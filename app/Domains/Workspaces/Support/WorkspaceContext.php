<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Support;

use App\Domains\Workspaces\Models\Workspace;
use Spatie\Permission\PermissionRegistrar;

/**
 * Current-workspace context for the request or job.
 *
 * Isolation is row-level — a `workspace_id` column plus a global Eloquent
 * scope (see BelongsToWorkspace). All this needs to do is remember which
 * workspace the request is acting in and keep Spatie's permission "team id"
 * pointed at it, so per-workspace role checks resolve against the active
 * workspace.
 *
 * Registered as a singleton in AppServiceProvider. The resolving middleware
 * (ResolveWorkspace) sets it for the request and restores the prior
 * context afterwards via runFor(), so a long-lived worker can't leak one
 * workspace's context into the next request/job.
 */
class WorkspaceContext
{
    private ?Workspace $current = null;

    /**
     * Make $workspace the active workspace (null clears it) and point Spatie's
     * team scope at it so `$user->can(...)` resolves per-workspace roles.
     */
    public function set(?Workspace $workspace): void
    {
        $this->current = $workspace;
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace?->getKey());
    }

    public function current(): ?Workspace
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
     * Run $callback with $workspace active, then restore the previous context.
     * Used by the resolving middleware and by any code that needs to act in a
     * specific workspace (e.g. a super admin operating cross-workspace).
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function runFor(Workspace $workspace, callable $callback)
    {
        $previous = $this->current;
        $this->set($workspace);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }
}
