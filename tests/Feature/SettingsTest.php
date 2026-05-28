<?php

use App\Domains\Users\Models\User;
use App\Domains\Users\Models\UserSetting;

it('creates settings with defaults when user is created', function () {
    $user = User::factory()->create();
    $settings = $user->settings();

    expect($settings)->toBeInstanceOf(UserSetting::class);
    expect($settings->resolved())->toBe(UserSetting::$defaults);
});

it('returns defaults for missing keys', function () {
    $user = User::factory()->create();
    $user->settings()->merge(['locale' => 'no']);

    // dark_mode was never set, should still return default
    expect($user->settings()->resolved()['dark_mode'])->toBe(true);
    expect($user->settings()->resolved()['locale'])->toBe('no');
});

it('merges partial settings without overwriting unrelated keys', function () {
    $user = User::factory()->create();
    $user->settings()->merge(['locale' => 'no', 'dark_mode' => true]);
    $user->settings()->merge(['locale' => 'en']);

    $resolved = $user->fresh()->settings()->resolved();

    expect($resolved['locale'])->toBe('en');
    expect($resolved['dark_mode'])->toBeTrue(); // not overwritten
});

it('requires authentication to update settings', function () {
    // JSON requests from unauthenticated users get 401, not a redirect
    $this->patchJson('/settings', ['locale' => 'no'])
        ->assertUnauthorized();
});

it('authenticated user can update locale setting', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson('/settings', ['locale' => 'no'])
        ->assertOk()
        ->assertJsonPath('settings.locale', 'no');

    expect($user->fresh()->settings()->resolved()['locale'])->toBe('no');
});

it('authenticated user can update dark mode setting', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson('/settings', ['dark_mode' => true])
        ->assertOk()
        ->assertJsonPath('settings.dark_mode', true);

    expect($user->fresh()->settings()->resolved()['dark_mode'])->toBeTrue();
});

it('returns an inertia redirect for spa settings updates', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/')
        ->patch('/settings', ['locale' => 'no'], [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->assertStatus(303)
        ->assertRedirect('/');

    expect($user->fresh()->settings()->resolved()['locale'])->toBe('no');
});

it('can update multiple settings at once', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson('/settings', ['locale' => 'no', 'dark_mode' => true])
        ->assertOk()
        ->assertJsonPath('settings.locale', 'no')
        ->assertJsonPath('settings.dark_mode', true);
});

it('ignores unknown setting keys', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson('/settings', ['locale' => 'no', 'unknown_key' => 'value'])
        ->assertOk();

    $resolved = $user->fresh()->settings()->resolved();
    expect(array_key_exists('unknown_key', $resolved))->toBeFalse();
});

it('shares settings as inertia props for authenticated users', function () {
    $user = User::factory()->create();
    $user->settings()->merge(['locale' => 'no']);

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page
            ->has('user_settings')
            ->where('user_settings.locale', 'no')
        );
});

it('shares default settings as inertia props for guests', function () {
    $this->get('/')
        ->assertInertia(fn ($page) => $page
            ->has('user_settings')
            ->where('user_settings.locale', UserSetting::$defaults['locale'])
            ->where('user_settings.dark_mode', UserSetting::$defaults['dark_mode'])
        );
});

it('deletes settings when user is deleted', function () {
    $user = User::factory()->create();
    $settingId = $user->settings()->id;

    $user->delete();

    expect(UserSetting::find($settingId))->toBeNull();
});
