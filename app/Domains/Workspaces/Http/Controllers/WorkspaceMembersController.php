<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Http\Controllers;

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Models\WorkspaceInvitation;
use App\Domains\Workspaces\Support\WorkspaceMembership;
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
 * `ResolveWorkspace` (which has already set the permission team id and
 * exposed the customer on the request).
 */
class WorkspaceMembersController extends Controller
{
    public function index(Request $request): Response
    {
        $customer = $this->customer($request);

        // Eager-load team-scoped roles with one JOIN instead of the N+1 storm
        // of per-user `WorkspaceMembership::rolesOn` calls. The `->where` on
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

        // Pending invitations (auto-scoped to this workspace by BelongsToWorkspace).
        $invitations = WorkspaceInvitation::query()
            ->whereNull('accepted_at')
            ->orderByDesc('id')
            ->get(['id', 'email', 'role', 'expires_at'])
            ->map(fn (WorkspaceInvitation $i) => [
                'id' => $i->id,
                'email' => $i->email,
                'role' => $i->role,
                'expired' => $i->isExpired(),
                'expires_at' => $i->expires_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Customer/Members/Index', [
            'members' => $members,
            'invitations' => $invitations,
            'assignable_roles' => WorkspaceMembership::assignableRoles(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            // `exists:users,email` runs through the default DB connection,
            // which is the *tenant* schema after ResolveWorkspace has
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
            'roles.*' => ['string', Rule::in(WorkspaceMembership::assignableRoles())],
        ]);

        $user = User::query()->where('email', $data['email'])->firstOrFail();

        // `WorkspaceMembership::attach()` calls `syncRoles()`, which overwrites
        // the member's existing role set. Inviting someone who is already in
        // the customer would silently downgrade their roles — reject that
        // here and direct the admin to the per-row role editor instead.
        if ($user->belongsToCustomer($customer)) {
            return back()->with('error', __('flash.customers.already_member', [
                'email' => $user->email,
                'name' => $customer->name,
            ]));
        }

        WorkspaceMembership::attach($user, $customer, $data['roles']);

        return back()->with('success', __('flash.customers.member_added', ['email' => $user->email, 'name' => $customer->name]));
    }

    public function setRole(Request $request, User $user): RedirectResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::in(WorkspaceMembership::assignableRoles())],
        ]);

        if (! $user->belongsToCustomer($customer)) {
            return back()->with('error', __('flash.customers.not_member', ['email' => $user->email, 'name' => $customer->name]));
        }

        $newRoles = array_values(array_unique($data['roles']));
        $wasAdmin = in_array('Admin', WorkspaceMembership::rolesOn($user, $customer), true);
        $willBeAdmin = in_array('Admin', $newRoles, true);

        // Per-customer last-admin guard: don't let the only Admin on this
        // customer lose the role, whether by self-demote or external change.
        if ($wasAdmin && ! $willBeAdmin && $this->otherAdmins($customer, $user) === 0) {
            return back()->with('error', __('flash.customers.last_admin'));
        }

        WorkspaceMembership::syncRoles($user, $customer, $newRoles);

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

        if (in_array('Admin', WorkspaceMembership::rolesOn($user, $customer), true)
            && $this->otherAdmins($customer, $user) === 0
        ) {
            return back()->with('error', __('flash.customers.last_admin'));
        }

        WorkspaceMembership::detach($user, $customer);

        return back()->with('success', __('flash.customers.member_removed', ['email' => $user->email, 'name' => $customer->name]));
    }

    /**
     * Count Admins on `$customer` excluding `$excluding` — used by the
     * last-admin guard on role-change and remove.
     */
    private function otherAdmins(Workspace $customer, User $excluding): int
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

    private function customer(Request $request): Workspace
    {
        $customer = $request->attributes->get('customer');

        // ResolveWorkspace always populates this attribute before the
        // route dispatches. If we land here with it missing, the routing is
        // misconfigured (someone mounted the controller outside the
        // `/c/{customer}` group) — bail loudly so the fault is visible.
        if (! $customer instanceof Workspace) {
            throw new \LogicException(
                'WorkspaceMembersController requires the customer request attribute set by ResolveWorkspace.',
            );
        }

        // Defensive: the team id should already be set by ResolveWorkspace,
        // but re-asserting keeps this controller safe if it's ever called from
        // a flow that bypasses that middleware (e.g. a console runner).
        app(PermissionRegistrar::class)->setPermissionsTeamId($customer->id);

        return $customer;
    }
}
