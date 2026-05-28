<?php

namespace App\Http\Middleware;

use App\Domains\Settings\Models\AppSetting;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Middleware;
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
            // see the team id set by `InitializeTenancyByPath` — Inertia's
            // `share()` runs *before* the rest of the middleware stack, so
            // eagerly reading roles here would yield the pre-tenancy (empty)
            // set on customer-scoped routes.
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
            'tenancy' => [
                'enabled' => (bool) config('tenancy.enabled'),
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
            'customer' => fn () => $this->currentCustomer(),
            // The navbar customer switcher needs the user's memberships, so
            // share a compact list. Capped at 50 — past that, admins should
            // use the full picker or the /admin/customers UI.
            //
            // Keyed under `available_customers` to avoid being shadowed by the
            // `customers` paginator prop on /admin/customers and similar pages.
            'available_customers' => fn () => $this->availableCustomers($request),
        ];
    }

    /**
     * The customer the request is currently scoped to, or null on central routes.
     *
     * @return array<string, mixed>|null
     */
    private function currentCustomer(): ?array
    {
        if (! tenancy()->initialized) {
            return null;
        }

        /** @var Tenant $customer */
        $customer = tenancy()->tenant;

        return [
            'id' => $customer->id,
            'slug' => $customer->slug,
            'name' => $customer->name,
            'headline' => $customer->headline,
            'about' => $customer->about,
            'location' => $customer->location,
            'website' => $customer->website,
            'files_feature_enabled' => (bool) $customer->files_feature_enabled,
            'company_files_enabled' => (bool) $customer->company_files_enabled,
        ];
    }

    /**
     * Customers the authenticated user can enter. Admins see every active one;
     * non-admins see only the customers they are a member of.
     *
     * @return array<int, array<string, mixed>>
     */
    private function availableCustomers(Request $request): array
    {
        if (! config('tenancy.enabled')) {
            return [];
        }

        $user = $request->user();

        if (! $user) {
            return [];
        }

        $query = $user->isSuperAdmin()
            ? Tenant::query()->where('status', 'active')
            : $user->customers()->where('status', 'active');

        /** @var Collection<int, Tenant> $customers */
        $customers = $query->orderBy('name')->limit(50)->get();

        return $customers
            ->map(fn (Tenant $customer) => [
                'id' => $customer->id,
                'slug' => $customer->slug,
                'name' => $customer->name,
                'files_feature_enabled' => (bool) $customer->files_feature_enabled,
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
