<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /** @var class-string<Workspace> */
    protected $model = Workspace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'slug' => $slug,
            'name' => ucfirst($slug),
            'status' => 'active',
            'files_feature_enabled' => false,
        ];
    }

    public function withFiles(): static
    {
        return $this->state(fn () => ['files_feature_enabled' => true]);
    }
}
