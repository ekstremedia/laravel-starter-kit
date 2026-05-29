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

it('renders the Home Inertia page with userDetail and activity', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/home')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->has('userDetail', fn (Assert $ud) => $ud
                ->where('id', $user->id)
                ->where('email', $user->email)
                ->where('first_name', $user->first_name)
                ->where('last_name', $user->last_name)
                ->has('email_verified_at')
                ->where('two_factor_enabled', false)
                ->has('workspace_roles')
                ->where('is_super_admin', false)
                ->has('created_at')
            )
            ->has('activity')
        );
});

it('caps the activity list at 10 entries', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 15; $i++) {
        activity()->causedBy($user)->performedOn($user)->log("event {$i}");
    }

    $this->actingAs($user)
        ->get('/home')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->has('activity', 10)
        );
});
