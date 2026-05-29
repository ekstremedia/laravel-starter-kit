<?php

namespace App\Domains\Users\Http\Controllers;

use App\Domains\Notifications\Notifications\AccountBannedNotification;
use App\Domains\Notifications\Notifications\AdminTestNotification;
use App\Domains\Notifications\Notifications\WorkspaceMemberAddedNotification;
use App\Domains\Notifications\Notifications\WorkspaceMemberRemovedNotification;
use App\Domains\Users\Models\User;
use App\Domains\Users\Models\UserSetting;
use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Support\WorkspaceMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $sort = $request->string('sort')->toString() ?: 'id';
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';
        $page = (int) $request->input('page', 1);

        // Only allow sorting on a known allowlist; defaults to id desc.
        $allowedSort = ['id', 'first_name', 'last_name', 'email', 'storage_used_bytes'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        // Cache the full payload (paginator + filters + role list + counts).
        // A version counter on the User model invalidates every entry on
        // create/update/delete/restore, so stale reads self-heal on the next
        // request without needing a tagged cache driver.
        $version = User::usersListVersion();
        $key = User::USERS_LIST_CACHE_KEY.':v'.$version.':'.md5(implode('|', [$search, $sort, $direction, $page]));

        // Cache a plain array payload (not the paginator object). Storing the
        // paginator directly breaks round-trip through serialized cache drivers
        // — the unserialized instance loses its LazyCollection wiring and the
        // Inertia response renders empty. Array shape is identical to what
        // Inertia produces from a paginator, so the Vue page is unchanged.
        $payload = Cache::remember($key, 300, function () use ($search, $sort, $direction) {
            $users = User::query()
                ->with(['media', 'setting', 'customers:workspaces.id,name,slug'])
                ->when($search !== '', function ($q) use ($search) {
                    $driver = $q->getModel()->getConnection()->getDriverName();
                    $op = $driver === 'pgsql' ? 'ilike' : 'like';
                    $needle = '%'.mb_strtolower($search).'%';

                    $q->where(function ($q) use ($op, $needle, $driver) {
                        if ($driver === 'pgsql') {
                            $q->where('email', $op, $needle)
                                ->orWhere('first_name', $op, $needle)
                                ->orWhere('last_name', $op, $needle);
                        } else {
                            $q->whereRaw('LOWER(email) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(first_name) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(last_name) LIKE ?', [$needle]);
                        }
                    });
                })
                ->orderBy($sort, $direction)
                ->paginate(15)
                ->withQueryString();

            // Single batched lookup of (user_id, team_id) → role name for the
            // page worth of users. Avoids an N×M hit from calling
            // WorkspaceMembership::rolesOn per row.
            $pageUserIds = $users->getCollection()->pluck('id')->all();
            $rolesByUserAndTeam = [];
            if ($pageUserIds !== []) {
                $mhr = config('permission.table_names.model_has_roles');
                $rolesTable = config('permission.table_names.roles');
                $teamKey = config('permission.column_names.team_foreign_key');

                // `model_has_roles` / `roles` live on the central schema.
                // Stancl swaps the default connection to the tenant mid-
                // request, so pin raw queries explicitly — a bare DB::table
                // here would silently hit the tenant schema when reached
                // from inside `/c/{customer}/...`.
                $central = (string) config('workspaces.database.central_connection');
                $rows = DB::connection($central)->table($mhr)
                    ->join($rolesTable, "{$rolesTable}.id", '=', "{$mhr}.role_id")
                    ->where("{$mhr}.model_type", (new User)->getMorphClass())
                    ->whereIn("{$mhr}.model_id", $pageUserIds)
                    ->get([
                        "{$mhr}.model_id as user_id",
                        "{$mhr}.{$teamKey} as team_id",
                        "{$rolesTable}.name as name",
                    ]);

                foreach ($rows as $row) {
                    $rolesByUserAndTeam[$row->user_id][$row->team_id][] = $row->name;
                }
            }

            $users->getCollection()->transform(function (User $user) use ($rolesByUserAndTeam) {
                $user->setAttribute('avatar_thumb_url', $user->avatarUrl('thumb'));
                // `setting` is eager-loaded above — reach through it directly so
                // each row doesn't hit firstOrCreate (N+1 across a 15-row page).
                $resolved = $user->setting
                    ? array_merge(UserSetting::$defaults, $user->setting->settings ?? [])
                    : UserSetting::$defaults;
                // Expose the raw override so the admin list can distinguish
                // explicit caps (N>0) from explicit unlimited (-1) and
                // inheriting (null). Resolution to the effective cap for
                // display happens on the Vue side.
                $user->setAttribute('storage_quota_override', $resolved['storage_quota_override'] ?? null);

                // Compact per-customer role mapping for the hover tooltip.
                $customerRoles = [];
                foreach ($user->customers as $c) {
                    /** @var Workspace $c */
                    $customerRoles[] = [
                        'id' => $c->id,
                        'name' => $c->name,
                        'slug' => $c->slug,
                        'roles' => array_values(array_unique($rolesByUserAndTeam[$user->id][$c->id] ?? [])),
                    ];
                }
                $user->setAttribute('customer_roles', $customerRoles);

                return $user;
            });

            return [
                'users' => $users->toArray(),
                'allRoles' => Role::orderBy('name')->pluck('name')->all(),
                'userStats' => [
                    'total' => User::count(),
                    'active' => Schema::hasColumn('users', 'banned_at')
                        ? User::whereNull('banned_at')->count()
                        : User::count(),
                ],
            ];
        });

        return Inertia::render('Admin/Users/Index', [
            'users' => $payload['users'],
            'filters' => ['search' => $search, 'sort' => $sort, 'direction' => $direction],
            'allRoles' => $payload['allRoles'],
            'userStats' => $payload['userStats'],
        ]);
    }

    public function setRole(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot change your own role.');
        }

        // Platform admin now only toggles the SuperAdmin flag. Customer-scoped
        // roles (Admin/Editor/User) are managed per customer on the members
        // page — there's no natural "which customer" context at this endpoint.
        $data = $request->validate([
            'role' => ['required', 'string', 'in:SuperAdmin,none'],
        ]);

        $before = $user->isSuperAdmin() ? ['SuperAdmin'] : [];

        // Transaction + row-level lock so two concurrent demotions can't
        // both pass the last-super-admin guard. Force the central connection
        // — this endpoint can be reached via an admin action inside a
        // tenancy-initialized session. We also read the target user's
        // `is_super_admin` through a locked SELECT inside the transaction
        // so a concurrent flip between route-model binding and this block
        // can't make us take the wrong branch.
        $central = (string) config('workspaces.database.central_connection');
        $locked = DB::connection($central)->transaction(function () use ($user, $data, $central) {
            $current = (bool) DB::connection($central)->table('users')
                ->where('id', $user->id)
                ->lockForUpdate()
                ->value('is_super_admin');

            if ($current && $data['role'] !== 'SuperAdmin') {
                // Lock every SuperAdmin row so a concurrent request can't
                // slip past the "last admin" count check.
                DB::connection($central)->table('users')->where('is_super_admin', true)->lockForUpdate()->get();

                $remaining = User::where('is_super_admin', true)
                    ->where('id', '!=', $user->id)
                    ->count();
                if ($remaining === 0) {
                    return false;
                }

                $user->forceFill(['is_super_admin' => false])->save();
            } elseif (! $current && $data['role'] === 'SuperAdmin') {
                $user->forceFill(['is_super_admin' => true])->save();
            }

            return true;
        });

        if ($locked === false) {
            return back()->with('error', 'Cannot demote the last super admin.');
        }

        User::bumpUsersListVersion();

        activity('user')
            ->performedOn($user)
            ->withProperties(['before' => $before, 'after' => [$data['role']], 'target_user_id' => $user->id])
            ->event('role_changed')
            ->log('User role changed');

        return back()->with('success', __('flash.users.role_updated', ['role' => $data['role']]));
    }

    /**
     * Grant or revoke a grantable platform-level capability for a user.
     *
     * Platform capabilities live in the users.platform_permissions JSON column
     * rather than as Spatie permissions: the package's team schema forces
     * model_has_permissions.team_id non-null, so a global (team-less) grant
     * isn't representable. SuperAdmins bypass these via Gate::before, so this
     * endpoint only matters for delegating to non-super-admins. Super-admin
     * gated at the route layer.
     */
    public function setPlatformPermission(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            // Allowlist — extend as new platform capabilities are introduced.
            'capability' => ['required', 'string', 'in:manage_email_templates'],
            'enabled' => ['required', 'boolean'],
        ]);

        $capability = $data['capability'];

        // Drop the capability first, then re-add when enabling — keeps the
        // array deduplicated regardless of prior state.
        $current = array_values(array_filter(
            $user->platform_permissions ?? [],
            fn (string $c) => $c !== $capability
        ));
        if ($data['enabled']) {
            $current[] = $capability;
        }

        $user->forceFill(['platform_permissions' => $current === [] ? null : $current])->save();

        User::bumpUsersListVersion();

        activity('user')
            ->performedOn($user)
            ->withProperties([
                'capability' => $capability,
                'enabled' => $data['enabled'],
                'target_user_id' => $user->id,
            ])
            ->event('platform_permission_changed')
            ->log('User platform permission changed');

        return back()->with('success', __('flash.users.platform_permission_updated'));
    }

    public function setQuota(Request $request, User $user): RedirectResponse
    {
        // `storage_quota_override`:
        //   null  = inherit (3-tier resolution falls back to customer/app default)
        //   -1    = explicit unlimited for this user
        //    0    = hard-disabled
        //    N>0  = byte cap
        // Capped at 2^53-1 so values round-trip through JS consumers
        // without losing precision (Number.MAX_SAFE_INTEGER). Real quotas
        // don't come close — this is a belt-and-braces guard against
        // pasting an accidentally huge number.
        $data = $request->validate([
            'storage_quota_override' => ['nullable', 'integer', 'min:-1', 'max:'.((2 ** 53) - 1)],
            'files_enabled' => 'sometimes|boolean',
        ]);

        $patch = [];
        if (array_key_exists('storage_quota_override', $data)) {
            $patch['storage_quota_override'] = $data['storage_quota_override'];
            // Reset alert tracking when quota moves, otherwise users who
            // already crossed 95/100 % under the old cap would silently stop
            // receiving alerts (the stored threshold would shadow the new one).
            $patch['storage_last_alerted_threshold'] = null;
        }
        if (array_key_exists('files_enabled', $data)) {
            $patch['files_enabled'] = (bool) $data['files_enabled'];
        }

        if ($patch === []) {
            return back();
        }

        $user->settings()->merge($patch);

        // The admin user list caches its payload for 5 minutes keyed on this
        // version counter. setRole/setCustomerRole already bump it after
        // mutation — do the same here so the storage bar and override badge
        // reflect the new value on the next list render instead of lagging
        // up to the full TTL.
        User::bumpUsersListVersion();

        return back()->with('success', __('admin.users.quota_updated'));
    }

    public function create(): Response
    {
        // No roles passed: roles are customer-scoped and assigned after
        // creation from the user show page / customer members panel. The
        // create form only collects account fields.
        return Inertia::render('Admin/Users/Create');
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'email_verified_at' => now(),
        ]);

        // Roles are customer-scoped now, so platform user-creation only
        // produces the account. Customer-Admins assign per-customer roles
        // from the /c/{customer}/members page (or via attachCustomer below).
        activity('user')
            ->performedOn($user)
            ->event('created')
            ->log("Created user {$user->email}");

        return redirect()->route('admin.users.index')->with('success', __('flash.users.created'));
    }

    public function show(User $user): Response
    {
        // Eager-load `customers` with the ordering/columns we render so the
        // closure below can reuse the already-loaded collection instead of
        // firing a second SELECT.
        $user->load([
            'media',
            'customers' => fn ($q) => $q->orderBy('name'),
        ]);

        $recentActivity = Activity::query()
            ->where(function ($q) use ($user) {
                $q->where('causer_id', $user->id)->where('causer_type', $user->getMorphClass())
                    ->orWhere(function ($q) use ($user) {
                        $q->where('subject_id', $user->id)->where('subject_type', $user->getMorphClass());
                    });
            })
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'log_name' => $a->log_name,
                'description' => $a->description,
                'event' => $a->event,
                'created_at' => $a->created_at->toIso8601String(),
                'causer_id' => $a->causer_id,
            ]);

        // Per-customer role for each customer the user belongs to. Resolved in
        // one batch lookup rather than N queries through WorkspaceMembership,
        // since the admin show page is SuperAdmin-only and we want it to
        // render fast regardless of membership size.
        $customerIds = $user->customers->pluck('id')->all();
        $rolesByTeam = [];
        if ($customerIds !== []) {
            $mhr = config('permission.table_names.model_has_roles');
            $rolesTable = config('permission.table_names.roles');
            $teamKey = config('permission.column_names.team_foreign_key');

            // `team_id` lives on both tables (roles.team_id for shared/global
            // role rows, model_has_roles.team_id for per-assignment scope), so
            // every reference has to be fully qualified — otherwise Postgres
            // raises "column reference team_id is ambiguous". Pin to central
            // since these tables live in the landlord schema.
            $central = (string) config('workspaces.database.central_connection');
            $rows = DB::connection($central)->table($mhr)
                ->join($rolesTable, "{$rolesTable}.id", '=', "{$mhr}.role_id")
                ->where("{$mhr}.model_type", (new User)->getMorphClass())
                ->where("{$mhr}.model_id", $user->id)
                ->whereIn("{$mhr}.{$teamKey}", $customerIds)
                ->get([$mhr.'.'.$teamKey.' as team_id', $rolesTable.'.name as name']);

            foreach ($rows as $row) {
                $rolesByTeam[$row->team_id][] = $row->name;
            }
        }

        return Inertia::render('Admin/Users/Show', [
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'full_name' => $user->fullName(),
                'email' => $user->email,
                'email_verified_at' => optional($user->email_verified_at)->toIso8601String(),
                'banned_at' => optional($user->banned_at)->toIso8601String(),
                'banned_reason' => $user->banned_reason,
                'last_login_at' => optional($user->last_login_at)->toIso8601String(),
                'created_at' => $user->created_at->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'is_super_admin' => $user->isSuperAdmin(),
                'platform_permissions' => $user->platform_permissions ?? [],
                'avatar_url' => $user->avatarUrl('avatar'),
                'avatar_thumb_url' => $user->avatarUrl('thumb'),
                'unread_notifications_count' => $user->unreadNotifications()->count(),
                'customers' => (function () use ($user, $rolesByTeam): array {
                    /** @var array<int, Workspace> $list */
                    $list = $user->customers->all();

                    return array_map(fn (Workspace $c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'slug' => $c->slug,
                        'roles' => array_values(array_unique($rolesByTeam[$c->id] ?? [])),
                    ], $list);
                })(),
            ],
            'assignable_roles' => WorkspaceMembership::assignableRoles(),
            'activity' => $recentActivity,
        ]);
    }

    public function edit(User $user): Response
    {
        /** @var array<int, Workspace> $customersList */
        $customersList = $user->customers()->orderBy('name')->get(['workspaces.id', 'name', 'slug'])->all();
        $customerIds = array_map(fn (Workspace $c) => $c->id, $customersList);

        $rolesByTeam = [];
        if ($customerIds !== []) {
            $mhr = config('permission.table_names.model_has_roles');
            $rolesTable = config('permission.table_names.roles');
            $teamKey = config('permission.column_names.team_foreign_key');

            $central = (string) config('workspaces.database.central_connection');
            $rows = DB::connection($central)->table($mhr)
                ->join($rolesTable, "{$rolesTable}.id", '=', "{$mhr}.role_id")
                ->where("{$mhr}.model_type", (new User)->getMorphClass())
                ->where("{$mhr}.model_id", $user->id)
                ->whereIn("{$mhr}.{$teamKey}", $customerIds)
                ->get([$mhr.'.'.$teamKey.' as team_id', $rolesTable.'.name as name']);

            foreach ($rows as $row) {
                $rolesByTeam[$row->team_id][] = $row->name;
            }
        }

        return Inertia::render('Admin/Users/Edit', [
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'customers' => array_map(fn (Workspace $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'roles' => array_values(array_unique($rolesByTeam[$c->id] ?? [])),
                ], $customersList),
            ],
            'assignable_roles' => WorkspaceMembership::assignableRoles(),
            'all_customers' => Workspace::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug'])->toArray(),
        ]);
    }

    public function verify(User $user): RedirectResponse
    {
        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();

            activity('user')->performedOn($user)->event('email_verified')
                ->log("Admin marked {$user->email} as verified");
        }

        return back()->with('success', __('flash.users.verified'));
    }

    public function unverify(User $user): RedirectResponse
    {
        if ($user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => null])->save();

            activity('user')->performedOn($user)->event('email_unverified')
                ->log("Admin cleared verification for {$user->email}");
        }

        return back()->with('success', __('flash.users.unverified'));
    }

    public function ban(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot ban yourself.');
        }
        if ($user->isSuperAdmin()) {
            return back()->with('error', 'You cannot ban another super admin.');
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user->ban($data['reason'] ?? null);
        $user->notify(new AccountBannedNotification($data['reason'] ?? null));

        activity('user')
            ->performedOn($user)
            ->withProperties(['reason' => $data['reason'] ?? null])
            ->event('banned')
            ->log("Banned {$user->email}");

        return back()->with('success', __('flash.users.banned'));
    }

    public function unban(User $user): RedirectResponse
    {
        $user->unban();

        activity('user')->performedOn($user)->event('unbanned')
            ->log("Unbanned {$user->email}");

        return back()->with('success', __('flash.users.unbanned'));
    }

    public function resendVerification(User $user): RedirectResponse
    {
        if ($user->hasVerifiedEmail()) {
            return back()->with('error', 'User is already verified.');
        }

        $user->sendEmailVerificationNotification();

        activity('user')->performedOn($user)->event('verification_resent')
            ->log("Resent verification email to {$user->email}");

        return back()->with('success', __('flash.users.verification_resent'));
    }

    public function reset2fa(User $user): RedirectResponse
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        activity('user')->performedOn($user)->event('two_factor_reset')
            ->log("Reset 2FA for {$user->email}");

        return back()->with('success', __('flash.users.twofa_reset'));
    }

    public function sendPasswordReset(User $user): RedirectResponse
    {
        $status = Password::sendResetLink(['email' => $user->email]);

        activity('user')->performedOn($user)->event('password_reset_sent')
            ->log("Sent password reset link to {$user->email}");

        return back()->with(
            $status === Password::RESET_LINK_SENT ? 'success' : 'error',
            $status === Password::RESET_LINK_SENT
                ? 'Password reset link sent.'
                : 'Could not send reset link.',
        );
    }

    public function notifyTest(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $user->notify(new AdminTestNotification($data['message'] ?? 'Hello from the admin panel!'));

        activity('user')->performedOn($user)->event('test_notification_sent')
            ->log("Sent test notification to {$user->email}");

        return back()->with('success', __('flash.users.test_notification_sent'));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        $passwordChanged = ! empty($data['password']);

        // Capture the "before" snapshot for audit-log diffing so the activity
        // entry records which fields actually changed (pure profile edits
        // used to be silently unlogged while only password changes surfaced).
        $before = $user->only(['first_name', 'last_name', 'email']);

        $user->fill([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
        ]);

        if ($passwordChanged) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        $after = $user->only(['first_name', 'last_name', 'email']);
        $changedFields = array_keys(array_udiff_assoc($after, $before, fn ($a, $b) => $a === $b ? 0 : 1));

        // Log every admin update, not just password changes. Roles are
        // customer-scoped and managed from `/c/{customer}/members` so they
        // don't appear here.
        if ($passwordChanged || $changedFields !== []) {
            activity('user')
                ->performedOn($user)
                ->withProperties([
                    'password_changed' => $passwordChanged,
                    'changed_fields' => $changedFields,
                ])
                ->event('admin_updated')
                ->log("Admin updated user {$user->email}");
        }

        return redirect()->route('admin.users.index')->with('success', __('flash.users.updated'));
    }

    public function attachCustomer(Request $request, User $user): RedirectResponse
    {
        // `exists:tenants,id` would resolve through the default DB connection,
        // which swaps to the tenant mid-request. Workspace lives on the central
        // schema — use a closure against the Eloquent model so the check
        // honours `Workspace::$connection` regardless of ambient tenancy state.
        $data = $request->validate([
            'customer_ids' => ['required', 'array', 'min:1'],
            'customer_ids.*' => [
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $exists = Workspace::query()
                        ->where('id', $value)
                        ->where('status', 'active')
                        ->exists();
                    if (! $exists) {
                        $fail(__('validation.exists', ['attribute' => $attribute]));
                    }
                },
            ],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(WorkspaceMembership::assignableRoles())],
            'notify' => ['boolean'],
        ]);

        /** @var Collection<int, Workspace> $customers */
        $customers = Workspace::query()
            ->where('status', 'active')
            ->whereIn('id', $data['customer_ids'])
            ->get();

        $existingIds = $user->customers()->pluck('workspaces.id')->all();
        $newCustomers = $customers->reject(fn (Workspace $c) => in_array($c->id, $existingIds, true));

        $roles = array_values(array_unique($data['roles']));
        foreach ($customers as $attaching) {
            /** @var Workspace $attaching */
            WorkspaceMembership::attach($user, $attaching, $roles);
        }

        $newNames = [];
        foreach ($newCustomers as $customer) {
            /** @var Workspace $customer */
            $newNames[] = $customer->name;

            if ($data['notify'] ?? false) {
                $user->notify(new WorkspaceMemberAddedNotification($customer));
            }

            activity('user')
                ->performedOn($user)
                ->withProperties(['customer' => $customer->name, 'notify' => $data['notify'] ?? false])
                ->event('customer_attached')
                ->log("Added {$user->email} to {$customer->name}");
        }

        if ($newNames === []) {
            return back();
        }

        $names = implode(', ', $newNames);

        if (count($newNames) === 1) {
            return back()->with('success', __('flash.users.customer_attached', ['email' => $user->email, 'name' => $names]));
        }

        return back()->with('success', __('flash.users.customers_attached', ['email' => $user->email, 'names' => $names]));
    }

    public function setCustomerRole(Request $request, User $user, Workspace $customer): RedirectResponse
    {
        // Require at least one role — posting `roles: []` would otherwise
        // leave the user as a member with zero roles (matching nothing in
        // `can()` checks), which breaks the pivot+role atomicity that
        // `WorkspaceMembership` explicitly promises. Removing membership is
        // a separate flow (`detachCustomer`).
        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(WorkspaceMembership::assignableRoles())],
        ]);

        if (! $user->belongsToCustomer($customer)) {
            return back()->with('error', __('flash.customers.not_member', ['email' => $user->email, 'name' => $customer->name]));
        }

        $roles = array_values(array_unique($data['roles']));
        WorkspaceMembership::syncRoles($user, $customer, $roles);

        activity('user')
            ->performedOn($user)
            ->withProperties(['customer' => $customer->name, 'roles' => $roles])
            ->event('customer_role_changed')
            ->log("Set {$user->email}'s roles on {$customer->name} to ".(empty($roles) ? '(none)' : implode(', ', $roles)));

        User::bumpUsersListVersion();

        return back()->with('success', __('flash.users.customer_role_updated', [
            'email' => $user->email,
            'name' => $customer->name,
            'role' => empty($roles) ? __('admin.users.no_roles') : implode(', ', $roles),
        ]));
    }

    public function detachCustomer(Request $request, User $user, Workspace $customer): RedirectResponse
    {
        $data = $request->validate([
            'notify' => ['boolean'],
        ]);

        $customerName = $customer->name;

        if (! $user->belongsToCustomer($customer)) {
            return back()->with('error', __('flash.customers.not_member', ['email' => $user->email, 'name' => $customerName]));
        }

        WorkspaceMembership::detach($user, $customer);

        if ($data['notify'] ?? false) {
            $user->notify(new WorkspaceMemberRemovedNotification($customerName));
        }

        activity('user')
            ->performedOn($user)
            ->withProperties(['customer' => $customerName, 'notify' => $data['notify'] ?? false])
            ->event('customer_detached')
            ->log("Removed {$user->email} from {$customerName}");

        return back()->with('success', __('flash.users.customer_detached', ['email' => $user->email, 'name' => $customerName]));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $email = $user->email;
        // Snapshot the audit facts BEFORE delete(): after the row is gone,
        // `isSuperAdmin()` reads a stripped attribute set (always false) and
        // the customers() relation resolves against a detached model.
        $wasSuperAdmin = $user->isSuperAdmin();
        $memberships = $user->customers()->pluck('slug')->all();
        $user->delete();

        activity('user')
            ->withProperties([
                'email' => $email,
                'was_super_admin' => $wasSuperAdmin,
                'memberships' => $memberships,
            ])
            ->event('deleted')
            ->log("Deleted user {$email}");

        return redirect()->route('admin.users.index')->with('success', __('flash.users.deleted'));
    }
}
