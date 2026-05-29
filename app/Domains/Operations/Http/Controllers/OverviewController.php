<?php

namespace App\Domains\Operations\Http\Controllers;

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Spatie\Backup\BackupDestination\BackupDestination;

class OverviewController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Overview', [
            'metrics' => $this->collect(),
        ]);
    }

    public function metrics(): JsonResponse
    {
        return response()->json($this->collect());
    }

    private function collect(): array
    {
        $now = CarbonImmutable::now();
        // 29 + today = 30 buckets. subDays(30) plus an inclusive loop would
        // overshoot to 31.
        $thirtyDaysAgo = $now->subDays(29)->startOfDay();
        $sevenDaysAgo = $now->subDays(6)->startOfDay();

        return [
            'generated_at' => $now->toIso8601String(),
            'users' => $this->userMetrics($now, $sevenDaysAgo, $thirtyDaysAgo),
            'workspaces' => $this->workspaceMetrics(),
            'storage' => $this->storageMetrics(),
            'queue' => $this->queueMetrics(),
            'backups' => $this->backupMetrics(),
            'activity' => $this->activityMetrics($thirtyDaysAgo, $now),
            'recent_activity' => $this->recentActivity(),
        ];
    }

    private function userMetrics(CarbonImmutable $now, CarbonImmutable $sevenDaysAgo, CarbonImmutable $thirtyDaysAgo): array
    {
        $trend = User::query()
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('date(created_at) as d, count(*) as c')
            ->groupBy('d')
            ->orderBy('d')
            ->pluck('c', 'd');

        $central = (string) config('workspaces.database.central_connection', config('database.default'));

        return [
            'total' => User::count(),
            'unverified' => User::whereNull('email_verified_at')->count(),
            // Schema::hasColumn defaults to the active connection — pin it so
            // tenant-scoped requests don't mis-report 0 banned users when the
            // tenant schema legitimately lacks the column.
            'banned' => Schema::connection($central)->hasColumn('users', 'banned_at')
                ? User::whereNotNull('banned_at')->count()
                : 0,
            'new_last_7d' => User::where('created_at', '>=', $sevenDaysAgo)->count(),
            'trend_30d' => $this->fillDailySeries($trend, $thirtyDaysAgo, $now),
        ];
    }

    private function workspaceMetrics(): ?array
    {
        if (! config('workspaces.enabled')) {
            return null;
        }

        return [
            'total' => Workspace::count(),
            'active' => Workspace::where('status', 'active')->count(),
            'suspended' => Workspace::where('status', 'suspended')->count(),
        ];
    }

    private function storageMetrics(): array
    {
        // Quotas live in user_settings JSON (per-user), not on the users table —
        // summing them would require a JSON scan. Surface total usage only.
        return [
            'used_bytes' => (int) User::sum('storage_used_bytes'),
            'quota_bytes' => 0,
        ];
    }

    private function queueMetrics(): array
    {
        // Jobs / failed_jobs live on the central connection — pin the queries
        // there so tenant-scoped requests don't read the wrong schema and
        // report zero.
        $connection = (string) config('workspaces.database.central_connection', config('database.default'));
        $schema = Schema::connection($connection);
        $db = DB::connection($connection);

        return [
            'pending' => $schema->hasTable('jobs') ? $db->table('jobs')->count() : 0,
            'failed' => $schema->hasTable('failed_jobs') ? $db->table('failed_jobs')->count() : 0,
        ];
    }

    private function backupMetrics(): array
    {
        try {
            $disks = (array) config('backup.backup.destination.disks', []);
            if (empty($disks)) {
                return ['last_at' => null, 'last_size_bytes' => null, 'count' => 0];
            }
            $destination = BackupDestination::create($disks[0], (string) config('backup.backup.name'));
            $backups = $destination->backups();
            $newest = $backups->first();

            return [
                'disk' => $disks[0],
                'count' => $backups->count(),
                'last_at' => $newest?->date()->toIso8601String(),
                'last_size_bytes' => $newest ? (int) $newest->sizeInBytes() : null,
            ];
        } catch (\Throwable $e) {
            // Log once per failure — silent swallowing hid disk / auth issues
            // that should actually page an operator.
            Log::warning('Backup metrics probe failed.', ['exception' => $e->getMessage()]);

            return ['last_at' => null, 'last_size_bytes' => null, 'count' => 0];
        }
    }

    private function activityMetrics(CarbonImmutable $thirtyDaysAgo, CarbonImmutable $now): array
    {
        $trend = Activity::query()
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('date(created_at) as d, count(*) as c')
            ->groupBy('d')
            ->orderBy('d')
            ->pluck('c', 'd');

        return [
            'total' => Activity::count(),
            'trend_30d' => $this->fillDailySeries($trend, $thirtyDaysAgo, $now),
        ];
    }

    private function recentActivity(): array
    {
        return Activity::query()
            ->with('causer:id,email,first_name,last_name')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (Activity $a) => [
                'id' => $a->id,
                'description' => $a->description,
                'log_name' => $a->log_name,
                'event' => $a->event,
                'created_at' => $a->created_at?->toIso8601String(),
                'causer' => $a->causer ? [
                    'id' => $a->causer->getKey(),
                    'email' => $a->causer->email ?? null,
                    'first_name' => $a->causer->first_name ?? null,
                    'last_name' => $a->causer->last_name ?? null,
                ] : null,
            ])
            ->all();
    }

    /**
     * @param  Collection<string,int>  $data
     * @return array<int, array{date: string, count: int}>
     */
    private function fillDailySeries(Collection $data, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        $cursor = $from->startOfDay();
        while ($cursor->lessThanOrEqualTo($to)) {
            $key = $cursor->toDateString();
            $out[] = ['date' => $key, 'count' => (int) ($data[$key] ?? 0)];
            $cursor = $cursor->addDay();
        }

        return $out;
    }
}
