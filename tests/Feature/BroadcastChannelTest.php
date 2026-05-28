<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

function resolveChannel(string $name, $user)
{
    /** @var BroadcastManager $broadcaster */
    $broadcaster = app(BroadcastManager::class)->driver('pusher');

    // Fall back to the registered channels directly
    $channels = require base_path('routes/channels.php');

    // Use the broadcast manager's internal channel dispatcher
    $callbacks = (fn () => $this->channels)->call($broadcaster);

    $key = array_key_first(array_filter(
        array_keys($callbacks ?? []),
        fn ($k) => $k === $name
    ));

    if (! $key) {
        return null;
    }

    return $callbacks[$key]($user);
}

it('authorizes admins on the admin.health channel', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $channels = require base_path('routes/channels.php');

    // Grab the registered callback via reflection of the broadcaster
    $broadcaster = app('Illuminate\Contracts\Broadcasting\Broadcaster');
    $reflection = new ReflectionClass($broadcaster);
    $prop = $reflection->getProperty('channels');
    $prop->setAccessible(true);
    $registered = $prop->getValue($broadcaster);

    $callback = $registered['admin.health'] ?? null;
    expect($callback)->not->toBeNull();

    expect($callback($admin))->toBeTrue();
});

it('denies non-admins on the admin.health channel', function () {
    $user = User::factory()->create();

    $broadcaster = app('Illuminate\Contracts\Broadcasting\Broadcaster');
    $reflection = new ReflectionClass($broadcaster);
    $prop = $reflection->getProperty('channels');
    $prop->setAccessible(true);
    $registered = $prop->getValue($broadcaster);

    $callback = $registered['admin.health'] ?? null;

    expect($callback($user))->toBeFalse();
});

it('denies a null user on the admin.health channel', function () {
    $broadcaster = app('Illuminate\Contracts\Broadcasting\Broadcaster');
    $reflection = new ReflectionClass($broadcaster);
    $prop = $reflection->getProperty('channels');
    $prop->setAccessible(true);
    $registered = $prop->getValue($broadcaster);

    $callback = $registered['admin.health'] ?? null;

    expect($callback(null))->toBeFalse();
});
