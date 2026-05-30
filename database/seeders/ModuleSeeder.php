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
            $existing = Module::query()->where('key', $module['key'])->first();

            if ($existing) {
                // Preserve the admin's enabled choice; only sync metadata.
                $existing->update([
                    'name' => $module['name'],
                    'morph_alias' => $module['morph_alias'],
                ]);

                continue;
            }

            Module::create($module);
        }
    }
}
