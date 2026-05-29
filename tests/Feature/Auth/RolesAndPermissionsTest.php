<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('creates the workspace-scoped Admin role template', function () {
    expect(Role::where('name', 'Admin')->exists())->toBeTrue();
});

it('creates the User role template', function () {
    expect(Role::where('name', 'User')->exists())->toBeTrue();
});

it('creates expected workspace-scoped permissions', function () {
    $expected = [
        'view dashboard',
        'manage workspace users',
        'manage workspace settings',
        'manage profile',
        'upload files',
    ];

    foreach ($expected as $permission) {
        expect(Permission::where('name', $permission)->exists())->toBeTrue();
    }
});

it('gives the workspace-scoped Admin role every workspace permission', function () {
    $adminRole = Role::findByName('Admin');
    $allWorkspacePermissions = Permission::all();

    expect($adminRole->permissions->count())->toBe($allWorkspacePermissions->count());
});

it('promotes a user to platform SuperAdmin via the is_super_admin column', function () {
    $user = makeSuperAdmin(User::factory()->create());

    expect($user->isSuperAdmin())->toBeTrue();
});

it('assigns the Admin role on a specific workspace', function () {
    $workspace = createWorkspace();
    $user = User::factory()->create();

    grantRoleOnWorkspace($user, 'Admin', $workspace);

    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
    expect($user->hasRole('Admin'))->toBeTrue();
    expect($user->can('manage workspace users'))->toBeTrue();
});
