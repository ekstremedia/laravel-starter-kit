<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('can attach the same user to two separate workspaces', function () {
    $acme = createWorkspace('acme', 'Acme');
    $globex = createWorkspace('globex', 'Globex');

    $user = User::factory()->create();
    joinWorkspace($user, $acme);
    joinWorkspace($user, $globex);

    expect($user->workspaces()->pluck('slug')->sort()->values()->all())
        ->toBe(['acme', 'globex']);
});

it('shows the picker when a user belongs to more than one workspace', function () {
    $acme = createWorkspace('acme');
    $globex = createWorkspace('globex');

    $user = User::factory()->create();
    joinWorkspace($user, $acme);
    joinWorkspace($user, $globex);

    $this->actingAs($user)
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Workspaces/Picker')
            ->has('workspaces', 2)
        );
});

it('redirects a user with exactly one workspace straight into it', function () {
    $only = createWorkspace('only');
    $user = User::factory()->create();
    joinWorkspace($user, $only);

    $this->actingAs($user)
        ->get('/app')
        ->assertRedirect('/w/only/dashboard');
});

it('lets a user in multiple workspaces load the dashboard under each slug', function () {
    $acme = createWorkspace('acme');
    $globex = createWorkspace('globex');

    $user = User::factory()->create();
    joinWorkspace($user, $acme);
    joinWorkspace($user, $globex);

    $this->actingAs($user)
        ->get(workspaceUrl($acme, '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('workspace.slug', 'acme')
        );

    $this->actingAs($user)
        ->get(workspaceUrl($globex, '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('workspace.slug', 'globex')
        );
});

it('403s when a member of one workspace tries to enter another', function () {
    $acme = createWorkspace('acme');
    $globex = createWorkspace('globex');

    $user = User::factory()->create();
    joinWorkspace($user, $acme);

    $this->actingAs($user)
        ->get(workspaceUrl($acme, '/dashboard'))
        ->assertOk();

    $this->actingAs($user)
        ->get(workspaceUrl($globex, '/dashboard'))
        ->assertForbidden();
});

it('removes access after detaching the membership', function () {
    $workspace = createWorkspace('acme');
    $user = User::factory()->create();
    joinWorkspace($user, $workspace);

    $this->actingAs($user)
        ->get(workspaceUrl($workspace, '/dashboard'))
        ->assertOk();

    $workspace->users()->detach($user->id);

    $this->actingAs($user)
        ->get(workspaceUrl($workspace, '/dashboard'))
        ->assertForbidden();
});

it('lets admins enter any workspace without a pivot row', function () {
    $workspace = createWorkspace('acme');

    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();
    // note: NOT attached via joinWorkspace

    expect($admin->belongsToWorkspace($workspace))->toBeFalse();

    $this->actingAs($admin)
        ->get(workspaceUrl($workspace, '/dashboard'))
        ->assertOk();
});

it('keeps the shared workspaces prop off the default payload (Inertia::optional)', function () {
    $acme = createWorkspace('acme');
    $globex = createWorkspace('globex');

    $user = User::factory()->create();
    joinWorkspace($user, $acme);
    joinWorkspace($user, $globex);

    // Normal page visit → picker receives the list as an *explicit* controller
    // prop ('workspaces'), but the shared-layer optional prop is not resolved.
    $this->actingAs($user)
        ->get('/app')
        ->assertInertia(fn ($page) => $page
            ->has('workspaces', 2)
            ->where('workspaces.0.slug', 'acme')
        );
});
