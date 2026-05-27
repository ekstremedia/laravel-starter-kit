<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('creates the customer-scoped Admin role template', function () {
    expect(Role::where('name', 'Admin')->exists())->toBeTrue();
});

it('creates the User role template', function () {
    expect(Role::where('name', 'User')->exists())->toBeTrue();
});

it('creates expected customer-scoped permissions', function () {
    $expected = [
        'view dashboard',
        'manage customer users',
        'manage customer settings',
        'manage profile',
        'upload files',
    ];

    foreach ($expected as $permission) {
        expect(Permission::where('name', $permission)->exists())->toBeTrue();
    }
});

it('gives the customer-scoped Admin role every customer permission', function () {
    $adminRole = Role::findByName('Admin');
    $allCustomerPermissions = Permission::all();

    expect($adminRole->permissions->count())->toBe($allCustomerPermissions->count());
});

it('promotes a user to platform SuperAdmin via the is_super_admin column', function () {
    $user = makeSuperAdmin(User::factory()->create());

    expect($user->isSuperAdmin())->toBeTrue();
});

it('assigns the Admin role on a specific customer', function () {
    $customer = createCustomer();
    $user = User::factory()->create();

    grantRoleOnCustomer($user, 'Admin', $customer);

    app(PermissionRegistrar::class)->setPermissionsTeamId($customer->id);
    expect($user->hasRole('Admin'))->toBeTrue();
    expect($user->can('manage customer users'))->toBeTrue();
});
