<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

it('renders the permissions index', function () {
    $this->actingAs($this->admin)
        ->get('/admin/permissions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Permissions/Index')
            ->has('permissions')
        );
});

it('rejects duplicate permission names', function () {
    Permission::create(['name' => 'unique.thing']);

    $this->actingAs($this->admin)
        ->post('/admin/permissions', ['name' => 'unique.thing'])
        ->assertSessionHasErrors('name');
});

it('validates permission name is required', function () {
    $this->actingAs($this->admin)
        ->post('/admin/permissions', [])
        ->assertSessionHasErrors('name');
});
