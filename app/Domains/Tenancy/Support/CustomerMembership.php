<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Support;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Helpers for managing a user's membership in a customer alongside their
 * customer-scoped role assignments.
 *
 * Membership has two moving parts that need to stay in sync:
 *   - `tenant_user` pivot row   (who can enter the customer)
 *   - one or more `model_has_roles` rows with `team_id = customer.id`
 *     (what they can do inside — permissions from all assigned roles are
 *     unioned automatically by Spatie's `can()` check)
 *
 * Touching only one side leaves users with access but no permissions, or the
 * reverse. Every attach/detach should go through this class so the two sides
 * move together.
 */
class CustomerMembership
{
    /**
     * Roles an admin may assign to a customer member. Intentionally excludes
     * SuperAdmin — that is a platform flag on the user row, not a customer
     * role (see `User::isSuperAdmin()`).
     *
     * @return array<int, string>
     */
    public static function assignableRoles(): array
    {
        return ['Admin', 'Editor', 'User'];
    }

    /**
     * Attach the user to the customer and sync the given customer-scoped
     * roles. Accepts a single role name for convenience or an array for
     * users that hold multiple roles (Editor + Admin, etc. — their
     * permissions union automatically). Safe to call on existing members.
     *
     * @param  string|array<int, string>  $roles
     */
    public static function attach(User $user, Tenant $customer, string|array $roles): void
    {
        // Run both touches in a single transaction on the central connection.
        // The class docblock promises membership pivot + role assignments
        // "move together"; without the transaction a failure after the pivot
        // insert leaves the user a member with no role (or vice versa).
        DB::connection(static::centralConnection())->transaction(function () use ($user, $customer, $roles): void {
            $user->customers()->syncWithoutDetaching([$customer->id]);
            static::syncRoles($user, $customer, (array) $roles);
        });
    }

    /**
     * Detach the user from the customer and remove every role they held there.
     * Running under setPermissionsTeamId so Spatie scopes `removeRole` to
     * that customer's assignments only.
     */
    public static function detach(User $user, Tenant $customer): void
    {
        // Same atomicity concern as `attach()` — run the role removals and
        // the pivot detach in one transaction so a crash mid-way can't leave
        // the user with roles on a customer they're no longer a member of.
        DB::connection(static::centralConnection())->transaction(function () use ($user, $customer): void {
            $registrar = app(PermissionRegistrar::class);
            $previous = $registrar->getPermissionsTeamId();

            try {
                $registrar->setPermissionsTeamId($customer->id);
                foreach ($user->roles()->pluck('name') as $roleName) {
                    $user->removeRole((string) $roleName);
                }
            } finally {
                $registrar->setPermissionsTeamId($previous);
            }

            $user->customers()->detach($customer->id);
        });
    }

    /**
     * Replace the user's customer-scoped roles with exactly the given list.
     * Permissions from the combined set are automatically unioned by Spatie.
     *
     * @param  array<int, string>  $roles
     */
    public static function syncRoles(User $user, Tenant $customer, array $roles): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($customer->id);
            $user->syncRoles(array_values(array_unique($roles)));
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    /**
     * Return all role names the user holds on this customer (empty array if
     * none). Independent of any ambient team context.
     *
     * @return array<int, string>
     */
    public static function rolesOn(User $user, Tenant $customer): array
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($customer->id);

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
    public static function roleOn(User $user, Tenant $customer): ?string
    {
        return static::rolesOn($user, $customer)[0] ?? null;
    }

    protected static function centralConnection(): string
    {
        return (string) config('tenancy.database.central_connection');
    }
}
