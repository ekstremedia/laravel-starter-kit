<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Modules\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Registers the built-in domain modules. Idempotent (updateOrCreate on `key`),
 * so re-seeding never duplicates or clobbers an admin's enabled/disabled choice
 * for an existing module — only the display name / morph alias are kept in sync.
 *
 * Add a row here when you add a module (mirrors the module's config default).
 */
class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            [
                'key' => 'equipment',
                'name' => 'Equipment',
                'morph_alias' => 'equipment',
                'enabled' => (bool) config('equipment.enabled', true),
            ],
        ];

        foreach ($modules as $module) {
            $row = Module::query()->firstOrNew(['key' => $module['key']]);
            $row->name = $module['name'];
            $row->morph_alias = $module['morph_alias'];
            // Only seed `enabled` on first create (respecting the config flag);
            // on re-seed the admin's on/off choice is preserved.
            if (! $row->exists) {
                $row->enabled = $module['enabled'];
            }
            $row->save();
        }
    }
}
