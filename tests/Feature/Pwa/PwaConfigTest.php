<?php

declare(strict_types=1);

use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('keeps the PWA opt-in (disabled by default)', function () {
    expect(config('pwa.enabled'))->toBeFalse();
});

it('always ships installability meta but only links the manifest when enabled', function () {
    // Default (disabled): theme-color + icons present, manifest link absent.
    $this->get('/')
        ->assertOk()
        ->assertSee('name="theme-color"', false)
        ->assertSee('rel="apple-touch-icon"', false)
        ->assertDontSee('rel="manifest"', false);

    // Enabled: the manifest link appears.
    config()->set('pwa.enabled', true);
    $this->get('/')
        ->assertOk()
        ->assertSee('rel="manifest"', false);
});
