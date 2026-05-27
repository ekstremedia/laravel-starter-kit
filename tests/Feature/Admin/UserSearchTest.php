<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

it('searches users by email', function () {
    User::factory()->create(['email' => 'alice@example.test']);
    User::factory()->create(['email' => 'bob@example.test']);

    $this->actingAs($this->admin)
        ->get('/admin/users?search=alice')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('users.data', fn ($rows) => collect($rows)->contains(fn ($u) => $u['email'] === 'alice@example.test')
                && ! collect($rows)->contains(fn ($u) => $u['email'] === 'bob@example.test'))
        );
});

it('searches users by first or last name', function () {
    User::factory()->create(['first_name' => 'Zelda', 'last_name' => 'Fitzgerald']);

    $this->actingAs($this->admin)
        ->get('/admin/users?search=Zelda')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('users.data', fn ($rows) => collect($rows)->contains(fn ($u) => $u['first_name'] === 'Zelda'))
        );
});

it('paginates users 15 per page', function () {
    User::factory()->count(20)->create();

    $this->actingAs($this->admin)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('users.data', fn ($rows) => collect($rows)->count() === 15)
        );
});
