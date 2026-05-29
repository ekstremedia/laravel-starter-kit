<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->workspace = createWorkspace();
});

it('redirects guests to login', function () {
    $this->get(workspaceUrl($this->workspace, '/dashboard'))->assertRedirect('/login');
});

it('redirects unverified users to verification notice', function () {
    $user = User::factory()->unverified()->create();
    joinWorkspace($user, $this->workspace);

    $this->actingAs($user)
        ->get(workspaceUrl($this->workspace, '/dashboard'))
        ->assertRedirect('/email/verify');
});

it('renders the dashboard for verified users', function () {
    $user = User::factory()->create();
    joinWorkspace($user, $this->workspace);

    $this->actingAs($user)
        ->get(workspaceUrl($this->workspace, '/dashboard'))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('auth.user.id', $user->id)
            ->where('auth.user.roles', ['User'])
            ->has('auth.user.email_verified_at')
            ->has('auth.user.created_at')
        );
});

it('403s for users not a member of the workspace', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(workspaceUrl($this->workspace, '/dashboard'))
        ->assertForbidden();
});
