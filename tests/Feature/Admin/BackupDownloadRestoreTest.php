<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();

    // Spatie's BackupDestination writes into the real configured disk; swap it
    // for an in-memory fake so we can drop a placeholder zip and drive the UI
    // endpoints without creating real archives.
    Storage::fake('local');
    config(['backup.backup.destination.disks' => ['local']]);
    config(['backup.backup.name' => 'starter']);

    // Extraction lands in the real storage path (not the faked disk). Scope the
    // staging root to this test so parallel processes don't collide.
    $this->restoreRoot = storage_path('app/backup-restores-'.bin2hex(random_bytes(4)));
    config(['backup.testing.restore_root' => $this->restoreRoot]);
});

afterEach(function () {
    if (isset($this->restoreRoot) && is_dir($this->restoreRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->restoreRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($this->restoreRoot);
    }
});

/**
 * Write a valid zip archive to the fake backup disk so Spatie's
 * BackupDestination treats it as a known backup.
 *
 * @param  array<string,string>  $entries  entry name → contents
 */
function seedBackup(string $relativePath, array $entries = ['db-dumps/main.sql' => '-- dump --']): string
{
    $disk = Storage::disk('local');
    $fullPath = (string) $disk->path($relativePath);
    if (! is_dir(dirname($fullPath))) {
        mkdir(dirname($fullPath), 0777, true);
    }

    $zip = new ZipArchive;
    $zip->open($fullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();

    return $relativePath;
}

it('rejects downloads for disks not in the backup config', function () {
    $this->actingAs($this->admin)
        ->get('/admin/backups/download?disk=public&path=fake.zip')
        ->assertNotFound();
});

it('rejects downloads for files that are not recognised spatie backups', function () {
    Storage::disk('local')->put('starter/random.txt', 'not-a-backup');

    $this->actingAs($this->admin)
        ->get('/admin/backups/download?disk=local&path=starter%2Frandom.txt')
        ->assertNotFound();
});

it('streams a known backup on download', function () {
    $path = seedBackup('starter/2026-04-21-noon.zip');

    $response = $this->actingAs($this->admin)
        ->get('/admin/backups/download?disk=local&path='.rawurlencode($path));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))
        ->toContain('2026-04-21-noon.zip');
});

it('refuses to prepare a restore when the filename confirmation does not match', function () {
    $path = seedBackup('starter/real.zip');

    $this->actingAs($this->admin)
        ->post('/admin/backups/prepare-restore', [
            'disk' => 'local',
            'path' => $path,
            'confirm' => 'wrong.zip',
        ])
        ->assertStatus(422);
});

it('extracts a well-formed backup into the staging directory', function () {
    $path = seedBackup('starter/2026-04-21.zip', [
        'db-dumps/main.sql' => '-- dump --',
        'storage/app/sample.txt' => 'hello',
    ]);

    $this->actingAs($this->admin)
        ->post('/admin/backups/prepare-restore', [
            'disk' => 'local',
            'path' => $path,
            'confirm' => '2026-04-21.zip',
        ])
        ->assertRedirect();

    $dirs = is_dir($this->restoreRoot) ? glob($this->restoreRoot.'/*', GLOB_ONLYDIR) : [];
    $staging = $dirs[0] ?? null;

    expect($staging)->not->toBeNull();
    expect(is_file($staging.'/db-dumps/main.sql'))->toBeTrue();
    expect(file_get_contents($staging.'/storage/app/sample.txt'))->toBe('hello');
});

it('refuses backups whose entries try to escape the staging dir', function () {
    $path = seedBackup('starter/evil.zip', [
        // Classic Zip-Slip payload.
        '../evil.txt' => 'pwned',
        'db-dumps/main.sql' => '-- dump --',
    ]);

    $this->actingAs($this->admin)
        ->post('/admin/backups/prepare-restore', [
            'disk' => 'local',
            'path' => $path,
            'confirm' => 'evil.zip',
        ])
        ->assertStatus(422);
});

it('refuses backups whose entries use absolute paths', function () {
    $path = seedBackup('starter/absolute.zip', [
        '/etc/passwd' => 'root:x:0:0:root',
    ]);

    $this->actingAs($this->admin)
        ->post('/admin/backups/prepare-restore', [
            'disk' => 'local',
            'path' => $path,
            'confirm' => 'absolute.zip',
        ])
        ->assertStatus(422);
});

it('forbids non-admins from download and restore endpoints', function () {
    $path = seedBackup('starter/real.zip');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/backups/download?disk=local&path='.rawurlencode($path))
        ->assertForbidden();

    $this->actingAs($user)
        ->post('/admin/backups/prepare-restore', [
            'disk' => 'local', 'path' => $path, 'confirm' => 'real.zip',
        ])
        ->assertForbidden();
});
