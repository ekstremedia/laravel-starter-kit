<?php

declare(strict_types=1);

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(fn () => $this->seed(RoleAndPermissionSeeder::class));

// PermissionRegistrar is a container singleton; RefreshDatabase doesn't touch
// it, so whichever team id the previous test left behind leaks into the next.
// Zero it out before every case so any scoping assertion proves the SUT set
// the team id — not carry-over from earlier.
afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('reads SuperAdmin purely from the is_super_admin column', function () {
    $user = User::factory()->create();
    expect($user->isSuperAdmin())->toBeFalse();

    $user->forceFill(['is_super_admin' => true])->save();
    expect($user->fresh()->isSuperAdmin())->toBeTrue();
});

it('SuperAdmin check does not depend on current team context', function () {
    $super = makeSuperAdmin(User::factory()->create());
    $workspace = createWorkspace();

    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
    expect($super->isSuperAdmin())->toBeTrue();

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    expect($super->isSuperAdmin())->toBeTrue();
});

it('the same user can hold different roles on different workspaces', function () {
    $user = User::factory()->create();
    $a = createWorkspace('a', 'A');
    $b = createWorkspace('b', 'B');

    grantRoleOnWorkspace($user, 'Admin', $a);
    grantRoleOnWorkspace($user, 'User', $b);

    $registrar = app(PermissionRegistrar::class);

    $registrar->setPermissionsTeamId($a->id);
    expect($user->fresh()->hasRole('Admin'))->toBeTrue();
    expect($user->fresh()->hasRole('User'))->toBeFalse();

    $registrar->setPermissionsTeamId($b->id);
    expect($user->fresh()->hasRole('User'))->toBeTrue();
    expect($user->fresh()->hasRole('Admin'))->toBeFalse();
});

it('ResolveWorkspace scopes the permission team id to the active workspace during the request', function () {
    $workspace = createWorkspace();
    $user = User::factory()->create();
    grantRoleOnWorkspace($user, 'User', $workspace);

    // Inertia's shared props resolve against the team id set by the
    // middleware — `roles: ['User']` on the response proves the team
    // context was active while rendering. After the request, the middleware
    // restores the previous team id (null here) to prevent leakage into
    // subsequent requests on the same worker.
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($user)
        ->get(workspaceUrl($workspace, '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user.roles', ['User']));

    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
});

it('lets SuperAdmin enter any workspace regardless of membership', function () {
    $super = makeSuperAdmin(User::factory()->create());
    $workspace = createWorkspace();

    // No membership on the workspace; the SuperAdmin flag alone should bypass
    // the ResolveWorkspace membership guard.
    $this->actingAs($super)
        ->get(workspaceUrl($workspace, '/dashboard'))
        ->assertOk();
});

it('blocks a non-SuperAdmin from a workspace they are not a member of', function () {
    $outsider = User::factory()->create();
    $workspace = createWorkspace();

    $this->actingAs($outsider)
        ->get(workspaceUrl($workspace, '/dashboard'))
        ->assertForbidden();
});

it('shares is_super_admin as a prop on workspace-scoped pages', function () {
    $super = makeSuperAdmin(User::factory()->create());
    $workspace = createWorkspace();

    $this->actingAs($super)
        ->get(workspaceUrl($workspace, '/dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.user.is_super_admin', true));
});
