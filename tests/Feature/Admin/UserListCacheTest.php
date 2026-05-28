<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    Cache::flush();

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

it('caches the admin users index payload under a versioned key', function () {
    // Warm the cache.
    $this->actingAs($this->admin)->get('/admin/users')->assertOk();

    $version = User::usersListVersion();
    $key = User::USERS_LIST_CACHE_KEY.':v'.$version.':'.md5(implode('|', ['', 'id', 'desc', 1]));

    expect(Cache::has($key))->toBeTrue();
});

it('invalidates the cache when a user is created', function () {
    $this->actingAs($this->admin)->get('/admin/users')->assertOk();
    $before = User::usersListVersion();

    User::factory()->create();

    expect(User::usersListVersion())->toBeGreaterThan($before);
});

it('invalidates the cache when a user is deleted', function () {
    $target = User::factory()->create();

    $this->actingAs($this->admin)->get('/admin/users')->assertOk();
    $before = User::usersListVersion();

    $target->delete();

    expect(User::usersListVersion())->toBeGreaterThan($before);
});

it('invalidates the cache when SuperAdmin is toggled via setRole', function () {
    $target = User::factory()->create();

    $this->actingAs($this->admin)->get('/admin/users')->assertOk();
    $before = User::usersListVersion();

    $this->actingAs($this->admin)
        ->patch("/admin/users/{$target->id}/role", ['role' => 'SuperAdmin'])
        ->assertRedirect();

    expect(User::usersListVersion())->toBeGreaterThan($before);
});

it('invalidates the cache when a user is banned', function () {
    $target = User::factory()->create();

    $this->actingAs($this->admin)->get('/admin/users')->assertOk();
    $before = User::usersListVersion();

    $target->ban('test');

    expect(User::usersListVersion())->toBeGreaterThan($before);
});
