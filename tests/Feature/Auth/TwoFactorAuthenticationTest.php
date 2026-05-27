<?php

use App\Domains\Users\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }
});

it('can enable two-factor authentication', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/user/confirm-password', ['password' => 'password'])
        ->assertRedirect();

    $this->actingAs($user)
        ->postJson('/user/two-factor-authentication')
        ->assertOk();

    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

it('can retrieve the QR code after enabling 2FA', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/user/confirm-password', ['password' => 'password']);

    $this->actingAs($user)
        ->postJson('/user/two-factor-authentication');

    $this->actingAs($user)
        ->get('/user/two-factor-qr-code')
        ->assertOk()
        ->assertJsonStructure(['svg', 'url']);
});

it('can retrieve recovery codes after enabling 2FA', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/user/confirm-password', ['password' => 'password']);

    $this->actingAs($user)
        ->postJson('/user/two-factor-authentication');

    $this->actingAs($user)
        ->get('/user/two-factor-recovery-codes')
        ->assertOk()
        ->assertJsonCount(8);
});

it('can disable two-factor authentication', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/user/confirm-password', ['password' => 'password']);

    $this->actingAs($user)
        ->postJson('/user/two-factor-authentication');

    expect($user->fresh()->two_factor_secret)->not->toBeNull();

    $this->actingAs($user)
        ->deleteJson('/user/two-factor-authentication')
        ->assertOk();

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('requires authentication to manage 2FA', function () {
    $this->postJson('/user/two-factor-authentication')
        ->assertUnauthorized();
});

it('requires password confirmation before enabling 2FA', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/user/two-factor-authentication')
        ->assertStatus(423);
});

it('rejects invalid TOTP code when confirming 2FA', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/user/confirm-password', ['password' => 'password']);

    $this->actingAs($user)
        ->postJson('/user/two-factor-authentication');

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();

    $this->actingAs($user)
        ->postJson('/user/confirmed-two-factor-authentication', ['code' => '000000'])
        ->assertUnprocessable();

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});
