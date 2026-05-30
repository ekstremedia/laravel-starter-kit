<?php

declare(strict_types=1);

namespace App\Domains\Equipment\Dashboard;

use App\Domains\Equipment\Models\Equipment;
use App\Domains\EquipmentCategory\Models\EquipmentCategory;
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

        // Category names + colours to label the grouped counts (the donut).
        $names = EquipmentCategory::query()
            ->where('workspace_id', $workspace->id)
            ->get(['id', 'name', 'color'])
            ->keyBy('id');

        $byCategory = (clone $base)->toBase()
            ->selectRaw('equipment_category_id, count(*) as count')
            ->groupBy('equipment_category_id')
            ->orderByDesc('count')
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'label' => $r->equipment_category_id !== null && $names->has($r->equipment_category_id)
                    ? $names[$r->equipment_category_id]->name
                    : __('equipment.no_category'),
                'count' => (int) $r->count,
                'color' => $r->equipment_category_id !== null ? ($names[$r->equipment_category_id]->color ?? null) : null,
            ])
            ->all();

        $recent = (clone $base)->with('category:id,name,color')->latest()
            ->limit(5)
            ->get(['id', 'name', 'equipment_category_id'])
            ->map(fn (Equipment $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'category' => $e->category ? ['name' => $e->category->name, 'color' => $e->category->color] : null,
            ])
            ->all();

        return [
            'total' => (clone $base)->count(),
            'with_files' => (clone $base)->whereHas('files')->count(),
            'by_category' => $byCategory,
            'recent' => $recent,
        ];
    }
}
