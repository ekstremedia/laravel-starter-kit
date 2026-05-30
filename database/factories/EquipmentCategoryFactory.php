<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\EquipmentCategory\Models\EquipmentCategory;
use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentCategory>
 */
class EquipmentCategoryFactory extends Factory
{
    /** @var class-string<EquipmentCategory> */
    protected $model = EquipmentCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => ucwords(fake()->unique()->words(2, true)),
            'color' => fake()->randomElement(['#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899', null]),
            'description' => fake()->boolean(60) ? fake()->sentence() : null,
        ];
    }
}
