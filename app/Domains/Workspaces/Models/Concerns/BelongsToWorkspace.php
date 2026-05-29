<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Models\Concerns;

use App\Domains\Workspaces\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Row-level workspace isolation for any model carrying a `workspace_id`.
 *
 * Adds a global scope that filters every query to the active workspace and
 * auto-stamps `workspace_id` on create — so a developer can never accidentally
 * leak rows across workspaces by forgetting a `where('workspace_id', …)`.
 *
 * The scope is **active only when a workspace context is set** (i.e. inside a
 * `/w/{workspace}/…` route, where ResolveWorkspace populated the
 * WorkspaceContext resolver). On central/admin routes — where there is no current
 * workspace — it is inert, so platform admins keep querying across all
 * workspaces exactly as before. Bypass explicitly with
 * `Model::withoutGlobalScope('workspace')` for the rare cross-workspace path.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $query): void {
            $tenancy = app(WorkspaceContext::class);

            if ($tenancy->check()) {
                // Qualify the column so the scope is join-safe.
                $query->where($query->getModel()->getTable().'.workspace_id', $tenancy->id());
            }
        });

        static::creating(function (Model $model): void {
            $tenancy = app(WorkspaceContext::class);

            if ($model->getAttribute('workspace_id') === null && $tenancy->check()) {
                $model->setAttribute('workspace_id', $tenancy->id());
            }
        });
    }
}
