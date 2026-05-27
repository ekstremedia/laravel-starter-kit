<?php

declare(strict_types=1);

use App\Domains\Tenancy\Models\Tenant;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLSchemaManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;

return [
    /**
     * Multi-tenancy is always on. The app is customer-scoped end-to-end:
     * routes live under `/c/{customer}/...`, `/app` is the post-login picker,
     * and `CustomerSeeder` provisions a `default` workspace on `migrate --seed`.
     * At least one customer must exist for users to reach anything beyond `/app`.
     *
     * Kept as a config key (rather than removed) so legacy callers that read
     * it still resolve cleanly.
     */
    'enabled' => true,

    'tenant_model' => Tenant::class,

    // We don't generate tenant IDs in application code — the `tenants` table's
    // auto-increment primary key supplies them, and stancl then derives schema
    // names as `tenant<id>` (e.g. tenant1, tenant2, ...). Flip this to a custom
    // generator class if you want UUIDs or another scheme instead; note you'd
    // need to adjust the tenants migration + Spatie team_id column types to match.
    'id_generator' => null,

    /**
     * Slug of the customer new registrations are added to (only consulted when
     * `enabled` above is true). Kept in config so a deployment can redirect
     * signups to a different customer without a code change.
     */
    'default_customer_slug' => env('TENANCY_DEFAULT_CUSTOMER', 'default'),

    // Domain-based identification is unused (we use the path prefix /c/{customer}).
    // The Domain model and table were intentionally removed from this starter kit.
    'domain_model' => Domain::class,

    /**
     * Central domains are irrelevant for path-based tenancy: the "central vs tenant"
     * split is done at the *route* level, not the *domain* level. Kept for stancl's
     * internal defaults only.
     */
    'central_domains' => [
        '127.0.0.1',
        'localhost',
    ],

    /**
     * Tenancy bootstrappers are executed when tenancy is initialized.
     *
     * v1 keeps it minimal: only the DB (PG search_path) switches per tenant, plus the
     * queue bootstrapper so queued jobs re-initialize tenancy when they run. Cache,
     * filesystem and redis are intentionally left central; revisit once we need
     * per-tenant isolation of those.
     */
    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
    ],

    /**
     * Database tenancy config. Used by DatabaseTenancyBootstrapper.
     *
     * Strategy: one PostgreSQL database, one schema per tenant. The schema manager
     * creates/drops `tenant<id>` schemas; the bootstrapper flips search_path so
     * unqualified tables resolve to the tenant schema, falling back to `public` for
     * shared tables (users, sessions, jobs, etc.).
     */
    'database' => [
        'central_connection' => env('DB_CONNECTION', 'pgsql'),

        'template_tenant_connection' => null,

        // Schema names look like "tenant<id>" (e.g. tenant1, tenant2) because
        // `id_generator` is null and IDs come from the auto-increment PK.
        'prefix' => 'tenant',
        'suffix' => '',

        'managers' => [
            'sqlite' => SQLiteDatabaseManager::class,
            'mysql' => MySQLDatabaseManager::class,
            'mariadb' => MySQLDatabaseManager::class,
            'pgsql' => PostgreSQLSchemaManager::class,
        ],
    ],

    /**
     * Cache tenancy config. Not active (bootstrapper disabled above) but kept so the
     * tag base is ready if we switch it back on.
     */
    'cache' => [
        'tag_base' => 'tenant',
    ],

    /**
     * Filesystem tenancy config. Not active (bootstrapper disabled above).
     */
    'filesystem' => [
        'suffix_base' => 'tenant',
        'disks' => [
            'local',
            'public',
        ],
        'root_override' => [
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],
        'suffix_storage_path' => true,
        'asset_helper_tenancy' => true,
    ],

    /**
     * Redis tenancy config. Not active (bootstrapper disabled above).
     */
    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [],
    ],

    'features' => [
        // Enable later as needed.
    ],

    // Tenancy's built-in asset routes are off because we serve everything centrally.
    'routes' => false,

    /**
     * Parameters used by the tenants:migrate command.
     */
    'migration_parameters' => [
        '--force' => true,
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    /**
     * Parameters used by the tenants:seed command.
     */
    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder',
    ],
];
