<?php

use App\Domains\Notifications\Notifications\WorkspaceInvitationNotification;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\WorkspaceInvitation;
use App\Domains\Workspaces\Support\WorkspaceMembership;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->workspace = createWorkspace();
});

function makeInvitation($workspace, string $email, array $overrides = []): WorkspaceInvitation
{
    return WorkspaceInvitation::create(array_merge([
        'workspace_id' => $workspace->id,
        'email' => $email,
        'role' => 'User',
        'token' => WorkspaceInvitation::freshToken(),
        'expires_at' => now()->addDays(7),
    ], $overrides));
}

it('lets a workspace admin invite by email and emails the invitee', function () {
    Notification::fake();
    $admin = User::factory()->create();
    grantRoleOnWorkspace($admin, 'Admin', $this->workspace);

    $this->actingAs($admin)
        ->post(workspaceUrl($this->workspace, '/members/invitations'), ['email' => 'New@Example.com', 'role' => 'Editor'])
        ->assertRedirect();

    $invitation = WorkspaceInvitation::query()->where('email', 'new@example.com')->first();
    expect($invitation)->not->toBeNull();
    expect($invitation->workspace_id)->toBe($this->workspace->id);
    expect($invitation->role)->toBe('Editor');

    Notification::assertSentOnDemand(WorkspaceInvitationNotification::class);
});

it('forbids a non-admin from inviting', function () {
    $member = User::factory()->create();
    joinWorkspace($member, $this->workspace, 'User');

    $this->actingAs($member)
        ->post(workspaceUrl($this->workspace, '/members/invitations'), ['email' => 'x@example.com', 'role' => 'User'])
        ->assertForbidden();
});

it('rejects inviting someone who is already a member', function () {
    $admin = User::factory()->create();
    grantRoleOnWorkspace($admin, 'Admin', $this->workspace);
    $member = User::factory()->create(['email' => 'member@example.com']);
    joinWorkspace($member, $this->workspace, 'User');

    $this->actingAs($admin)
        ->post(workspaceUrl($this->workspace, '/members/invitations'), ['email' => 'member@example.com', 'role' => 'User'])
        ->assertSessionHas('error');

    expect(WorkspaceInvitation::query()->where('email', 'member@example.com')->exists())->toBeFalse();
});

it('lets a logged-in invitee accept and join with the invited role', function () {
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = makeInvitation($this->workspace, 'invitee@example.com', ['role' => 'Editor']);

    $this->actingAs($invitee)->get('/invitations/'.$invitation->token)->assertRedirect();

    expect($invitee->fresh()->belongsToWorkspace($this->workspace))->toBeTrue();
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
    expect(WorkspaceMembership::rolesOn($invitee, $this->workspace))->toContain('Editor');
});

it('sends a guest to register and remembers the invitation', function () {
    $invitation = makeInvitation($this->workspace, 'guest@example.com');

    $this->get('/invitations/'.$invitation->token)
        ->assertRedirect(route('register', ['email' => 'guest@example.com']));

    expect(session('workspace_invitation_token'))->toBe($invitation->token);
});

it('finishes a pending invitation at the landing page after auth', function () {
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = makeInvitation($this->workspace, 'invitee@example.com');

    $this->actingAs($invitee)
        ->withSession(['workspace_invitation_token' => $invitation->token])
        ->get('/app')
        ->assertRedirect();

    expect($invitee->fresh()->belongsToWorkspace($this->workspace))->toBeTrue();
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('rejects accepting with a mismatched account', function () {
    $other = User::factory()->create(['email' => 'other@example.com']);
    $invitation = makeInvitation($this->workspace, 'invited@example.com');

    $this->actingAs($other)->get('/invitations/'.$invitation->token)->assertRedirect(route('app.landing'));

    expect($other->fresh()->belongsToWorkspace($this->workspace))->toBeFalse();
    expect($invitation->fresh()->accepted_at)->toBeNull();
});

it('rejects an expired invitation', function () {
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = makeInvitation($this->workspace, 'invitee@example.com', ['expires_at' => now()->subDay()]);

    $this->actingAs($invitee)->get('/invitations/'.$invitation->token)->assertRedirect(route('app.landing'));

    expect($invitee->fresh()->belongsToWorkspace($this->workspace))->toBeFalse();
});

it('lets an admin revoke a pending invitation', function () {
    $admin = User::factory()->create();
    grantRoleOnWorkspace($admin, 'Admin', $this->workspace);
    $invitation = makeInvitation($this->workspace, 'revoke@example.com');

    $this->actingAs($admin)
        ->delete(workspaceUrl($this->workspace, '/members/invitations/'.$invitation->id))
        ->assertRedirect();

    expect(WorkspaceInvitation::query()->find($invitation->id))->toBeNull();
});
