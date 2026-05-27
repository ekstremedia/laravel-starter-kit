<?php

use App\Domains\Notifications\Notifications\AccountBannedNotification;
use App\Domains\Notifications\Notifications\AdminTestNotification;
use App\Domains\Notifications\Notifications\VerifyEmailNotification as VerifyEmail;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->customer = createCustomer();

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
    joinCustomer($this->admin, $this->customer);

    $this->target = User::factory()->create(['email_verified_at' => now()]);
    joinCustomer($this->target, $this->customer);
});

it('shows the user dashboard', function () {
    $this->actingAs($this->admin)
        ->get("/admin/users/{$this->target->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Users/Show')
            ->where('user.id', $this->target->id)
            ->has('activity'));
});

it('marks an unverified user as verified', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($this->admin)
        ->post("/admin/users/{$user->id}/verify")
        ->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('clears verification', function () {
    $this->actingAs($this->admin)
        ->post("/admin/users/{$this->target->id}/unverify")
        ->assertRedirect();

    expect($this->target->fresh()->email_verified_at)->toBeNull();
});

it('bans a user with a reason and notifies them', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post("/admin/users/{$this->target->id}/ban", ['reason' => 'Spamming'])
        ->assertRedirect();

    $user = $this->target->fresh();
    expect($user->isBanned())->toBeTrue()
        ->and($user->banned_reason)->toBe('Spamming');

    Notification::assertSentTo($user, AccountBannedNotification::class);
});

it('forbids banning an admin', function () {
    $other = User::factory()->create();
    $other->forceFill(['is_super_admin' => true])->save();

    $this->actingAs($this->admin)
        ->post("/admin/users/{$other->id}/ban")
        ->assertSessionHas('error');
});

it('forbids banning yourself', function () {
    $this->actingAs($this->admin)
        ->post("/admin/users/{$this->admin->id}/ban")
        ->assertSessionHas('error');
});

it('unbans a user', function () {
    $this->target->ban('nope');

    $this->actingAs($this->admin)
        ->post("/admin/users/{$this->target->id}/unban")
        ->assertRedirect();

    expect($this->target->fresh()->isBanned())->toBeFalse();
});

it('logs out a banned user on the next request', function () {
    $url = customerUrl($this->customer, '/dashboard');

    $this->actingAs($this->target)
        ->get($url)
        ->assertOk();

    $this->target->ban();

    $this->actingAs($this->target)
        ->get($url)
        ->assertRedirect('/login');
});

it('resends the verification email', function () {
    $user = User::factory()->unverified()->create();
    Notification::fake();

    $this->actingAs($this->admin)
        ->post("/admin/users/{$user->id}/resend-verification")
        ->assertRedirect();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('resets 2FA state for a user', function () {
    $this->target->forceFill([
        'two_factor_secret' => 'x',
        'two_factor_recovery_codes' => 'y',
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($this->admin)
        ->post("/admin/users/{$this->target->id}/reset-2fa")
        ->assertRedirect();

    $fresh = $this->target->fresh();
    expect($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_confirmed_at)->toBeNull();
});

it('queues a password reset link', function () {
    Password::shouldReceive('sendResetLink')->once()->andReturn(Password::RESET_LINK_SENT);

    $this->actingAs($this->admin)
        ->post("/admin/users/{$this->target->id}/send-password-reset")
        ->assertRedirect();
});

it('sends a test notification to a user', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post("/admin/users/{$this->target->id}/notify-test", ['message' => 'hi'])
        ->assertRedirect();

    Notification::assertSentTo($this->target, AdminTestNotification::class, fn (AdminTestNotification $n) => $n->message === 'hi');
});
