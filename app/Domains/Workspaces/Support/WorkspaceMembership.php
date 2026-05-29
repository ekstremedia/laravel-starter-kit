<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Support;

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Helpers for managing a user's membership in a workspace alongside their
 * workspace-scoped role assignments.
 *
 * Membership has two moving parts that need to stay in sync:
 *   - `workspace_user` pivot row   (who can enter the workspace)
 *   - one or more `model_has_roles` rows with `team_id = workspace.id`
 *     (what they can do inside — permissions from all assigned roles are
 *     unioned automatically by Spatie's `can()` check)
 *
 * Touching only one side leaves users with access but no permissions, or the
 * reverse. Every attach/detach should go through this class so the two sides
 * move together.
 */
class WorkspaceMembership
{
    /**
     * Roles an admin may assign to a workspace member. Intentionally excludes
     * SuperAdmin — that is a platform flag on the user row, not a workspace
     * role (see `User::isSuperAdmin()`).
     *
     * @return array<int, string>
     */
    public static function assignableRoles(): array
    {
        return ['Admin', 'Editor', 'User'];
    }

    /**
     * Attach the user to the workspace and sync the given workspace-scoped
     * roles. Accepts a single role name for convenience or an array for
     * users that hold multiple roles (Editor + Admin, etc. — their
     * permissions union automatically). Safe to call on existing members.
     *
     * @param  string|array<int, string>  $roles
     */
    public static function attach(User $user, Workspace $workspace, string|array $roles): void
    {
        // Run both touches in a single transaction on the central connection.
        // The class docblock promises membership pivot + role assignments
        // "move together"; without the transaction a failure after the pivot
        // insert leaves the user a member with no role (or vice versa).
        DB::connection(static::centralConnection())->transaction(function () use ($user, $workspace, $roles): void {
            $user->workspaces()->syncWithoutDetaching([$workspace->id]);
            static::syncRoles($user, $workspace, (array) $roles);
        });
    }

    /**
     * Detach the user from the workspace and remove every role they held there.
     * Running under setPermissionsTeamId so Spatie scopes `removeRole` to
     * that workspace's assignments only.
     */
    public static function detach(User $user, Workspace $workspace): void
    {
        // Same atomicity concern as `attach()` — run the role removals and
        // the pivot detach in one transaction so a crash mid-way can't leave
        // the user with roles on a workspace they're no longer a member of.
        DB::connection(static::centralConnection())->transaction(function () use ($user, $workspace): void {
            $registrar = app(PermissionRegistrar::class);
            $previous = $registrar->getPermissionsTeamId();

            try {
                $registrar->setPermissionsTeamId($workspace->id);
                foreach ($user->roles()->pluck('name') as $roleName) {
                    $user->removeRole((string) $roleName);
                }
            } finally {
                $registrar->setPermissionsTeamId($previous);
            }

            $user->workspaces()->detach($workspace->id);
        });
    }

    /**
     * Replace the user's workspace-scoped roles with exactly the given list.
     * Permissions from the combined set are automatically unioned by Spatie.
     *
     * @param  array<int, string>  $roles
     */
    public static function syncRoles(User $user, Workspace $workspace, array $roles): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($workspace->id);
            $user->syncRoles(array_values(array_unique($roles)));
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    /**
     * Return all role names the user holds on this workspace (empty array if
     * none). Independent of any ambient team context.
     *
     * @return array<int, string>
     */
    public static function rolesOn(User $user, Workspace $workspace): array
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($workspace->id);

            return $user->roles()->pluck('name')->all();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    /**
     * Convenience wrapper used by callers that only care about the primary
     * role — returns the first role name or null. Prefer `rolesOn()` for
     * anything that should reflect the full set.
     */
    public static function roleOn(User $user, Workspace $workspace): ?string
    {
        return static::rolesOn($user, $workspace)[0] ?? null;
    }

    protected static function centralConnection(): string
    {
        return (string) config('workspaces.database.central_connection');
    }
}
