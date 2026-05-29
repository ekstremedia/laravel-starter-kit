<?php

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Models\WorkspaceInvitation;
use Database\Seeders\RoleAndPermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    // Default for these tests: multi-tenant, self-serve registration.
    config()->set('workspaces.enabled', true);
    config()->set('workspaces.registration_mode', 'create_own');
});

it('shows the prefilled create-workspace form to a create_own user with no workspace', function () {
    $user = User::factory()->create(['first_name' => 'Ada']);

    $this->actingAs($user)
        ->get(route('workspaces.onboarding.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Workspaces/Create')
            ->where('suggestedName', "Ada's space"));
});

it('redirects away from onboarding when registration mode is join_default', function () {
    config()->set('workspaces.registration_mode', 'join_default');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('workspaces.onboarding.show'))
        ->assertRedirect(route('app.landing'));
});

it('redirects away from onboarding when workspaces are disabled', function () {
    config()->set('workspaces.enabled', false);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('workspaces.onboarding.show'))
        ->assertRedirect(route('app.landing'));
});

it('creates a workspace and makes the submitter its admin', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('workspaces.onboarding.store'), ['name' => 'Garage HQ']);

    $workspace = Workspace::where('name', 'Garage HQ')->first();
    expect($workspace)->not->toBeNull();
    $response->assertRedirect(route('workspace.dashboard', ['workspace' => $workspace->slug]));

    expect($user->workspaces()->whereKey($workspace->id)->exists())->toBeTrue();

    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
    $user->unsetRelation('roles');
    expect($user->hasRole('Admin'))->toBeTrue();
});

it('requires a name to create a workspace', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('workspaces.onboarding.store'), ['name' => ''])
        ->assertSessionHasErrors('name');

    expect(Workspace::query()->count())->toBe(0);
});

it('routes a create_own user with no workspace to onboarding from the landing page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/app')
        ->assertRedirect(route('workspaces.onboarding.show'));
});

it('does not send an invited user to onboarding — they join the inviting workspace', function () {
    $workspace = createWorkspace('acme', 'Acme');
    $user = User::factory()->create(['email' => 'invitee@example.test']);

    $invitation = WorkspaceInvitation::factory()->create([
        'workspace_id' => $workspace->id,
        'email' => 'invitee@example.test',
        'role' => 'User',
    ]);

    $this->actingAs($user)
        ->withSession(['workspace_invitation_token' => $invitation->token])
        ->get('/app')
        ->assertRedirect(route('workspace.dashboard', ['workspace' => $workspace->slug]));

    expect($user->fresh()->workspaces()->whereKey($workspace->id)->exists())->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('does not force a super admin with no membership into onboarding', function () {
    $admin = makeSuperAdmin(User::factory()->create());

    // Super admins are excluded from the onboarding redirect; with no active
    // workspaces they fall through to the picker, not the create form.
    $this->actingAs($admin)
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Workspaces/Picker'));
});
