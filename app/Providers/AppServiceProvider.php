<?php

namespace App\Providers;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Assets\Models\Asset;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Files\Models\CompanyFileLink;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Models\FileShare;
use App\Domains\Operations\Models\Activity;
use App\Domains\Users\Models\PersonalAccessToken;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Support\WorkspaceContext;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // personal_access_tokens lives on the central schema — point Sanctum
        // at our pinned subclass before any token query runs, or tenant-scoped
        // requests will try to read the table from the active tenant schema.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Current-workspace context (replaces stancl's tenancy() helper). A
        // singleton so the resolving middleware and the global tenant scope
        // read the same active workspace for the request.
        $this->app->singleton(WorkspaceContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Models now live in app/Domains/*/Models, but their factories stay
        // flat in database/factories (Database\Factories\<Name>Factory). The
        // default resolver would look for Database\Factories\Domains\...\Factory,
        // so map every model to its basename factory instead.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName): string => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // Pin a polymorphic morph map. The KEYS are the historical
        // `App\Models\*` class strings already stored in every `*_type` column
        // (file_items.owner_type, media.model_type, activity_log.subject/causer,
        // model_has_roles/permissions.model_type, notifications.notifiable_type,
        // personal_access_tokens.tokenable_type). Keeping the keys stable means
        // `getMorphClass()` keeps returning those exact strings even after the
        // model classes move into app/Domains/* — so existing rows resolve with
        // zero data backfill. When a model moves, only update its VALUE below.
        Relation::morphMap([
            'App\\Models\\User' => User::class,
            // KEY stays the historical `App\Models\Tenant` string already stored
            // in `*_type` columns (e.g. file_items.owner_type for company files);
            // only the VALUE moved from Tenant to the renamed Workspace model.
            'App\\Models\\Tenant' => Workspace::class,
            'App\\Models\\FileItem' => FileItem::class,
            'App\\Models\\FileShare' => FileShare::class,
            'App\\Models\\CompanyFileLink' => CompanyFileLink::class,
            'App\\Models\\Conversation' => Conversation::class,
            'App\\Models\\Message' => Message::class,
            'App\\Models\\Role' => Role::class,
            'App\\Models\\Permission' => Permission::class,
            // New domain entities use a clean alias from day one (no legacy
            // FQCN to preserve). New file-owning entities add a line here.
            'asset' => Asset::class,
        ]);

        // Generate absolute URLs from APP_URL rather than the request Host
        // header. Behind nginx/php-fpm the bundled fastcgi_params forward
        // `$host` (not `$http_host`), which drops the port — so redirects and
        // signed links would lose `:8120` when the app runs on a non-80 host
        // port. Pinning the root to APP_URL is both correct here and immune to
        // Host-header poisoning of password-reset / signed URLs.
        if (is_string($appUrl = config('app.url')) && $appUrl !== '') {
            URL::forceRootUrl($appUrl);

            if (str_starts_with($appUrl, 'https://')) {
                URL::forceScheme('https');
            }
        }

        // Catch N+1 queries at their source. We intentionally leave
        // preventSilentlyDiscardingAttributes + preventAccessingMissingAttributes
        // disabled — they surface too many legitimate patterns (partial
        // selects, dynamic attributes) that would derail existing code.
        // Production stays permissive regardless, so a stray unknown attribute
        // never takes the site down in the field.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Stamp the active workspace on every activity_log row. Without this,
        // workspace-scoped dashboards that filter activity by "members of
        // this workspace" would leak rows from other workspaces the same
        // users belong to (a user who's Admin on A and User on B would see
        // B's actions on A's dashboard). Null workspace_id is preserved for
        // genuine central-only events (password reset, profile edit from
        // the picker page, etc.).
        //
        // Escape hatch: callers that fire a deliberately platform-level
        // event while tenancy happens to be initialized can opt out with
        // `activity()->withProperties(['central' => true])->log(...)` — we
        // skip the stamp and leave `workspace_id` null so the row remains in
        // "all central activity" (workspace_id IS NULL) queries.
        Activity::creating(function (Activity $activity): void {
            // `properties` is a Collection cast by Spatie Activitylog (can be
            // null when no properties were set).
            if ((bool) ($activity->properties?->get('central', false) ?? false)) {
                return;
            }

            $tenancy = app(WorkspaceContext::class);
            if ($activity->workspace_id === null && $tenancy->check()) {
                $activity->workspace_id = $tenancy->id();
            }
        });

        // Authenticated users visiting guest-only pages (/login, /register, ...)
        // land on the tenant landing page, which dispatches them into their
        // workspace (or renders the picker for admins / multi-tenant users).
        RedirectIfAuthenticated::redirectUsing(fn () => route('app.landing'));

        Gate::define('viewPulse', function ($user = null) {
            return $user !== null && $user->isSuperAdmin();
        });

        Gate::define('viewLogViewer', function ($user = null) {
            return $user !== null && $user->isSuperAdmin();
        });

        // Grantable platform capability: edit transactional email content (all
        // locales) from the dashboard. Backed by a column, not Spatie (see
        // User::hasPlatformPermission). SuperAdmins pass via Gate::before below.
        Gate::define('manage email templates', function ($user = null) {
            return $user !== null && $user->hasPlatformPermission('manage_email_templates');
        });

        // SuperAdmin bypass: `Gate::before` runs before every ability check
        // (Spatie permission gates included), so a SuperAdmin clears workspace-
        // scoped `can('upload files')` / `can('manage workspace users')` checks
        // even when they enter a workspace they hold no membership role on.
        // Returning `null` falls through to normal resolution for everyone else.
        Gate::before(function ($user, $ability) {
            if ($user !== null && $user->isSuperAdmin()) {
                return true;
            }

            return null;
        });

        // Laravel's default /up route dispatches DiagnosingHealth before it
        // returns 200 — failing a listener flips the response to 500. Hook
        // in a DB ping (and Redis when Redis is the cache/queue driver) so
        // /up is a real dependency probe, not just "PHP booted".
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::connection()->getPdo();

            if (in_array('redis', [(string) config('cache.default'), (string) config('queue.default'), (string) config('session.driver')], true)) {
                Redis::connection()->ping();
            }
        });
    }
}
