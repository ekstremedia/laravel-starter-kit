<?php

use App\Domains\Notifications\Notifications\ResetPasswordNotification as ResetPassword;
use App\Domains\Users\Models\User;
use Illuminate\Support\Facades\Notification;

it('shows the forgot password page', function () {
    $this->get('/forgot-password')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->component('Auth/ForgotPassword'));
});

it('sends a password reset link', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'test@example.com']);

    $this->post('/forgot-password', ['email' => 'test@example.com'])
        ->assertRedirect();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('validates email for password reset request', function () {
    $this->post('/forgot-password', ['email' => ''])
        ->assertSessionHasErrors('email');
});

it('shows the reset password page', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'test@example.com']);

    $this->post('/forgot-password', ['email' => 'test@example.com']);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $this->get('/reset-password/'.$notification->token.'?email=test@example.com')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Auth/ResetPassword')
                ->where('email', 'test@example.com')
                ->has('token')
            );

        return true;
    });
});

it('resets the password with a valid token', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'test@example.com']);

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect('/login');

        return true;
    });
});

it('requires password confirmation for reset', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'test@example.com']);

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'different-password',
        ])->assertSessionHasErrors('password');

        return true;
    });
});

it('redirects authenticated users away from forgot password page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/forgot-password')
        ->assertRedirect('/app');
});
