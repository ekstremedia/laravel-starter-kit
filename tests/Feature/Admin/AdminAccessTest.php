<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

$adminRoutes = [
    ['GET', '/admin'],
    ['GET', '/admin/users'],
    ['GET', '/admin/users/create'],
    ['GET', '/admin/roles'],
    ['GET', '/admin/permissions'],
    ['GET', '/admin/monitoring'],
    ['GET', '/admin/mail'],
    ['GET', '/admin/system'],
];

it('redirects guests from admin routes to login', function (string $method, string $uri) {
    $this->call($method, $uri)->assertRedirect('/login');
})->with($adminRoutes);

it('forbids non-admins from admin routes', function (string $method, string $uri) {
    $user = User::factory()->create();

    $this->actingAs($user)->call($method, $uri)->assertForbidden();
})->with($adminRoutes);

it('allows admins to access admin routes', function (string $method, string $uri) {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $this->actingAs($admin)->call($method, $uri)->assertOk();
})->with($adminRoutes);
