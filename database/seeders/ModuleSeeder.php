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
                // The reference file-owning module: ships both files and a Log.
                'capabilities' => ['files' => true, 'log' => true],
            ],
            [
                'key' => 'equipment_category',
                'name' => 'Equipment categories',
                'morph_alias' => 'equipment_category',
                'enabled' => (bool) config('equipment_category.enabled', true),
                // The reference lean module: a Log, but no file area.
                'capabilities' => ['files' => false, 'log' => true],
                // Grouped under Equipment: shown nested on the workspace settings
                // page and disabled along with its parent (cascade).
                'parent_key' => 'equipment',
            ],
        ];

        foreach ($modules as $module) {
            $row = Module::query()->firstOrNew(['key' => $module['key']]);
            $row->name = $module['name'];
            $row->morph_alias = $module['morph_alias'];
            // Grouping is a code-defined relationship, so keep it in sync on
            // every re-seed (like morph_alias / capabilities).
            $row->parent_key = $module['parent_key'] ?? null;
            // Keep declared capabilities in sync with the code on every re-seed.
            $row->capabilities = $module['capabilities'];
            // Only seed `enabled` + `features` on first create (respecting the
            // config flag); on re-seed the admin's on/off + feature choices are
            // preserved. New modules default every shipped capability to "on".
            if (! $row->exists) {
                $row->enabled = $module['enabled'];
                $row->features = $module['capabilities'];
            }
            $row->save();
        }
    }
}
