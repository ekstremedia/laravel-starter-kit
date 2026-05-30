<?php

declare(strict_types=1);

namespace App\Domains\Modules\Services;

use App\Domains\Modules\Contracts\DashboardWidget;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;

/**
 * Resolves the dashboard widgets registered in config/dashboard.php, filtered
 * to those whose owning module is enabled, and builds the per-user payload
 * (honouring each user's hidden-widget preference). Data is only computed for
 * widgets the user has enabled.
 */
class DashboardWidgetRegistry
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * Widgets available to this workspace (their module is enabled).
     *
     * @return array<int, DashboardWidget>
     */
    public function available(): array
    {
        return collect(config('dashboard.widgets', []))
            ->map(fn (string $class): DashboardWidget => app($class))
            ->filter(fn (DashboardWidget $w): bool => $w->moduleKey() === null || $this->modules->isEnabled($w->moduleKey()))
            ->values()
            ->all();
    }

    /**
     * The dashboard payload for a user: every available widget with its meta,
     * an `enabled` flag from the user's prefs, and `data` only for enabled ones.
     *
     * @param  array<int, string>  $hidden  widget keys the user has hidden
     * @return array<int, array<string, mixed>>
     */
    public function forUser(Workspace $workspace, User $user, array $hidden): array
    {
        return collect($this->available())
            ->map(function (DashboardWidget $widget) use ($workspace, $user, $hidden): array {
                $enabled = ! in_array($widget->key(), $hidden, true);

                return [
                    'key' => $widget->key(),
                    'title_key' => $widget->titleKey(),
                    'icon' => $widget->icon(),
                    'component' => $widget->component(),
                    'enabled' => $enabled,
                    'data' => $enabled ? $widget->data($workspace, $user) : null,
                ];
            })
            ->all();
    }
}
