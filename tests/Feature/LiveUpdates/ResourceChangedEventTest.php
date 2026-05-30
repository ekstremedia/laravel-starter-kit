<?php

declare(strict_types=1);

use App\Support\Events\ResourceChanged;
use Illuminate\Broadcasting\PrivateChannel;

it('routes a workspace-scoped change to the workspace resources channel', function () {
    $event = new ResourceChanged('equipment', 'created', 42, 7);

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-workspace.7.resources');

    expect($event->broadcastAs())->toBe('ResourceChanged');
    expect($event->broadcastWith())->toBe(['resource' => 'equipment', 'action' => 'created', 'id' => 42]);
});

it('routes a central change to the admin resources channel', function () {
    $event = new ResourceChanged('users', 'deleted', 9);

    $channels = $event->broadcastOn();
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-admin.resources');

    // No workspace id leaks into the payload — the channel encodes the scope.
    expect($event->broadcastWith())->toBe(['resource' => 'users', 'action' => 'deleted', 'id' => 9]);
});
