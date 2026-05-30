<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Equipment\Models\Equipment;
use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Database\Seeder;

/**
 * Demo Equipment per workspace so the module is visible (and paginates) on a
 * fresh install. The three named rows are idempotent (firstOrCreate); the
 * factory rows top each workspace up past one page so pagination/sorting/search
 * are demonstrable. Delete this seeder (and its DatabaseSeeder call) together
 * with the rest of the Equipment module to drop the demo.
 */
class EquipmentSeeder extends Seeder
{
    /** Top each workspace up to at least this many rows (page size is 20). */
    private const TARGET_ROWS = 45;

    public function run(): void
    {
        $demo = [
            ['name' => 'Delivery Van', 'category' => 'Vehicle', 'serial' => 'VAN-2041'],
            ['name' => 'Forklift', 'category' => 'Machine', 'serial' => 'FL-0087'],
            ['name' => 'Laptop — Dell XPS', 'category' => 'Device', 'serial' => 'DXPS-5521'],
        ];

        Workspace::query()->each(function (Workspace $workspace) use ($demo): void {
            foreach ($demo as $row) {
                Equipment::firstOrCreate(
                    ['workspace_id' => $workspace->id, 'name' => $row['name']],
                    ['category' => $row['category'], 'serial' => $row['serial']],
                );
            }

            $missing = self::TARGET_ROWS - Equipment::query()->where('workspace_id', $workspace->id)->count();
            if ($missing > 0) {
                Equipment::factory()->count($missing)->create(['workspace_id' => $workspace->id]);
            }
        });
    }
}
