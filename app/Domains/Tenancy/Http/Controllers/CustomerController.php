<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Http\Controllers;

use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Support\CustomerMembership;
use App\Domains\Users\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

/**
 * Landlord CRUD for customers.
 *
 * NOTE: the underlying model is `App\Domains\Tenancy\Models\Tenant` (extending stancl/tenancy's
 * base `Tenant` model). "Customer" is our user-facing name for the same row.
 */
class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $customers = Tenant::query()
            ->withCount('users')
            ->when($search !== '', function ($q) use ($search) {
                $escaped = addcslashes(mb_strtolower($search), '%_\\');
                $needle = '%'.$escaped.'%';
                $q->where(function ($q) use ($needle) {
                    $q->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$needle])
                        ->orWhereRaw("LOWER(slug) LIKE ? ESCAPE '\\'", [$needle]);
                });
            })
            ->orderBy('id', 'desc')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Customers/Index', [
            'customers' => $customers,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Customers/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', Rule::unique('tenants', 'slug')],
        ]);

        $slug = $data['slug'] ?? Str::slug($data['name']);

        // The request-level rules above only fire when the client sent a slug.
        // When we fall back to `Str::slug($name)` an odd name can produce an
        // empty string, an over-length value, or collide with an existing
        // customer — re-run the same rules against the resolved slug so the
        // auto-generated branch can't bypass them.
        Validator::make(['slug' => $slug], [
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', Rule::unique('tenants', 'slug')],
        ], [
            'slug.*' => 'Could not derive a valid slug from the name; please provide one explicitly.',
        ])->validate();

        $customer = Tenant::create([
            'name' => $data['name'],
            'slug' => $slug,
            'status' => 'active',
        ]);

        return redirect()
            ->route('admin.customers.edit', $customer)
            ->with('success', __('flash.customers.created', ['name' => $customer->name]));
    }

    public function edit(Tenant $customer, StorageUsageService $usage): Response
    {
        // Same eager-load pattern as CustomerMembersController@index — pull
        // the customer-scoped roles in a single JOIN keyed on
        // `model_has_roles.team_id` so we can show each member's role(s)
        // without an N+1 storm of CustomerMembership::rolesOn calls.
        // Unlike that controller, this is a central /admin route, so
        // tenancy middleware hasn't already set the registrar team id —
        // do it explicitly around the eager load and restore afterwards.
        $teamKey = config('permission.column_names.team_foreign_key');
        $mhrTable = config('permission.table_names.model_has_roles');

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($customer->id);

            $members = $customer->users()
                ->with([
                    'roles' => fn ($q) => $q->where("{$mhrTable}.{$teamKey}", $customer->id),
                    'media',
                ])
                ->orderBy('users.email')
                ->get(['users.id', 'users.public_id', 'users.first_name', 'users.last_name', 'users.email']);
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }

        // Live usage for the "Used: X GB of Y" caption on the admin edit
        // page. Cheap per-page query; surface the fresh number rather than
        // the denormalized column in case it drifted.
        $companyUsed = $usage->usedBytesForTenantCompany($customer);

        // Fetch app settings once — `current()` does firstOrCreate on each
        // call, and we need two fields below.
        $appSettings = AppSetting::current();

        return Inertia::render('Admin/Customers/Edit', [
            'customer' => [
                'id' => $customer->id,
                'slug' => $customer->slug,
                'name' => $customer->name,
                'headline' => $customer->headline,
                'about' => $customer->about,
                'location' => $customer->location,
                'website' => $customer->website,
                'status' => $customer->status,
                'files_feature_enabled' => (bool) $customer->files_feature_enabled,
                'company_files_enabled' => (bool) $customer->company_files_enabled,
                'storage_quota_bytes' => $customer->storage_quota_bytes,
                'storage_used_bytes' => $companyUsed,
                'default_member_storage_bytes' => $customer->default_member_storage_bytes,
                'users' => $members->map(fn (User $user) => [
                    'id' => $user->id,
                    'public_id' => $user->public_id,
                    'email' => $user->email,
                    'full_name' => $user->fullName(),
                    'avatar_thumb_url' => $user->avatarUrl('thumb'),
                    'roles' => $user->roles->pluck('name')->all(),
                ])->values(),
            ],
            'global_files_feature_enabled' => (bool) $appSettings->files_feature_enabled,
            'global_default_personal_storage_bytes' => $appSettings->default_personal_storage_bytes,
        ]);
    }

    public function update(Request $request, Tenant $customer): RedirectResponse
    {
        $globalFilesEnabled = (bool) AppSetting::current()->files_feature_enabled;

        // Same "disabled globally" gate for both feature flags — extracted
        // to a single closure so the error message stays in lockstep if it
        // ever changes.
        $requiresGlobalFiles = function (string $attribute, mixed $value, \Closure $fail) use ($globalFilesEnabled): void {
            if ($value && ! $globalFilesEnabled) {
                $fail('Files feature is disabled globally in App Settings — enable it there first.');
            }
        };

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'about' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:120'],
            'website' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:255'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'files_feature_enabled' => ['sometimes', 'boolean', $requiresGlobalFiles],
            // Company files is the master kill switch for the shared
            // workspace; personal and company are otherwise independent
            // per-customer toggles, both gated only on the global flag.
            'company_files_enabled' => ['sometimes', 'boolean', $requiresGlobalFiles],
            // -1 = explicit unlimited, null = unlimited (no cap set),
            // 0 = blocked, N>0 = byte cap. Capped at 2^53-1 so Inertia
            // round-trips preserve precision (JS safe-integer range).
            'storage_quota_bytes' => ['sometimes', 'nullable', 'integer', 'min:-1', 'max:'.((2 ** 53) - 1)],
            'default_member_storage_bytes' => ['sometimes', 'nullable', 'integer', 'min:-1', 'max:'.((2 ** 53) - 1)],
        ]);

        foreach (['headline', 'about', 'location', 'website'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $trimmed = trim($data[$key]);
                $data[$key] = $trimmed === '' ? null : $trimmed;
            }
        }

        // Personal and company-shared files are independent toggles: a
        // customer can run with one, the other, or both. The global
        // `AppSetting::files_feature_enabled` is still the master kill
        // switch (enforced below) — but at the per-customer level, an
        // admin can e.g. disable personal Files while keeping the shared
        // workspace available.

        $customer->update($data);

        return back()->with('success', __('flash.customers.updated'));
    }

    public function destroy(Tenant $customer): RedirectResponse
    {
        $name = $customer->name;

        // Triggers the TenantDeleted job pipeline → drops the tenant<id> schema.
        $customer->delete();

        return redirect()
            ->route('admin.customers.index')
            ->with('success', __('flash.customers.deleted', ['name' => $name]));
    }

    public function attachMember(Request $request, Tenant $customer): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(CustomerMembership::assignableRoles())],
        ]);

        $user = User::query()->where('email', $data['email'])->firstOrFail();

        CustomerMembership::attach($user, $customer, $data['roles']);

        return back()->with('success', __('flash.customers.member_added', ['email' => $user->email, 'name' => $customer->name]));
    }

    public function detachMember(Tenant $customer, User $user): RedirectResponse
    {
        CustomerMembership::detach($user, $customer);

        return back()->with('success', __('flash.customers.member_removed', ['email' => $user->email, 'name' => $customer->name]));
    }
}
