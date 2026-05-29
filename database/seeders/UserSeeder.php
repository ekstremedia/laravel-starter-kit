<?php

namespace Database\Seeders;

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use Faker\Factory as Faker;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\PermissionRegistrar;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $defaultCustomer = Workspace::query()
            ->where('slug', config('workspaces.default_workspace_slug', 'default'))
            ->first();

        // SuperAdmin (from .env) — platform super-user. The `is_super_admin`
        // boolean on the user row is the single source of truth; it grants
        // access to /admin/*, lets them enter any customer, and bypasses
        // customer-scoped role checks.
        $admin = User::firstOrCreate(
            ['email' => env('STARTER_ADMIN_EMAIL', 'admin@example.test')],
            [
                'first_name' => env('STARTER_ADMIN_FIRST_NAME', 'Admin'),
                'last_name' => env('STARTER_ADMIN_LAST_NAME', 'User'),
                'password' => Hash::make(env('STARTER_ADMIN_PASSWORD', 'password')),
                'email_verified_at' => now(),
            ],
        );
        // `is_super_admin` is deliberately unfillable (see User model) so a
        // crafted `/admin/users` payload can't elevate an account via mass
        // assignment — set it explicitly via forceFill here.
        if (! $admin->is_super_admin) {
            $admin->forceFill(['is_super_admin' => true])->save();
        }
        $this->attachToCustomer($admin, $defaultCustomer);
        // Also give them a customer-scoped Admin role on the default customer
        // so they have a full member role when they enter it.
        $this->assignCustomerRole($admin, 'Admin', $defaultCustomer);

        if (! env('SEED_DEMO_USERS', false)) {
            return;
        }

        $faker = Faker::create('nb_NO');
        $password = Hash::make('password');

        $this->seedRole($faker, 'Editor', 3, $password, $defaultCustomer);
        $this->seedRole($faker, 'User', 8, $password, $defaultCustomer);

        // One unverified user so devs can exercise the verify flow
        $unverified = User::firstOrCreate(
            ['email' => 'unverified@example.test'],
            [
                'first_name' => $faker->firstName(),
                'last_name' => $faker->lastName(),
                'password' => $password,
                'email_verified_at' => null,
            ],
        );
        $this->attachToCustomer($unverified, $defaultCustomer);
        $this->assignCustomerRole($unverified, 'User', $defaultCustomer);
    }

    private function seedRole(Generator $faker, string $role, int $count, string $password, ?Workspace $customer): void
    {
        for ($i = 0; $i < $count; $i++) {
            $first = $faker->firstName();
            $last = $faker->lastName();
            $slug = Str::lower(Str::ascii($first).'.'.Str::ascii($last));
            $email = $slug.'@example.test';

            // Avoid collisions on duplicate name pairs
            $suffix = 1;
            while (User::where('email', $email)->exists()) {
                $email = $slug.($suffix++).'@example.test';
            }

            $user = User::create([
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'password' => $password,
                'email_verified_at' => now(),
            ]);
            $this->attachToCustomer($user, $customer);
            $this->assignCustomerRole($user, $role, $customer);
        }
    }

    private function attachToCustomer(User $user, ?Workspace $customer): void
    {
        if ($customer === null) {
            return;
        }

        $user->customers()->syncWithoutDetaching([$customer->id]);
    }

    /**
     * Assigns a customer-scoped role with team_id = customer.id so the
     * assignment only applies while that customer is the active team context.
     */
    private function assignCustomerRole(User $user, string $role, ?Workspace $customer): void
    {
        if ($customer === null) {
            return;
        }

        try {
            app(PermissionRegistrar::class)->setPermissionsTeamId($customer->id);
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        } catch (RoleDoesNotExist) {
            $this->command->warn("Role '{$role}' not found; skipping customer assignment for {$user->email}. Run RoleAndPermissionSeeder first.");
        } finally {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
    }
}
