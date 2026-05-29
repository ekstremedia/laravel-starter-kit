<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Assets\Models\Asset;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    /** @var class-string<Asset> */
    protected $model = Asset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Tenant::factory(),
            'name' => fake()->unique()->words(2, true),
            'category' => fake()->randomElement(['Vehicle', 'Equipment', 'Device', 'Tool', null]),
            'serial' => fake()->boolean(70) ? mb_strtoupper(fake()->bothify('??-#####')) : null,
            'notes' => fake()->boolean(40) ? fake()->sentence() : null,
            'file_quota_bytes' => null,
        ];
    }
}
