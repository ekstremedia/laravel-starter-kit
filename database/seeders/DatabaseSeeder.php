<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * NOTE: we intentionally do NOT use the `WithoutModelEvents` trait here.
     * stancl/tenancy's Tenant model relies on Eloquent `creating`/`created` events
     * (mapped via `$dispatchesEvents`) to fire `TenantCreated`, which in turn runs
     * the schema-creation + migration job pipeline. Silencing model events would
     * leave freshly-seeded customers without a Postgres schema.
     */
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            CustomerSeeder::class,
            UserSeeder::class,
            EmailTemplateSeeder::class,
            // Demo file-owning entity. Remove with the rest of the Assets module.
            AssetSeeder::class,
        ]);
    }
}
