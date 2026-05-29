<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Assets\Models\Asset;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * A few demo Assets per customer so the file-owning-entity feature is visible
 * on a fresh install. Idempotent (firstOrCreate). Delete this seeder (and its
 * DatabaseSeeder call) together with the rest of the Assets module to drop the
 * demo.
 */
class AssetSeeder extends Seeder
{
    public function run(): void
    {
        $demo = [
            ['name' => 'Delivery Van', 'category' => 'Vehicle', 'serial' => 'VAN-2041'],
            ['name' => 'Forklift', 'category' => 'Equipment', 'serial' => 'FL-0087'],
            ['name' => 'Laptop — Dell XPS', 'category' => 'Device', 'serial' => 'DXPS-5521'],
        ];

        Tenant::query()->each(function (Tenant $tenant) use ($demo): void {
            foreach ($demo as $row) {
                Asset::firstOrCreate(
                    ['workspace_id' => $tenant->id, 'name' => $row['name']],
                    ['category' => $row['category'], 'serial' => $row['serial']],
                );
            }
        });
    }
}
