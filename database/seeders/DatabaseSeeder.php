<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * NOTE: we intentionally do NOT use the `WithoutModelEvents` trait here.
     * stancl/tenancy's Workspace model relies on Eloquent `creating`/`created` events
     * (mapped via `$dispatchesEvents`) to fire `TenantCreated`, which in turn runs
     * the schema-creation + migration job pipeline. Silencing model events would
     * leave freshly-seeded workspaces without a Postgres schema.
     */
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            WorkspaceSeeder::class,
            UserSeeder::class,
            EmailTemplateSeeder::class,
            // Module registry — must run before the modules' own seeders so the
            // enabled-flag and stats can resolve.
            ModuleSeeder::class,
            // Demo categories first, then equipment files itself under them
            // (EquipmentSeeder assigns each row an equipment_category_id).
            EquipmentCategorySeeder::class,
            // Demo file-owning module. Remove with the rest of the Equipment module.
            EquipmentSeeder::class,
        ]);
    }
}
