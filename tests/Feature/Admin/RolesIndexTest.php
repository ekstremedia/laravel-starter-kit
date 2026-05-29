<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

it('renders roles index with permission and user counts', function () {
    $workspace = createWorkspace();
    User::factory()->count(3)->create()->each(fn ($u) => grantRoleOnWorkspace($u, 'User', $workspace));

    $this->actingAs($this->admin)
        ->get('/admin/roles')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Roles/Index')
            ->has('roles', 3)
            ->where('roles', fn ($roles) => collect($roles)->contains(fn ($r) => $r['name'] === 'User' && $r['users_count'] === 3))
        );
});

it('renders the roles create form', function () {
    $this->actingAs($this->admin)
        ->get('/admin/roles/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Roles/Edit')
            ->where('role', null)
            ->has('permissions')
        );
});

it('renders the roles edit form with current permissions preloaded', function () {
    $role = Role::findByName('Editor');

    $this->actingAs($this->admin)
        ->get("/admin/roles/{$role->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Roles/Edit')
            ->where('role.name', 'Editor')
            ->has('role.permissions')
        );
});

it('rejects duplicate role names on create', function () {
    $this->actingAs($this->admin)
        ->post('/admin/roles', ['name' => 'Admin'])
        ->assertSessionHasErrors('name');
});
