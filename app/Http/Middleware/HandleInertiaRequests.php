<?php

namespace App\Http\Middleware;

use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use App\Domains\Users\Models\UserSetting;
use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Middleware;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'request_id' => (string) $request->attributes->get('request_id', ''),
            // `user` resolves lazily so `getRoleNames()` / `getAllPermissions()`
            // see the team id set by `ResolveWorkspace` — Inertia's
            // `share()` runs *before* the rest of the middleware stack, so
            // eagerly reading roles here would yield the pre-tenancy (empty)
            // set on workspace-scoped routes.
            'auth' => [
                'user' => fn () => $request->user() ? [
                    'id' => $request->user()->id,
                    'public_id' => $request->user()->public_id,
                    'first_name' => $request->user()->first_name,
                    'last_name' => $request->user()->last_name,
                    'email' => $request->user()->email,
                    'headline' => $request->user()->headline,
                    'bio' => $request->user()->bio,
                    'location' => $request->user()->location,
                    'website' => $request->user()->website,
                    'email_verified_at' => $request->user()->email_verified_at,
                    'created_at' => $request->user()->created_at,
                    'two_factor_enabled' => ! is_null($request->user()->two_factor_confirmed_at),
                    'full_name' => $request->user()->fullName(),
                    'avatar_url' => $request->user()->avatarUrl('avatar'),
                    'avatar_thumb_url' => $request->user()->avatarUrl('thumb'),
                    'roles' => $request->user()->getRoleNames()->toArray(),
                    'permissions' => $request->user()->getAllPermissions()->pluck('name')->toArray(),
                    'is_super_admin' => $request->user()->isSuperAdmin(),
                    'unread_notifications_count' => $request->user()->unreadNotifications()->count(),
                    'unread_messages_count' => config('chat.enabled')
                        ? $request->user()->unreadMessagesCount()
                        : 0,
                    'is_impersonating' => session()->has('impersonated_by'),
                ] : null,
                // Grantable platform capabilities, surfaced for nav/tab gating.
                // SuperAdmins resolve true via Gate::before.
                'can' => fn () => [
                    'manage_email_templates' => $request->user()?->can('manage email templates') ?? false,
                ],
            ],
            'locale' => app()->getLocale(),
            'debug' => [
                'easy_login_enabled' => (app()->isLocal() || app()->runningUnitTests()) && config('dev.easy_login_enabled'),
            ],
            // Resolved per-user preferences for authenticated users, defaults for
            // guests. Named `user_settings` so it cannot collide with a page-level
            // `settings` prop on admin pages (app settings, mail settings, …).
            'user_settings' => $request->user()
                ? $request->user()->settings()->resolved()
                : UserSetting::$defaults,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'status' => fn () => $request->session()->get('status'),
                // Plain-text Sanctum token; surfaced exactly once after creation.
                'new_token' => fn () => $request->session()->get('new_token'),
            ],
            'app_settings' => fn () => $this->appSettings(),
            'workspaces' => [
                'enabled' => (bool) config('workspaces.enabled'),
            ],
            'chat' => [
                'enabled' => (bool) config('chat.enabled'),
            ],
            // Named `assetsEnabled` (not `assets`) to avoid colliding with the
            // Assets/Index page's own `assets` paginator prop, which would
            // otherwise shadow this shared flag on that page.
            'assetsEnabled' => (bool) config('assets.enabled'),
            // Which OAuth providers to render "Sign in with …" buttons for.
            // Empty array when the whole feature is gated off, so the Vue
            // template's v-if collapses cleanly.
            'oauth' => [
                'providers' => $this->enabledOauthProviders(),
            ],
            'workspace' => fn () => $this->currentWorkspaceProfile(),
            // The workspace ("workspace") the left rail should be scoped to,
            // resolved even on central routes (/home, /admin, …) so the rail
            // shows the same workspace section everywhere instead of only
            // inside a /w/{slug}/... route. Carries the user's workspace-scoped
            // capabilities so permission-gated entries render identically
            // off-route. See currentWorkspace().
            'current_workspace' => fn () => $this->currentWorkspace($request),
            // The navbar workspace switcher needs the user's memberships, so
            // share a compact list. Capped at 50 — past that, admins should
            // use the full picker or the /admin/workspaces UI.
            //
            // Keyed under `available_workspaces` to avoid being shadowed by the
            // `workspaces` paginator prop on /admin/workspaces and similar pages.
            'available_workspaces' => fn () => $this->availableWorkspaces($request),
        ];
    }

    /**
     * The workspace the request is currently scoped to, or null on central routes.
     *
     * @return array<string, mixed>|null
     */
    private function currentWorkspaceProfile(): ?array
    {
        $tenancy = app(WorkspaceContext::class);
        if (! $tenancy->check()) {
            return null;
        }

        /** @var Workspace $workspace */
        $workspace = $tenancy->current();

        return [
            'id' => $workspace->id,
            'slug' => $workspace->slug,
            'name' => $workspace->name,
            'headline' => $workspace->headline,
            'about' => $workspace->about,
            'location' => $workspace->location,
            'website' => $workspace->website,
            'files_feature_enabled' => (bool) $workspace->files_feature_enabled,
            'company_files_enabled' => (bool) $workspace->company_files_enabled,
        ];
    }

    /**
     * The workspace the left rail should be scoped to. Inside a workspace route
     * this is the active tenant; on central routes it falls back to the user's
     * last-visited workspace, then their first membership. Null for guests or
     * users who belong to no active workspace.
     *
     * Resolved regardless of `tenancy.enabled` — the app is always
     * workspace-scoped, so the rail's Private files / dashboard must work even
     * in single-workspace mode. The `tenancy.enabled` flag only governs the
     * multi-tenant *chrome* (Shared files, scope pill, workspace switcher),
     * which is gated in the components that render it.
     *
     * @return array<string, mixed>|null
     */
    private function currentWorkspace(Request $request): ?array
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        // Inside a /w/{slug}/... route the active tenant *is* the workspace,
        // and the Spatie team id is already scoped to it by the tenancy
        // middleware — so read capabilities directly.
        $tenancy = app(WorkspaceContext::class);
        if ($tenancy->check()) {
            /** @var Workspace $workspace */
            $workspace = $tenancy->current();

            return $this->workspacePayload($workspace, $user, alreadyScoped: true);
        }

        /** @var Collection<int, Workspace> $accessible */
        $accessible = $this->accessibleWorkspacesQuery($user)->orderBy('name')->get();

        if ($accessible->isEmpty()) {
            return null;
        }

        $remembered = $user->settings()->resolved()['last_workspace_slug'] ?? null;
        $current = (is_string($remembered) && $remembered !== '')
            ? $accessible->firstWhere('slug', $remembered)
            : null;
        $current ??= $accessible->first();

        return $this->workspacePayload($current, $user, alreadyScoped: false);
    }

    /**
     * Compact workspace shape consumed by the rail, including the user's
     * workspace-scoped capabilities (so Members / Shared files / Assets gate
     * the same on /home as inside the workspace).
     *
     * @return array<string, mixed>
     */
    private function workspacePayload(Workspace $workspace, User $user, bool $alreadyScoped): array
    {
        [$isAdmin, $canViewCompanyFiles] = $this->workspaceCapabilities($workspace, $user, $alreadyScoped);

        return [
            'id' => $workspace->id,
            'slug' => $workspace->slug,
            'name' => $workspace->name,
            'files_feature_enabled' => (bool) $workspace->files_feature_enabled,
            'company_files_enabled' => (bool) $workspace->company_files_enabled,
            'is_admin' => $isAdmin,
            'can_view_company_files' => $canViewCompanyFiles,
        ];
    }

    /**
     * Resolve [isAdmin, canViewCompanyFiles] for the user within $workspace.
     * SuperAdmins short-circuit to true (they bypass membership and pass the
     * workspace.admin gate). For everyone else the checks are Spatie
     * team-scoped, so on central routes we temporarily set the permission team
     * id to the workspace — mirroring ResolveWorkspace — and restore it
     * afterwards, resetting the cached role/permission relations on both sides
     * so neither the central nor the workspace scope leaks into the other.
     *
     * @return array{0: bool, 1: bool}
     */
    private function workspaceCapabilities(Workspace $workspace, User $user, bool $alreadyScoped): array
    {
        if ($user->isSuperAdmin()) {
            return [true, true];
        }

        if ($alreadyScoped) {
            return [$user->hasRole('Admin'), $user->can('view company files')];
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($workspace->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return [$user->hasRole('Admin'), $user->can('view company files')];
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * Base query for the active workspaces a user may enter: every active one
     * for SuperAdmins, only their memberships otherwise. Returns either an
     * Eloquent\Builder or a BelongsToMany relation — both honour the
     * orderBy()/limit()/get() calls the callers chain onto it.
     *
     * @return Builder<Workspace>|BelongsToMany<Workspace, User>
     */
    private function accessibleWorkspacesQuery(User $user): Builder|BelongsToMany
    {
        return $user->isSuperAdmin()
            ? Workspace::query()->where('status', 'active')
            : $user->workspaces()->where('status', 'active');
    }

    /**
     * Workspaces the authenticated user can enter. Admins see every active one;
     * non-admins see only the workspaces they are a member of.
     *
     * @return array<int, array<string, mixed>>
     */
    private function availableWorkspaces(Request $request): array
    {
        if (! config('workspaces.enabled')) {
            return [];
        }

        $user = $request->user();

        if (! $user) {
            return [];
        }

        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = $this->accessibleWorkspacesQuery($user)->orderBy('name')->limit(50)->get();

        return $workspaces
            ->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'slug' => $workspace->slug,
                'name' => $workspace->name,
                'files_feature_enabled' => (bool) $workspace->files_feature_enabled,
            ])
            ->values()
            ->all();
    }

    /**
     * OAuth providers to expose on the login page, each with its human label.
     *
     * @return array<int, array{name: string, label: string}>
     */
    private function enabledOauthProviders(): array
    {
        if (! config('socialite.enabled')) {
            return [];
        }

        $labels = ['google' => 'Google', 'github' => 'GitHub'];

        return collect((array) config('socialite.providers', []))
            ->filter(fn (bool $on): bool => $on)
            ->map(fn (bool $_, string $name) => ['name' => $name, 'label' => $labels[$name] ?? ucfirst($name)])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function appSettings(): array
    {
        try {
            $s = AppSetting::current();

            return [
                'registration_open' => $s->registration_open,
                'login_enabled' => $s->login_enabled,
                'announcement' => $s->announcement_banner
                    ? ['text' => $s->announcement_banner, 'severity' => $s->announcement_severity]
                    : null,
                'files_feature_enabled' => (bool) $s->files_feature_enabled,
            ];
        } catch (Throwable) {
            return [
                'registration_open' => true,
                'login_enabled' => true,
                'announcement' => null,
                'files_feature_enabled' => false,
            ];
        }
    }
}
