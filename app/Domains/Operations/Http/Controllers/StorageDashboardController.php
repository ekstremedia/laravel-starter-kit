<?php

declare(strict_types=1);

namespace App\Domains\Operations\Http\Controllers;

use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StorageDashboardController extends Controller
{
    public function __construct(private readonly StorageUsageService $usage) {}

    public function index(Request $request): Response
    {
        $totalBytes = $this->usage->systemTotalBytes();
        $byType = $this->usage->systemBreakdownByType();
        $byCollection = $this->usage->systemBreakdownByCollection();

        // Search inputs let admins find a user/workspace without scrolling
        // through a long top-N list. Empty strings = no filter.
        $userSearch = trim((string) $request->string('user_search')->toString());
        $workspaceSearch = trim((string) $request->string('workspace_search')->toString());

        $topUsers = $this->usage->topUsers(20, $userSearch ?: null);
        $byWorkspace = $this->usage->usageByTenant($workspaceSearch ?: null, 50);

        // Billable storage grouped by the polymorphic owner entity type. We
        // emit a stable `key` (personal/company/other) the Vue localizes, plus
        // a `label` fallback (the entity class basename) for custom owners.
        $personalType = (new User)->getMorphClass();
        $companyType = (new Workspace)->getMorphClass();
        $byEntityType = array_map(function (array $row) use ($personalType, $companyType): array {
            $row['key'] = match ($row['type']) {
                $personalType => 'personal',
                $companyType => 'company',
                default => 'other',
            };
            $row['label'] = class_basename(Relation::getMorphedModel($row['type']) ?? $row['type']);

            return $row;
        }, $this->usage->systemBreakdownByOwnerType());

        $diskTotal = (int) @disk_total_space(storage_path());
        $diskFree = (int) @disk_free_space(storage_path());

        return Inertia::render('Admin/Storage', [
            'totals' => [
                'bytes' => $totalBytes,
                'disk_total' => $diskTotal,
                'disk_free' => $diskFree,
                'file_count' => (int) DB::connection(config('workspaces.database.central_connection'))
                    ->table('media')->count(),
                'user_count' => User::query()->count(),
                'workspace_count' => Workspace::query()->count(),
            ],
            'by_type' => $byType,
            'by_collection' => $byCollection,
            'by_entity_type' => $byEntityType,
            'by_workspace' => $byWorkspace,
            'top_users' => $topUsers,
            'growth' => $this->growth(),
            'filters' => [
                'user_search' => $userSearch,
                'workspace_search' => $workspaceSearch,
            ],
        ]);
    }

    /**
     * Last 30 days of aggregate usage across all users.
     *
     * @return array<int, array{date: string, bytes: int}>
     */
    private function growth(): array
    {
        $conn = (string) config('workspaces.database.central_connection');

        return DB::connection($conn)
            ->table('storage_snapshots')
            ->whereNull('workspace_id')
            ->where('snapshot_date', '>=', now()->subDays(30)->toDateString())
            ->select('snapshot_date', DB::raw('SUM(bytes_used) as bytes'))
            ->groupBy('snapshot_date')
            ->orderBy('snapshot_date')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) Carbon::parse($r->snapshot_date)->toDateString(),
                'bytes' => (int) $r->bytes,
            ])
            ->all();
    }
}
