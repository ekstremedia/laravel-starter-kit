<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('keeps critical shared props on the initial load but defers available_workspaces', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/home')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            // Critical for first paint / the rail — must NOT be deferred.
            ->has('auth.user')
            // Deferred — absent from the initial payload, loaded in a follow-up.
            ->missing('available_workspaces')
        );
});

it('lists available_workspaces among the deferred props of the initial page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/home')->assertOk();

    // Inertia exposes the full page object (props + deferredProps groups) as the
    // root view's `page` data — assert the prop is deferred, not eager.
    $page = $response->viewData('page');

    expect($page['props'])->not->toHaveKey('available_workspaces');

    $deferred = collect($page['deferredProps'] ?? [])->flatten()->all();
    expect($deferred)->toContain('available_workspaces');
});

it('encrypts Inertia history by default for logout safety', function () {
    expect(config('inertia.history.encrypt'))->toBeTrue();
});
