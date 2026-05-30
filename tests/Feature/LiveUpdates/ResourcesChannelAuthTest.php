<?php

declare(strict_types=1);

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * Resolve a registered broadcast channel authorization callback by name,
 * mirroring the approach in BroadcastChannelTest.
 */
function registeredChannel(string $name): ?Closure
{
    $broadcaster = app('Illuminate\Contracts\Broadcasting\Broadcaster');
    $reflection = new ReflectionClass($broadcaster);
    $prop = $reflection->getProperty('channels');
    $prop->setAccessible(true);

    return $prop->getValue($broadcaster)[$name] ?? null;
}

it('authorizes only super admins on admin.resources', function () {
    $admin = makeSuperAdmin(User::factory()->create());
    $user = User::factory()->create();

    $callback = registeredChannel('admin.resources');
    expect($callback)->not->toBeNull();

    expect($callback($admin))->toBeTrue();
    expect($callback($user))->toBeFalse();
    expect($callback(null))->toBeFalse();
});

it('authorizes members and super admins on workspace.{id}.resources', function () {
    $workspace = createWorkspace();
    $member = User::factory()->create();
    joinWorkspace($member, $workspace, 'User');

    $stranger = User::factory()->create();
    $admin = makeSuperAdmin(User::factory()->create());

    $callback = registeredChannel('workspace.{workspaceId}.resources');
    expect($callback)->not->toBeNull();

    expect($callback($member, $workspace->id))->toBeTrue();
    expect($callback($admin, $workspace->id))->toBeTrue();
    expect($callback($stranger, $workspace->id))->toBeFalse();
    expect($callback(null, $workspace->id))->toBeFalse();
});
