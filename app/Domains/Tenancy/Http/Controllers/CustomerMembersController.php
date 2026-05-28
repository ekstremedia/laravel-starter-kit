<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Http\Controllers;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Support\CustomerMembership;
use App\Domains\Users\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

/**
 * Customer-scoped user management. A customer-Admin (i.e. someone who holds
 * the `Admin` role for the active customer) manages their own workspace's
 * members here — invite existing users, change their customer-level role,
 * remove them. Platform administration stays at `/admin/*` behind SuperAdmin.
 *
 * All lookups and mutations are implicitly scoped to the active customer via
 * `InitializeTenancyByPath` (which has already set the permission team id and
 * exposed the customer on the request).
 */
class CustomerMembersController extends Controller
{
    public function index(Request $request): Response
    {
        $customer = $this->customer($request);

        // Eager-load team-scoped roles with one JOIN instead of the N+1 storm
        // of per-user `CustomerMembership::rolesOn` calls. The `->where` on
        // `model_has_roles.team_id` scopes the pivot to this customer so we
        // never accidentally pick up the same user's roles on another customer.
        $teamKey = config('permission.column_names.team_foreign_key');
        $mhrTable = config('permission.table_names.model_has_roles');

        $members = $customer->users()
            ->with(['roles' => fn ($q) => $q->where("{$mhrTable}.{$teamKey}", $customer->id)])
            ->orderBy('users.email')
            ->get(['users.id', 'users.first_name', 'users.last_name', 'users.email'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'first_name' => $u->first_name,
                'last_name' => $u->last_name,
                'full_name' => $u->fullName(),
                'email' => $u->email,
                'roles' => $u->roles->pluck('name')->all(),
            ])
            ->values();

        return Inertia::render('Customer/Members/Index', [
            'members' => $members,
            'assignable_roles' => CustomerMembership::assignableRoles(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            // `exists:users,email` runs through the default DB connection,
            // which is the *tenant* schema after InitializeTenancyByPath has
            // booted tenancy. Users live on the central schema — resolve
            // against it explicitly via a closure to avoid "relation users
            // does not exist" when tenancy bootstrappers are on.
            'email' => [
                'required',
                'email',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! User::query()->where('email', (string) $value)->exists()) {
                        $fail(__('validation.exists', ['attribute' => $attribute]));
                    }
                },
            ],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(CustomerMembership::assignableRoles())],
        ]);

        $user = User::query()->where('email', $data['email'])->firstOrFail();

        // `CustomerMembership::attach()` calls `syncRoles()`, which overwrites
        // the member's existing role set. Inviting someone who is already in
        // the customer would silently downgrade their roles — reject that
        // here and direct the admin to the per-row role editor instead.
        if ($user->belongsToCustomer($customer)) {
            return back()->with('error', __('flash.customers.already_member', [
                'email' => $user->email,
                'name' => $customer->name,
            ]));
        }

        CustomerMembership::attach($user, $customer, $data['roles']);

        return back()->with('success', __('flash.customers.member_added', ['email' => $user->email, 'name' => $customer->name]));
    }

    public function setRole(Request $request, User $user): RedirectResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::in(CustomerMembership::assignableRoles())],
        ]);

        if (! $user->belongsToCustomer($customer)) {
            return back()->with('error', __('flash.customers.not_member', ['email' => $user->email, 'name' => $customer->name]));
        }

        $newRoles = array_values(array_unique($data['roles']));
        $wasAdmin = in_array('Admin', CustomerMembership::rolesOn($user, $customer), true);
        $willBeAdmin = in_array('Admin', $newRoles, true);

        // Per-customer last-admin guard: don't let the only Admin on this
        // customer lose the role, whether by self-demote or external change.
        if ($wasAdmin && ! $willBeAdmin && $this->otherAdmins($customer, $user) === 0) {
            return back()->with('error', __('flash.customers.last_admin'));
        }

        CustomerMembership::syncRoles($user, $customer, $newRoles);

        return back()->with('success', __('flash.customers.member_role_updated', [
            'email' => $user->email,
            'role' => empty($newRoles) ? __('admin.users.no_roles') : implode(', ', $newRoles),
        ]));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $customer = $this->customer($request);

        if (! $user->belongsToCustomer($customer)) {
            return back()->with('error', __('flash.customers.not_member', ['email' => $user->email, 'name' => $customer->name]));
        }

        if (in_array('Admin', CustomerMembership::rolesOn($user, $customer), true)
            && $this->otherAdmins($customer, $user) === 0
        ) {
            return back()->with('error', __('flash.customers.last_admin'));
        }

        CustomerMembership::detach($user, $customer);

        return back()->with('success', __('flash.customers.member_removed', ['email' => $user->email, 'name' => $customer->name]));
    }

    /**
     * Count Admins on `$customer` excluding `$excluding` — used by the
     * last-admin guard on role-change and remove.
     */
    private function otherAdmins(Tenant $customer, User $excluding): int
    {
        // Collapse what used to be "pull every member, call rolesOn() per
        // user" (one N+1 query storm per membership action) into a single
        // JOIN that counts Admin role rows on this customer's team scope.
        $teamKey = config('permission.column_names.team_foreign_key');

        return $customer->users()
            ->where('users.id', '!=', $excluding->id)
            ->whereHas('roles', fn ($q) => $q
                ->where('name', 'Admin')
                ->where(config('permission.table_names.model_has_roles').'.'.$teamKey, $customer->id))
            ->count();
    }

    private function customer(Request $request): Tenant
    {
        $customer = $request->attributes->get('customer');

        // InitializeTenancyByPath always populates this attribute before the
        // route dispatches. If we land here with it missing, the routing is
        // misconfigured (someone mounted the controller outside the
        // `/c/{customer}` group) — bail loudly so the fault is visible.
        if (! $customer instanceof Tenant) {
            throw new \LogicException(
                'CustomerMembersController requires the customer request attribute set by InitializeTenancyByPath.',
            );
        }

        // Defensive: the team id should already be set by InitializeTenancyByPath,
        // but re-asserting keeps this controller safe if it's ever called from
        // a flow that bypasses that middleware (e.g. a console runner).
        app(PermissionRegistrar::class)->setPermissionsTeamId($customer->id);

        return $customer;
    }
}
