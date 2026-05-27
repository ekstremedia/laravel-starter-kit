<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

it('rejects creating a user with an existing email', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs($this->admin)
        ->post('/admin/users', [
            'first_name' => 'X',
            'last_name' => 'Y',
            'email' => 'taken@example.test',
            'password' => 'Password#1',
            'password_confirmation' => 'Password#1',
        ])
        ->assertSessionHasErrors('email');
});

it('ignores a roles payload on platform user creation', function () {
    // Platform /admin/users doesn't set customer-scoped roles (those live
    // per-customer on the user Show page), so an incoming `roles` field is
    // silently dropped rather than validated. Verify the create succeeds and
    // no validation error surfaces — catching the next time someone re-adds
    // a stale validator.
    $this->actingAs($this->admin)
        ->post('/admin/users', [
            'first_name' => 'X',
            'last_name' => 'Y',
            'email' => 'new@example.test',
            'password' => 'Password#1',
            'password_confirmation' => 'Password#1',
            'roles' => ['NonExistentRole'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/users');

    expect(User::where('email', 'new@example.test')->exists())->toBeTrue();
});

it('ignores a roles payload on platform user update', function () {
    // `UpdateUserRequest` dropped its `roles` rule; verify the controller
    // still accepts (and silently drops) the field so no one re-adds a
    // stale validator and makes the PUT path diverge from the POST path.
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->put("/admin/users/{$user->id}", [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => $user->email,
            'roles' => ['NonExistentRole'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/users');

    expect($user->fresh()->first_name)->toBe('Updated');
});

it('allows updating a user without changing password when blank', function () {
    $user = User::factory()->create(['password' => bcrypt('old-pass')]);
    $hashBefore = $user->password;

    $this->actingAs($this->admin)
        ->put("/admin/users/{$user->id}", [
            'first_name' => 'Same',
            'last_name' => 'Same',
            'email' => $user->email,
        ])
        ->assertRedirect('/admin/users');

    expect($user->fresh()->password)->toBe($hashBefore);
});

it('allows the same email when editing the same user', function () {
    $user = User::factory()->create(['email' => 'stays@example.test']);

    $this->actingAs($this->admin)
        ->put("/admin/users/{$user->id}", [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => 'stays@example.test',
        ])
        ->assertRedirect('/admin/users');
});

it('forbids non-admins from creating users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/admin/users', [
            'first_name' => 'X',
            'last_name' => 'Y',
            'email' => 'new@example.test',
            'password' => 'Password#1',
            'password_confirmation' => 'Password#1',
        ])
        ->assertForbidden();
});
