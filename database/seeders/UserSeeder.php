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
        $defaultWorkspace = Workspace::query()
            ->where('slug', config('workspaces.default_workspace_slug', 'default'))
            ->first();

        // SuperAdmin (from .env) — platform super-user. The `is_super_admin`
        // boolean on the user row is the single source of truth; it grants
        // access to /admin/*, lets them enter any workspace, and bypasses
        // workspace-scoped role checks.
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
        $this->attachToWorkspace($admin, $defaultWorkspace);
        // Also give them a workspace-scoped Admin role on the default workspace
        // so they have a full member role when they enter it.
        $this->assignWorkspaceRole($admin, 'Admin', $defaultWorkspace);

        if (! env('SEED_DEMO_USERS', false)) {
            return;
        }

        $faker = Faker::create('nb_NO');
        $password = Hash::make('password');

        $this->seedRole($faker, 'Editor', 3, $password, $defaultWorkspace);
        $this->seedRole($faker, 'User', 8, $password, $defaultWorkspace);

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
        $this->attachToWorkspace($unverified, $defaultWorkspace);
        $this->assignWorkspaceRole($unverified, 'User', $defaultWorkspace);
    }

    private function seedRole(Generator $faker, string $role, int $count, string $password, ?Workspace $workspace): void
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
            $this->attachToWorkspace($user, $workspace);
            $this->assignWorkspaceRole($user, $role, $workspace);
        }
    }

    private function attachToWorkspace(User $user, ?Workspace $workspace): void
    {
        if ($workspace === null) {
            return;
        }

        $user->workspaces()->syncWithoutDetaching([$workspace->id]);
    }

    /**
     * Assigns a workspace-scoped role with team_id = workspace.id so the
     * assignment only applies while that workspace is the active team context.
     */
    private function assignWorkspaceRole(User $user, string $role, ?Workspace $workspace): void
    {
        if ($workspace === null) {
            return;
        }

        try {
            app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        } catch (RoleDoesNotExist) {
            $this->command->warn("Role '{$role}' not found; skipping workspace assignment for {$user->email}. Run RoleAndPermissionSeeder first.");
        } finally {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
    }
}
