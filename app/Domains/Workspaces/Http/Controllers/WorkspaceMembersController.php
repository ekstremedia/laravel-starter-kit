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
 * Workspace-scoped user management. A workspace-Admin (i.e. someone who holds
 * the `Admin` role for the active workspace) manages their own workspace's
 * members here — invite existing users, change their workspace-level role,
 * remove them. Platform administration stays at `/admin/*` behind SuperAdmin.
 *
 * All lookups and mutations are implicitly scoped to the active workspace via
 * `ResolveWorkspace` (which has already set the permission team id and
 * exposed the workspace on the request).
 */
class WorkspaceMembersController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);

        // Eager-load team-scoped roles with one JOIN instead of the N+1 storm
        // of per-user `WorkspaceMembership::rolesOn` calls. The `->where` on
        // `model_has_roles.team_id` scopes the pivot to this workspace so we
        // never accidentally pick up the same user's roles on another workspace.
        $teamKey = config('permission.column_names.team_foreign_key');
        $mhrTable = config('permission.table_names.model_has_roles');

        $members = $workspace->users()
            ->with(['roles' => fn ($q) => $q->where("{$mhrTable}.{$teamKey}", $workspace->id)])
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

        return Inertia::render('Workspace/Members/Index', [
            'members' => $members,
            'invitations' => $invitations,
            'assignable_roles' => WorkspaceMembership::assignableRoles(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);

        $data = $request->validate([
            // Resolve the email against the User model explicitly via a
            // closure so the lookup goes through User (which pins its own
            // connection) rather than a bare `exists:users,email` rule.
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
        // the workspace would silently downgrade their roles — reject that
        // here and direct the admin to the per-row role editor instead.
        if ($user->belongsToWorkspace($workspace)) {
            return back()->with('error', __('flash.workspaces.already_member', [
                'email' => $user->email,
                'name' => $workspace->name,
            ]));
        }

        WorkspaceMembership::attach($user, $workspace, $data['roles']);

        return back()->with('success', __('flash.workspaces.member_added', ['email' => $user->email, 'name' => $workspace->name]));
    }

    public function setRole(Request $request, User $user): RedirectResponse
    {
        $workspace = $this->workspace($request);

        $data = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::in(WorkspaceMembership::assignableRoles())],
        ]);

        if (! $user->belongsToWorkspace($workspace)) {
            return back()->with('error', __('flash.workspaces.not_member', ['email' => $user->email, 'name' => $workspace->name]));
        }

        $newRoles = array_values(array_unique($data['roles']));
        $wasAdmin = in_array('Admin', WorkspaceMembership::rolesOn($user, $workspace), true);
        $willBeAdmin = in_array('Admin', $newRoles, true);

        // Per-workspace last-admin guard: don't let the only Admin on this
        // workspace lose the role, whether by self-demote or external change.
        if ($wasAdmin && ! $willBeAdmin && $this->otherAdmins($workspace, $user) === 0) {
            return back()->with('error', __('flash.workspaces.last_admin'));
        }

        WorkspaceMembership::syncRoles($user, $workspace, $newRoles);

        return back()->with('success', __('flash.workspaces.member_role_updated', [
            'email' => $user->email,
            'role' => empty($newRoles) ? __('admin.users.no_roles') : implode(', ', $newRoles),
        ]));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $workspace = $this->workspace($request);

        if (! $user->belongsToWorkspace($workspace)) {
            return back()->with('error', __('flash.workspaces.not_member', ['email' => $user->email, 'name' => $workspace->name]));
        }

        if (in_array('Admin', WorkspaceMembership::rolesOn($user, $workspace), true)
            && $this->otherAdmins($workspace, $user) === 0
        ) {
            return back()->with('error', __('flash.workspaces.last_admin'));
        }

        WorkspaceMembership::detach($user, $workspace);

        return back()->with('success', __('flash.workspaces.member_removed', ['email' => $user->email, 'name' => $workspace->name]));
    }

    /**
     * Count Admins on `$workspace` excluding `$excluding` — used by the
     * last-admin guard on role-change and remove.
     */
    private function otherAdmins(Workspace $workspace, User $excluding): int
    {
        // Collapse what used to be "pull every member, call rolesOn() per
        // user" (one N+1 query storm per membership action) into a single
        // JOIN that counts Admin role rows on this workspace's team scope.
        $teamKey = config('permission.column_names.team_foreign_key');

        return $workspace->users()
            ->where('users.id', '!=', $excluding->id)
            ->whereHas('roles', fn ($q) => $q
                ->where('name', 'Admin')
                ->where(config('permission.table_names.model_has_roles').'.'.$teamKey, $workspace->id))
            ->count();
    }

    private function workspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');

        // ResolveWorkspace always populates this attribute before the
        // route dispatches. If we land here with it missing, the routing is
        // misconfigured (someone mounted the controller outside the
        // `/w/{workspace}` group) — bail loudly so the fault is visible.
        if (! $workspace instanceof Workspace) {
            throw new \LogicException(
                'WorkspaceMembersController requires the workspace request attribute set by ResolveWorkspace.',
            );
        }

        // Defensive: the team id should already be set by ResolveWorkspace,
        // but re-asserting keeps this controller safe if it's ever called from
        // a flow that bypasses that middleware (e.g. a console runner).
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);

        return $workspace;
    }
}
