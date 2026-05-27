<?php

use App\Domains\Users\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

it('shows verification notice to unverified users', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get('/email/verify')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->component('Auth/VerifyEmail'));
});

it('redirects verified users away from verification notice', function () {
    $user = User::factory()->create(); // verified by default

    $this->actingAs($user)
        ->get('/email/verify')
        ->assertRedirect('/app');
});

it('verifies email with valid signed url', function () {
    Event::fake();

    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
    );

    $this->actingAs($user)
        ->get($verificationUrl)
        ->assertRedirect('/app?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
});

it('rejects invalid verification hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => 'invalid-hash'],
    );

    $this->actingAs($user)
        ->get($verificationUrl)
        ->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('can resend verification email', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post('/email/verification-notification')
        ->assertRedirect();
});

it('requires authentication for verification routes', function () {
    $this->get('/email/verify')->assertRedirect('/login');
    $this->post('/email/verification-notification')->assertRedirect('/login');
});
