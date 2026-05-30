<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('redirects unauthenticated users to login', function () {
    $this->get('/home')->assertRedirect('/login');
});

it('redirects unverified users to the verification notice', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/home')->assertRedirect('/email/verify');
});

it('renders the Home Inertia page with the signed-in user detail', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/home')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->has('userDetail', fn (Assert $ud) => $ud
                ->where('first_name', $user->first_name)
                ->has('workspace_roles')
                ->where('is_super_admin', false)
                ->has('created_at')
            )
        );
});
