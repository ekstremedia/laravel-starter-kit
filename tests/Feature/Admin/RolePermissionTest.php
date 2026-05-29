<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

it('creates a role with permissions', function () {
    $this->actingAs($this->admin)->post('/admin/roles', [
        'name' => 'Moderator',
        'permissions' => ['manage workspace users', 'view dashboard'],
    ])->assertRedirect('/admin/roles');

    $role = Role::findByName('Moderator');
    expect($role->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(['manage workspace users', 'view dashboard']);
});

it('updates role permissions', function () {
    $role = Role::findByName('Editor');

    $this->actingAs($this->admin)->put("/admin/roles/{$role->id}", [
        'name' => 'Editor',
        'permissions' => ['view dashboard'],
    ])->assertRedirect('/admin/roles');

    expect($role->fresh()->permissions->pluck('name')->all())->toBe(['view dashboard']);
});

it('creates a permission', function () {
    $this->actingAs($this->admin)->post('/admin/permissions', [
        'name' => 'do thing',
    ])->assertRedirect();

    expect(Permission::where('name', 'do thing')->exists())->toBeTrue();
});

it('deletes a permission', function () {
    $perm = Permission::create(['name' => 'temp perm']);

    $this->actingAs($this->admin)
        ->delete("/admin/permissions/{$perm->id}")
        ->assertRedirect();

    expect(Permission::find($perm->id))->toBeNull();
});
