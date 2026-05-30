<?php

declare(strict_types=1);

use App\Domains\Modules\Models\Module;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->admin = makeSuperAdmin(User::factory()->create());
});

// ── Users ────────────────────────────────────────────────────────────────────

it('returns a single user row in the index list-shape', function () {
    $workspace = createWorkspace('acme', 'Acme');
    $user = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test']);
    grantRoleOnWorkspace($user, 'Admin', $workspace);

    $this->actingAs($this->admin)
        ->getJson("/admin/users/{$user->id}/live-row")
        ->assertOk()
        ->assertJson(['id' => $user->id, 'first_name' => 'Ada', 'email' => 'ada@example.test', 'is_super_admin' => false])
        ->assertJsonStructure(['id', 'first_name', 'last_name', 'email', 'created_at', 'avatar_thumb_url', 'workspace_roles', 'storage_quota_override'])
        ->assertJsonPath('workspace_roles.0.roles', ['Admin']);
});

it('forbids a non-super-admin from a user live row', function () {
    $target = User::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get("/admin/users/{$target->id}/live-row")
        ->assertForbidden();
});

// ── Roles ────────────────────────────────────────────────────────────────────

it('returns a single role row with permissions + users_count', function () {
    $workspace = createWorkspace();
    $role = Role::findByName('User');
    User::factory()->count(2)->create()->each(fn ($u) => grantRoleOnWorkspace($u, 'User', $workspace));

    $this->actingAs($this->admin)
        ->getJson("/admin/roles/{$role->id}/live-row")
        ->assertOk()
        ->assertJson(['id' => $role->id, 'name' => 'User', 'users_count' => 2])
        ->assertJsonStructure(['id', 'name', 'permissions', 'users_count']);
});

// ── Permissions ──────────────────────────────────────────────────────────────

it('returns a single permission row with roles_count', function () {
    $permission = Permission::create(['name' => 'reports.export']);
    Role::create(['name' => 'reporter'])->givePermissionTo($permission);

    $this->actingAs($this->admin)
        ->getJson("/admin/permissions/{$permission->id}/live-row")
        ->assertOk()
        ->assertJson(['id' => $permission->id, 'name' => 'reports.export', 'roles_count' => 1])
        ->assertJsonStructure(['id', 'name', 'guard_name', 'roles_count']);
});

// ── Workspaces ───────────────────────────────────────────────────────────────

it('returns a single workspace row with users_count', function () {
    $workspace = createWorkspace('acme', 'Acme Corp');
    joinWorkspace(User::factory()->create(), $workspace);
    joinWorkspace(User::factory()->create(), $workspace);

    $this->actingAs($this->admin)
        ->getJson("/admin/workspaces/{$workspace->id}/live-row")
        ->assertOk()
        ->assertJson(['id' => $workspace->id, 'slug' => 'acme', 'name' => 'Acme Corp', 'users_count' => 2])
        ->assertJsonStructure(['id', 'name', 'slug', 'status', 'users_count', 'created_at']);
});

it('forbids a non-super-admin from a workspace live row', function () {
    $workspace = createWorkspace();

    $this->actingAs(User::factory()->create())
        ->get("/admin/workspaces/{$workspace->id}/live-row")
        ->assertForbidden();
});

// ── Modules ──────────────────────────────────────────────────────────────────

it('returns a single module row in the index list-shape', function () {
    $module = Module::create([
        'key' => 'equipment',
        'name' => 'Equipment',
        'enabled' => true,
        'morph_alias' => 'equipment',
        'capabilities' => ['files' => true, 'log' => true],
    ]);

    $this->actingAs($this->admin)
        ->getJson("/admin/modules/{$module->id}/live-row")
        ->assertOk()
        ->assertJson(['id' => $module->id, 'key' => 'equipment', 'name' => 'Equipment', 'enabled' => true])
        ->assertJsonStructure(['id', 'key', 'name', 'enabled', 'morph_alias', 'features' => ['files' => ['supported', 'enabled']], 'record_count', 'storage_used_bytes']);
});

it('redirects a guest and 404s an unknown id', function () {
    $role = Role::findByName('User');

    $this->get("/admin/roles/{$role->id}/live-row")->assertRedirect('/login');
    $this->actingAs($this->admin)->getJson('/admin/roles/999999/live-row')->assertNotFound();
});
