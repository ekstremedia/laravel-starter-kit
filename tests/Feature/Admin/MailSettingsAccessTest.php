<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('renders the mail settings page for admins', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $this->actingAs($admin)
        ->get('/admin/mail')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Mail')
            ->has('settings.mailer')
            ->has('settings.from_address')
            ->where('settings.has_password', false)
            ->missing('settings.password')
        );
});

it('exposes has_password=true after saving a password', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $this->actingAs($admin)->patch('/admin/mail', [
        'mailer' => 'smtp',
        'host' => 'h',
        'port' => 25,
        'encryption' => null,
        'username' => 'u',
        'password' => 'secret-xyz',
        'from_address' => 'a@b.test',
        'from_name' => 'T',
        'enabled' => true,
    ]);

    $this->actingAs($admin)
        ->get('/admin/mail')
        ->assertInertia(fn ($page) => $page
            ->where('settings.has_password', true)
            ->missing('settings.password')
        );
});

it('rejects invalid mail settings payloads', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $this->actingAs($admin)
        ->patch('/admin/mail', [
            'mailer' => '',
            'port' => 999999,
            'from_address' => 'not-an-email',
        ])
        ->assertSessionHasErrors(['mailer', 'port', 'from_address']);
});
