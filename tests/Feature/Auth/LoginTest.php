<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    config()->set('dev.easy_login_enabled', false);
});

it('shows the login page', function () {
    $this->get('/login')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->where('debug.easy_login_enabled', false)
        );
});

it('logs in a verified user', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    $this->post('/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
    ])->assertRedirect('/app');

    $this->assertAuthenticatedAs($user);
});

it('redirects unverified user to verification page after login', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'unverified@example.com',
        'password' => bcrypt('password123'),
    ]);

    $this->post('/login', [
        'email' => 'unverified@example.com',
        'password' => 'password123',
    ])->assertRedirect('/email/verify');

    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials', function () {
    User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
    ]);

    $this->post('/login', [
        'email' => 'test@example.com',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('requires email to login', function () {
    $this->post('/login', [
        'email' => '',
        'password' => 'password123',
    ])->assertSessionHasErrors('email');
});

it('requires password to login', function () {
    $this->post('/login', [
        'email' => 'test@example.com',
        'password' => '',
    ])->assertSessionHasErrors('password');
});

it('logs out a user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect('/');

    $this->assertGuest();
});

it('redirects authenticated users away from login page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/login')
        ->assertRedirect('/app');
});

it('shows easy login on the login page when enabled', function () {
    config()->set('dev.easy_login_enabled', true);

    $this->get('/login')
        ->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->where('debug.easy_login_enabled', true)
        );
});

it('logs in user one with easy login when enabled', function () {
    config()->set('dev.easy_login_enabled', true);

    $user = User::factory()->create([
        'id' => 1,
    ]);

    $this->post('/login/dev')
        ->assertRedirect('/app');

    $this->assertAuthenticatedAs($user);
});

it('returns not found for easy login when disabled', function () {
    User::factory()->create([
        'id' => 1,
    ]);

    $this->post('/login/dev')
        ->assertNotFound();

    $this->assertGuest();
});
