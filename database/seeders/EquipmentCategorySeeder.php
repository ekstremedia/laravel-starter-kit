<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\EquipmentCategory\Models\EquipmentCategory;
use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Database\Seeder;

/**
 * Demo equipment categories per workspace so the relation is visible on a fresh
 * install. Idempotent (firstOrCreate on workspace + name). Must run BEFORE
 * EquipmentSeeder, which files each equipment row under one of these.
 */
class EquipmentCategorySeeder extends Seeder
{
    public function run(): void
    {
        $demo = [
            ['name' => 'Vehicle', 'color' => '#3b82f6', 'description' => 'Cars, vans and anything that drives.'],
            ['name' => 'Machine', 'color' => '#f59e0b', 'description' => 'Heavy and powered machinery.'],
            ['name' => 'Device', 'color' => '#10b981', 'description' => 'Laptops, phones and electronics.'],
            ['name' => 'Tool', 'color' => '#8b5cf6', 'description' => 'Hand and power tools.'],
        ];

        Workspace::query()->each(function (Workspace $workspace) use ($demo): void {
            foreach ($demo as $row) {
                EquipmentCategory::firstOrCreate(
                    ['workspace_id' => $workspace->id, 'name' => $row['name']],
                    ['color' => $row['color'], 'description' => $row['description']],
                );
            }
        });
    }
}
