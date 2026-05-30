<?php

declare(strict_types=1);

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('keeps SSR opt-in (disabled by default)', function () {
    // Guards against regressing to the "enabled by default with no bundle"
    // silent-CSR-fallback trap.
    expect(config('inertia.ssr.enabled'))->toBeFalse();
    expect(config('inertia.ssr.ensure_bundle_exists'))->toBeFalse();
});

it('still renders pages client-side with no SSR server running', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/home')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home'));
});
