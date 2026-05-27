<?php

namespace Database\Factories;

use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** @var class-string<User> */
    protected $model = User::class;

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Set explicitly here, on top of the User::creating hook, so
            // tests that call `Event::fake()` (which suppresses model
            // events) still get a non-null public_id.
            'public_id' => (string) Str::uuid(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Promote the user to platform SuperAdmin. `is_super_admin` is guarded
     * (mass-assignment unsafe — see User model), so we set it after the
     * model has been persisted via `forceFill` to bypass the fillable
     * filter.
     */
    public function superAdmin(): static
    {
        return $this->afterMaking(function (User $user): void {
            $user->forceFill(['is_super_admin' => true]);
        })->afterCreating(function (User $user): void {
            if (! $user->is_super_admin) {
                $user->forceFill(['is_super_admin' => true])->save();
            }
        });
    }
}
