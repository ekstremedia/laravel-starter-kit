<?php

declare(strict_types=1);

use App\Domains\Users\Models\User;

// The `admin.health` private broadcast channel in routes/channels.php gates
// on `$user->isSuperAdmin()` — these two tests lock that contract in so a
// future refactor of `isSuperAdmin` or a stray `hasRole('Admin')` regression
// can't silently open the channel to workspace-level admins.

// Laravel's `/broadcasting/auth` returns a proper 403 only when called with
// JSON headers (otherwise it falls through to the HTML renderer). Use
// `postJson` so both the success and reject paths get the right status.

it('allows a SuperAdmin to authenticate the admin.health channel', function () {
    $super = User::factory()->superAdmin()->create();

    $this->actingAs($super)->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-admin.health',
    ])->assertOk();
});

it('rejects a non-SuperAdmin from the admin.health channel', function () {
    $user = User::factory()->create(); // default is_super_admin=false

    $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-admin.health',
    ]);

    // Different Laravel broadcaster configurations respond to rejected
    // channel auth differently — a Pusher-style driver returns 403, the
    // null/log driver short-circuits with 200 and an empty body. Either
    // way the user MUST NOT receive a signed `auth` token for the channel.
    expect($response->status())->toBeIn([403, 200]);
    expect((string) $response->getContent())->not->toContain('"auth":');
});
