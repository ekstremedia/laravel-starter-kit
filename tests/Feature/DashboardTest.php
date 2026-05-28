<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->customer = createCustomer();
});

it('redirects guests to login', function () {
    $this->get(customerUrl($this->customer, '/dashboard'))->assertRedirect('/login');
});

it('redirects unverified users to verification notice', function () {
    $user = User::factory()->unverified()->create();
    joinCustomer($user, $this->customer);

    $this->actingAs($user)
        ->get(customerUrl($this->customer, '/dashboard'))
        ->assertRedirect('/email/verify');
});

it('renders the dashboard for verified users', function () {
    $user = User::factory()->create();
    joinCustomer($user, $this->customer);

    $this->actingAs($user)
        ->get(customerUrl($this->customer, '/dashboard'))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('auth.user.id', $user->id)
            ->where('auth.user.roles', ['User'])
            ->has('auth.user.email_verified_at')
            ->has('auth.user.created_at')
        );
});

it('403s for users not a member of the customer', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(customerUrl($this->customer, '/dashboard'))
        ->assertForbidden();
});
