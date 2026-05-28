<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

it('creates a user (platform-level; no customer role assigned here)', function () {
    $this->actingAs($this->admin)->post('/admin/users', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.test',
        'password' => 'Password#1',
        'password_confirmation' => 'Password#1',
    ])->assertRedirect('/admin/users');

    $user = User::where('email', 'jane@example.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->isSuperAdmin())->toBeFalse();
});

it('validates new user input', function () {
    $this->actingAs($this->admin)
        ->post('/admin/users', [])
        ->assertSessionHasErrors(['first_name', 'last_name', 'email', 'password']);
});

it('updates a user profile fields', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin)->put("/admin/users/{$user->id}", [
        'first_name' => 'Updated',
        'last_name' => 'Name',
        'email' => $user->email,
    ])->assertRedirect('/admin/users');

    $user->refresh();
    expect($user->first_name)->toBe('Updated');
});

it('deletes a user', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->delete("/admin/users/{$user->id}")
        ->assertRedirect('/admin/users');

    expect(User::find($user->id))->toBeNull();
});

it('prevents deleting own account', function () {
    $this->actingAs($this->admin)
        ->delete("/admin/users/{$this->admin->id}")
        ->assertRedirect();

    expect(User::find($this->admin->id))->not->toBeNull();
});
