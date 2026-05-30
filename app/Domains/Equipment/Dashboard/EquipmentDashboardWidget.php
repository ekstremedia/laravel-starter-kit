<?php

declare(strict_types=1);

namespace App\Domains\Equipment\Dashboard;

use App\Domains\Equipment\Models\Equipment;
use App\Domains\Modules\Contracts\DashboardWidget;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;

/**
 * The Equipment module's dashboard widget: totals, a category breakdown (donut),
 * and the most recently added items for the current workspace.
 */
class EquipmentDashboardWidget implements DashboardWidget
{
    public function key(): string
    {
        return 'equipment';
    }

    public function moduleKey(): ?string
    {
        return 'equipment';
    }

    public function titleKey(): string
    {
        return 'equipment.title';
    }

    public function icon(): string
    {
        return 'box';
    }

    public function component(): string
    {
        return 'EquipmentWidget';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Workspace $workspace, User $user): array
    {
        $base = Equipment::query()->where('workspace_id', $workspace->id);

        $byCategory = (clone $base)->toBase()
            ->selectRaw('coalesce(category, ?) as label, count(*) as count', [__('equipment.no_category')])
            ->groupBy('label')
            ->orderByDesc('count')
            ->limit(6)
            ->get()
            ->map(fn ($r) => ['label' => (string) $r->label, 'count' => (int) $r->count])
            ->all();

        $recent = (clone $base)->latest()
            ->limit(5)
            ->get(['id', 'name', 'category'])
            ->map(fn (Equipment $e) => ['id' => $e->id, 'name' => $e->name, 'category' => $e->category])
            ->all();

        return [
            'total' => (clone $base)->count(),
            'with_files' => (clone $base)->whereHas('files')->count(),
            'by_category' => $byCategory,
            'recent' => $recent,
        ];
    }
}
