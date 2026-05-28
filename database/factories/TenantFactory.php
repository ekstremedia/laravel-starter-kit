<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /** @var class-string<Tenant> */
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'slug' => $slug,
            'name' => ucfirst($slug),
            'headline' => fake()->sentence(6),
            'about' => fake()->paragraph(3),
            'location' => fake()->city().', '.fake()->countryCode(),
            'website' => 'https://'.fake()->domainName(),
            'status' => 'active',
            'files_feature_enabled' => false,
        ];
    }

    public function withFiles(): static
    {
        return $this->state(fn () => ['files_feature_enabled' => true]);
    }
}
