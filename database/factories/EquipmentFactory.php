<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Equipment\Models\Equipment;
use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    /** @var class-string<Equipment> */
    protected $model = Equipment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            // Title-cased two-word name with a short suffix so large seed batches
            // don't exhaust faker's unique() pool (the table allows duplicates).
            'name' => ucwords(fake()->words(2, true)).' '.mb_strtoupper(fake()->bothify('##?')),
            'category' => fake()->randomElement(['Vehicle', 'Machine', 'Device', 'Tool', null]),
            'serial' => fake()->boolean(70) ? mb_strtoupper(fake()->bothify('??-#####')) : null,
            'notes' => fake()->boolean(40) ? fake()->sentence() : null,
        ];
    }
}
