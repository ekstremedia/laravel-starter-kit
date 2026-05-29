<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Support;

use App\Domains\Tenancy\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;

/**
 * Current-workspace (tenant) context for the request or job.
 *
 * Replaces stancl/tenancy's global `tenancy()` helper. Isolation is now
 * row-level — a `workspace_id` column plus a global Eloquent scope (see
 * BelongsToTenant) — rather than a Postgres schema swap, so all this needs to
 * do is remember which workspace the request is acting in and keep Spatie's
 * permission "team id" pointed at it, so per-workspace role checks resolve
 * against the active workspace.
 *
 * Registered as a singleton in AppServiceProvider. The resolving middleware
 * (InitializeTenancyByPath) sets it for the request and restores the prior
 * context afterwards via runFor(), so a long-lived worker can't leak one
 * workspace's context into the next request/job.
 */
class Tenancy
{
    private ?Tenant $current = null;

    /**
     * Make $tenant the active workspace (null clears it) and point Spatie's
     * team scope at it so `$user->can(...)` resolves per-workspace roles.
     */
    public function set(?Tenant $tenant): void
    {
        $this->current = $tenant;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant?->getKey());
    }

    public function current(): ?Tenant
    {
        return $this->current;
    }

    public function id(): ?int
    {
        return $this->current?->getKey();
    }

    /** Whether a workspace context is currently active. */
    public function check(): bool
    {
        return $this->current !== null;
    }

    public function forget(): void
    {
        $this->set(null);
    }

    /**
     * Run $callback with $tenant active, then restore the previous context.
     * Used by the resolving middleware and by any code that needs to act in a
     * specific workspace (e.g. a super admin operating cross-workspace).
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function runFor(Tenant $tenant, callable $callback)
    {
        $previous = $this->current;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }
}
