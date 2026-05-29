<?php

declare(strict_types=1);

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Support\WorkspaceMembership;
use Database\Seeders\RoleAndPermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->workspace = createWorkspace();
    $this->admin = User::factory()->create();
    grantRoleOnWorkspace($this->admin, 'Admin', $this->workspace);
});

it('lets a workspace-Admin view the members index for their workspace', function () {
    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, '/members'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Workspace/Members/Index')
            ->has('members')
            ->where('assignable_roles', ['Admin', 'Editor', 'User'])
        );
});

it('forbids a workspace-Admin from accessing a workspace they are not an Admin on', function () {
    $other = createWorkspace('other', 'Other');

    $this->actingAs($this->admin)
        ->get(workspaceUrl($other, '/members'))
        ->assertForbidden();
});

it('forbids regular members from the members page', function () {
    $regular = User::factory()->create();
    grantRoleOnWorkspace($regular, 'User', $this->workspace);

    $this->actingAs($regular)
        ->get(workspaceUrl($this->workspace, '/members'))
        ->assertForbidden();
});

it('allows a platform SuperAdmin to access any workspace members page', function () {
    $super = makeSuperAdmin(User::factory()->create());
    $other = createWorkspace('isolated', 'Isolated');

    $this->actingAs($super)
        ->get(workspaceUrl($other, '/members'))
        ->assertOk();
});

it('invites an existing user with a role', function () {
    $newUser = User::factory()->create(['email' => 'friend@example.test']);

    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/members'), [
            'email' => 'friend@example.test',
            'roles' => ['Editor'],
        ])
        ->assertRedirect();

    expect($newUser->fresh()->belongsToWorkspace($this->workspace))->toBeTrue();

    // `rolesOn` saves/restores the PermissionRegistrar team id internally,
    // so we don't leak the workspace's team id into whichever test runs next
    // (the singleton persists across cases in the same process).
    expect(WorkspaceMembership::rolesOn($newUser->fresh(), $this->workspace))->toContain('Editor');
});

it('requires a role when inviting', function () {
    User::factory()->create(['email' => 'noRole@example.test']);

    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/members'), [
            'email' => 'noRole@example.test',
        ])
        ->assertSessionHasErrors('roles');
});

it('changes a member role on the workspace', function () {
    $member = User::factory()->create();
    grantRoleOnWorkspace($member, 'User', $this->workspace);

    $this->actingAs($this->admin)
        ->patch(workspaceUrl($this->workspace, "/members/{$member->id}/role"), [
            'roles' => ['Editor'],
        ])
        ->assertRedirect();

    expect(WorkspaceMembership::roleOn($member->fresh(), $this->workspace))->toBe('Editor');
});

it('prevents demoting the last workspace-Admin', function () {
    // $this->admin is the only Admin on this workspace. The controller redirects
    // back with a flashed error rather than mutating the role.
    $this->actingAs($this->admin)
        ->patch(workspaceUrl($this->workspace, "/members/{$this->admin->id}/role"), [
            'roles' => ['Editor'],
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(WorkspaceMembership::roleOn($this->admin->fresh(), $this->workspace))->toBe('Admin');
});

it('allows demoting when another workspace-Admin exists', function () {
    $second = User::factory()->create();
    grantRoleOnWorkspace($second, 'Admin', $this->workspace);

    $this->actingAs($this->admin)
        ->patch(workspaceUrl($this->workspace, "/members/{$this->admin->id}/role"), [
            'roles' => ['Editor'],
        ])
        ->assertRedirect();

    expect(WorkspaceMembership::roleOn($this->admin->fresh(), $this->workspace))->toBe('Editor');
});

it('removes a member', function () {
    $member = User::factory()->create();
    grantRoleOnWorkspace($member, 'User', $this->workspace);

    $this->actingAs($this->admin)
        ->delete(workspaceUrl($this->workspace, "/members/{$member->id}"))
        ->assertRedirect();

    expect($member->fresh()->belongsToWorkspace($this->workspace))->toBeFalse();
    expect(WorkspaceMembership::roleOn($member->fresh(), $this->workspace))->toBeNull();
});

it('prevents removing the last workspace-Admin', function () {
    $this->actingAs($this->admin)
        ->delete(workspaceUrl($this->workspace, "/members/{$this->admin->id}"))
        ->assertRedirect()
        ->assertSessionHas('error');

    // Both the membership pivot AND the Admin role assignment must survive —
    // `WorkspaceMembership::detach` wipes roles, so if the controller ever
    // skipped the early `return` but still called detach, the membership
    // alone could look preserved while the role was silently stripped.
    expect($this->admin->fresh()->belongsToWorkspace($this->workspace))->toBeTrue();
    expect(WorkspaceMembership::roleOn($this->admin->fresh(), $this->workspace))->toBe('Admin');
});
