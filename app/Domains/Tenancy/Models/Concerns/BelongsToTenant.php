<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models\Concerns;

use App\Domains\Tenancy\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Row-level workspace isolation for any model carrying a `tenant_id`.
 *
 * Adds a global scope that filters every query to the active workspace and
 * auto-stamps `tenant_id` on create — so a developer can never accidentally
 * leak rows across workspaces by forgetting a `where('tenant_id', …)`.
 *
 * The scope is **active only when a workspace context is set** (i.e. inside a
 * `/c/{workspace}/…` route, where InitializeTenancyByPath populated the
 * Tenancy resolver). On central/admin routes — where there is no current
 * workspace — it is inert, so platform admins keep querying across all
 * workspaces exactly as before. Bypass explicitly with
 * `Model::withoutGlobalScope('tenant')` for the rare cross-workspace path.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $tenancy = app(Tenancy::class);

            if ($tenancy->check()) {
                // Qualify the column so the scope is join-safe.
                $query->where($query->getModel()->getTable().'.tenant_id', $tenancy->id());
            }
        });

        static::creating(function (Model $model): void {
            $tenancy = app(Tenancy::class);

            if ($model->getAttribute('tenant_id') === null && $tenancy->check()) {
                $model->setAttribute('tenant_id', $tenancy->id());
            }
        });
    }
}
