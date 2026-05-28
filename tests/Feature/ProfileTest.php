<?php

use App\Domains\Notifications\Notifications\VerifyEmailNotification as VerifyEmail;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->customer = createCustomer();
});

it('redirects guests to login', function () {
    $this->get(customerUrl($this->customer, '/profile'))->assertRedirect('/login');
});

it('redirects unverified users to verification notice', function () {
    $user = User::factory()->unverified()->create();
    joinCustomer($user, $this->customer);

    $this->actingAs($user)
        ->get(customerUrl($this->customer, '/profile'))
        ->assertRedirect('/email/verify');
});

it('renders the profile page for verified users', function () {
    $user = User::factory()->create();
    joinCustomer($user, $this->customer);

    $this->actingAs($user)
        ->get(customerUrl($this->customer, '/profile'))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->component('Profile'));
});

it('updates profile information via fortify', function () {
    $user = User::factory()->create([
        'first_name' => 'Old',
        'last_name' => 'Name',
        'email' => 'old@example.com',
    ]);

    $this->actingAs($user)
        ->put('/user/profile-information', [
            'first_name' => 'New',
            'last_name' => 'Person',
            'email' => 'new@example.com',
        ])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->first_name)->toBe('New');
    expect($user->last_name)->toBe('Person');
    expect($user->email)->toBe('new@example.com');
});

it('clears email_verified_at and sends verification email when email changes', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'old@example.com',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->put('/user/profile-information', [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => 'new@example.com',
        ])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('updates password via fortify with correct current password', function () {
    $user = User::factory()->create([
        'password' => bcrypt('current-password'),
    ]);

    $this->actingAs($user)
        ->put('/user/password', [
            'current_password' => 'current-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
});

it('rejects password update with incorrect current password', function () {
    $user = User::factory()->create([
        'password' => bcrypt('current-password'),
    ]);

    $this->actingAs($user)
        ->put('/user/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
        ->assertSessionHasErrors('current_password');

    expect(Hash::check('current-password', $user->fresh()->password))->toBeTrue();
});
