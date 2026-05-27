<?php

namespace App\Domains\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function index(): Response
    {
        $disks = (array) config('backup.backup.destination.disks', []);
        $name = (string) config('backup.backup.name');

        $summaries = collect($disks)->map(function (string $disk) use ($name): array {
            try {
                $destination = BackupDestination::create($disk, $name);
                $backups = $destination->backups();

                return [
                    'disk' => $disk,
                    'count' => $backups->count(),
                    'used_bytes' => (int) $backups->sum(fn (Backup $b) => $b->sizeInBytes()),
                    'newest_at' => $backups->first()?->date()->toIso8601String(),
                ];
            } catch (\Throwable) {
                return [
                    'disk' => $disk,
                    'count' => 0,
                    'used_bytes' => 0,
                    'newest_at' => null,
                    'error' => true,
                ];
            }
        })->values();

        $backups = collect($disks)
            ->flatMap(function (string $disk) use ($name): array {
                try {
                    $destination = BackupDestination::create($disk, $name);

                    return $destination->backups()->map(fn (Backup $backup) => [
                        'disk' => $disk,
                        'path' => $backup->path(),
                        'size' => (int) $backup->sizeInBytes(),
                        'date' => $backup->date()->toIso8601String(),
                    ])->all();
                } catch (\Throwable) {
                    return [];
                }
            })
            ->sortByDesc('date')
            ->values();

        return Inertia::render('Admin/Backups', [
            'backups' => $backups,
            'disks' => $disks,
            'name' => $name,
            'summaries' => $summaries,
            'config' => [
                'includes' => (array) config('backup.backup.source.files.include', []),
                'excludes' => (array) config('backup.backup.source.files.exclude', []),
                'databases' => (array) config('backup.backup.source.databases', []),
                'retention_daily' => (int) config('backup.cleanup.default_strategy.keep_all_backups_for_days', 7),
                'retention_daily_keep' => (int) config('backup.cleanup.default_strategy.keep_daily_backups_for_days', 16),
                'retention_weekly_keep' => (int) config('backup.cleanup.default_strategy.keep_weekly_backups_for_weeks', 8),
                'retention_monthly_keep' => (int) config('backup.cleanup.default_strategy.keep_monthly_backups_for_months', 4),
                'retention_yearly_keep' => (int) config('backup.cleanup.default_strategy.keep_yearly_backups_for_years', 2),
                'max_storage_mb' => (int) config('backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than', 5000),
            ],
        ]);
    }

    public function run(): RedirectResponse
    {
        Artisan::queue('backup:run', ['--only-db' => false]);

        activity('backup')->event('run')->log('Manual backup queued');

        return back()->with('success', __('flash.backups.queued'));
    }

    public function clean(): RedirectResponse
    {
        Artisan::queue('backup:clean');

        activity('backup')->event('clean')->log('Manual backup cleanup queued');

        return back()->with('success', __('flash.backups.cleanup_queued'));
    }

    public function download(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'disk' => ['required', 'string'],
            'path' => ['required', 'string'],
        ]);

        $disks = (array) config('backup.backup.destination.disks', []);
        abort_unless(in_array($data['disk'], $disks, true), 404);
        abort_unless($this->isKnownBackup($data['disk'], $data['path']), 404);

        $storage = Storage::disk($data['disk']);

        // Logged as "requested" rather than "downloaded" — the stream may
        // still fail mid-flight (missing file, corrupted zip, client hangup),
        // and the audit trail should reflect intent, not a false success.
        activity('backup')->event('download_requested')->withProperties($data)->log('Backup download requested');

        $filename = basename($data['path']);

        return $storage->download($data['path'], $filename);
    }

    public function prepareRestore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'disk' => ['required', 'string'],
            'path' => ['required', 'string'],
            'confirm' => ['required', 'string'],
        ]);

        $disks = (array) config('backup.backup.destination.disks', []);
        abort_unless(in_array($data['disk'], $disks, true), 404);
        abort_unless(basename($data['path']) === $data['confirm'], 422, 'Filename confirmation does not match.');
        abort_unless($this->isKnownBackup($data['disk'], $data['path']), 404);

        $storage = Storage::disk($data['disk']);

        $stagingRoot = (string) config('backup.testing.restore_root', storage_path('app/backup-restores'));
        $stagingDir = $stagingRoot.'/'.now()->format('Ymd_His').'_'.Str::random(6);

        // 0700: the extracted tree contains the raw DB dump (password hashes,
        // 2FA secrets, Sanctum hashes). Locking it to the web user keeps it
        // unreadable by other accounts on a shared host.
        if (! is_dir($stagingDir) && ! mkdir($stagingDir, 0o700, true) && ! is_dir($stagingDir)) {
            abort(500, 'Unable to create staging directory.');
        }
        @chmod($stagingDir, 0o700);

        $tmpZip = $stagingDir.'/backup.zip';

        // Stream the archive to disk rather than loading it entirely into
        // memory — backups can easily exceed PHP's memory_limit.
        $source = $storage->readStream($data['path']);
        abort_unless(is_resource($source), 500, 'Unable to read backup archive.');

        $destination = fopen($tmpZip, 'wb');
        if (! is_resource($destination)) {
            fclose($source);
            abort(500, 'Unable to create temporary backup archive.');
        }

        try {
            $copied = stream_copy_to_stream($source, $destination);
            if ($copied === false) {
                abort(500, 'Failed to copy backup archive to staging.');
            }
        } finally {
            fclose($source);
            fclose($destination);
        }

        $zip = new \ZipArchive;
        $opened = $zip->open($tmpZip);
        if ($opened !== true) {
            abort(500, 'Unable to open backup archive (error '.$opened.').');
        }

        try {
            $this->assertSafeZipEntries($zip);

            if (! $zip->extractTo($stagingDir)) {
                abort(500, 'Unable to extract backup archive.');
            }
        } finally {
            $zip->close();
        }

        // Drop the intermediate zip — the extracted tree is the useful bit
        // and keeping both doubles the on-disk footprint per restore.
        @unlink($tmpZip);

        activity('backup')
            ->event('restore_prepared')
            ->withProperties(['path' => $data['path'], 'staging' => $stagingDir])
            ->log('Backup prepared for restore');

        return back()->with('success', __('flash.backups.restore_staged', ['path' => $stagingDir]));
    }

    /**
     * Confirm the requested path corresponds to a Spatie-managed backup on the
     * given disk, not an arbitrary file uploaded into the destination.
     */
    private function isKnownBackup(string $disk, string $path): bool
    {
        try {
            $destination = BackupDestination::create($disk, (string) config('backup.backup.name'));

            return $destination
                ->backups()
                ->contains(fn (Backup $backup): bool => $backup->path() === $path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Reject ZIP entries that would escape the staging directory — absolute
     * paths, Windows drive letters, or any parent-directory segment. Protects
     * against classic Zip-Slip path traversal on untrusted archives.
     */
    private function assertSafeZipEntries(\ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            abort_if($entry === false, 500, 'Unable to inspect backup archive.');

            $normalized = str_replace('\\', '/', (string) $entry);
            $segments = array_filter(explode('/', $normalized), fn (string $s): bool => $s !== '' && $s !== '.');

            $isAbsolute = str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1;
            $escapes = in_array('..', $segments, true);

            abort_if($isAbsolute || $escapes, 422, 'Backup archive contains an unsafe path.');
        }
    }
}
