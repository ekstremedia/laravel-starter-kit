<?php

use App\Domains\Notifications\Services\MjmlCompiler;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Support\CustomerMembership;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Multi-tenancy is row-level (a workspace_id column + the BelongsToTenant
        // global scope) — there are no per-tenant schemas/databases to create,
        // so creating a workspace is just a plain `workspaces`/`tenants` row in
        // the in-memory test DB. Nothing to strip here anymore.

        // MJML compilation shells out to `npx mjml` which takes ~600 ms per
        // template. 16 templates × every RefreshDatabase seed = painful.
        // Swap in a fake compiler for tests; real compilation is exercised
        // by the dedicated unit test that opts out of this binding.
        app()->bind(MjmlCompiler::class, fn () => new class extends MjmlCompiler
        {
            public function compile(string $mjml): string
            {
                return '<!doctype html><html><body>'.strip_tags($mjml).'</body></html>';
            }
        });
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Create a customer (`App\Domains\Tenancy\Models\Tenant` under the hood) for use in a test.
 * In multi-tenant integration tests against real Postgres the creation event
 * pipeline provisions the per-customer schema; in the Feature suite above we
 * strip those listeners so this just writes a `tenants` row.
 */
function createCustomer(string $slug = 'acme', ?string $name = null): Tenant
{
    return Tenant::create([
        'slug' => $slug,
        'name' => $name ?? ucfirst($slug),
        'status' => 'active',
    ]);
}

/**
 * Attach the user to a customer (creating one on the fly when not supplied)
 * and grant them a customer-scoped role. Defaults to `User` so most tests get
 * the standard file permissions; pass `null` to skip role assignment when a
 * bare membership is what the test needs.
 */
function joinCustomer(User $user, ?Tenant $customer = null, ?string $role = 'User'): Tenant
{
    $customer ??= Tenant::query()->where('slug', 'acme')->first() ?? createCustomer();

    if ($role === null) {
        $user->customers()->syncWithoutDetaching([$customer->id]);
    } else {
        grantRoleOnCustomer($user, $role, $customer);
    }

    return $customer;
}

/**
 * Build a customer-scoped URL, e.g. `customerUrl($c, '/dashboard')` →
 * `/c/acme/dashboard`. Path is joined as-is; omit to get the root `/c/acme`.
 */
function customerUrl(Tenant $customer, string $path = ''): string
{
    $path = $path === '' ? '' : '/'.ltrim($path, '/');

    return "/c/{$customer->slug}{$path}";
}

/**
 * Assign a customer-scoped role to a user on a specific customer. Joins the
 * customer first so the membership + role rows stay in sync. Resets the
 * PermissionRegistrar team id back to null so subsequent unscoped checks
 * (e.g. SuperAdmin) aren't accidentally constrained.
 */
function grantRoleOnCustomer(User $user, string $role, Tenant $customer): void
{
    CustomerMembership::attach($user, $customer, $role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
}

/**
 * Promote a user to platform SuperAdmin by setting the boolean column on the
 * users table. Independent of any customer context; see `User::isSuperAdmin()`.
 */
function makeSuperAdmin(User $user): User
{
    $user->forceFill(['is_super_admin' => true])->save();

    return $user->refresh();
}
