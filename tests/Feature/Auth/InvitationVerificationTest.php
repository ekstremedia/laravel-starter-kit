<?php

use App\Domains\Notifications\Notifications\VerifyEmailNotification;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Models\WorkspaceInvitation;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\RecoveryCode;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    // CreateNewUser auto-joins the default workspace outside create_own mode, so
    // it must exist for a clean (warning-free) registration.
    Workspace::firstOrCreate(
        ['slug' => config('workspaces.default_workspace_slug', 'default')],
        ['name' => 'Default', 'status' => 'active'],
    );
    $this->workspace = createWorkspace('acme', 'Acme');
});

function pendingInvite(Workspace $workspace, string $email): WorkspaceInvitation
{
    return WorkspaceInvitation::create([
        'workspace_id' => $workspace->id,
        'email' => $email,
        'role' => 'User',
        'token' => WorkspaceInvitation::freshToken(),
        'expires_at' => now()->addDays(7),
    ]);
}

it('auto-verifies an invitee who registers with the invited email', function () {
    Notification::fake();
    $invitation = pendingInvite($this->workspace, 'invitee@example.com');

    $this->withSession(['workspace_invitation_token' => $invitation->token])
        ->post('/register', [
            'first_name' => 'Inv',
            'last_name' => 'Itee',
            'email' => 'invitee@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
        // Verified at sign-up → straight to the landing router, not the notice.
        ->assertRedirect(route('app.landing'));

    $user = User::where('email', 'invitee@example.com')->first();
    expect($user->hasVerifiedEmail())->toBeTrue();

    // The redundant verification email must NOT go out.
    Notification::assertNotSentTo($user, VerifyEmailNotification::class);
});

it('case-insensitively matches the invited email', function () {
    $invitation = pendingInvite($this->workspace, 'mixed@example.com');

    $this->withSession(['workspace_invitation_token' => $invitation->token])
        ->post('/register', [
            'first_name' => 'Mix',
            'last_name' => 'Ed',
            'email' => 'Mixed@Example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('app.landing'));

    expect(User::whereRaw('lower(email) = ?', ['mixed@example.com'])->first()->hasVerifiedEmail())->toBeTrue();
});

it('does NOT verify when the invited email is merely typed without the invite token', function () {
    // The attacker knows an address has a pending invite and types it at signup,
    // but never visited the tokenised accept link — so no token is in session.
    pendingInvite($this->workspace, 'victim@example.com');

    $this->post('/register', [
        'first_name' => 'Mal',
        'last_name' => 'Lory',
        'email' => 'victim@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect('/email/verify');

    expect(User::where('email', 'victim@example.com')->first()->hasVerifiedEmail())->toBeFalse();
});

it('does NOT verify when the registered email differs from the invitation', function () {
    // A real token is in session, but they register as a different address.
    $invitation = pendingInvite($this->workspace, 'invited@example.com');

    $this->withSession(['workspace_invitation_token' => $invitation->token])
        ->post('/register', [
            'first_name' => 'Some',
            'last_name' => 'One',
            'email' => 'someone-else@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/email/verify');

    expect(User::where('email', 'someone-else@example.com')->first()->hasVerifiedEmail())->toBeFalse();
});

it('does NOT verify from an expired invitation token', function () {
    $invitation = pendingInvite($this->workspace, 'late@example.com');
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();

    $this->withSession(['workspace_invitation_token' => $invitation->token])
        ->post('/register', [
            'first_name' => 'La',
            'last_name' => 'Te',
            'email' => 'late@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/email/verify');

    expect(User::where('email', 'late@example.com')->first()->hasVerifiedEmail())->toBeFalse();
});

it('does NOT verify from an already-accepted invitation token', function () {
    $invitation = pendingInvite($this->workspace, 'used@example.com');
    $invitation->forceFill(['accepted_at' => now()])->save();

    $this->withSession(['workspace_invitation_token' => $invitation->token])
        ->post('/register', [
            'first_name' => 'Us',
            'last_name' => 'Ed',
            'email' => 'used@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/email/verify');

    expect(User::where('email', 'used@example.com')->first()->hasVerifiedEmail())->toBeFalse();
});

it('auto-verifies an existing unverified account that logs in via a matching invite', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'returning@example.com',
        'password' => Hash::make('password123'),
    ]);
    $invitation = pendingInvite($this->workspace, 'returning@example.com');

    $this->withSession(['workspace_invitation_token' => $invitation->token])
        ->post('/login', ['email' => 'returning@example.com', 'password' => 'password123'])
        ->assertRedirect(route('app.landing'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('auto-verifies a 2FA-enabled unverified account after it completes the challenge via a matching invite', function () {
    $recoveryCode = RecoveryCode::generate();
    $user = User::factory()->unverified()->create([
        'email' => 'twofa@example.com',
        'password' => Hash::make('password123'),
    ]);
    // Enable 2FA (confirm => true requires two_factor_confirmed_at). The secret
    // need not be a valid TOTP key — we authenticate with a recovery code.
    $user->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode([$recoveryCode])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $invitation = pendingInvite($this->workspace, 'twofa@example.com');

    // Password step defers to the 2FA challenge — must NOT verify yet.
    $this->withSession(['workspace_invitation_token' => $invitation->token])
        ->post('/login', ['email' => 'twofa@example.com', 'password' => 'password123'])
        ->assertRedirect(route('two-factor.login'));
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    // Completing the challenge routes through our TwoFactorLoginResponse, which
    // runs the same gated auto-verify as the password-login path.
    $this->post('/two-factor-challenge', ['recovery_code' => $recoveryCode])
        ->assertRedirect(route('app.landing'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('still sends an ordinary (non-invited) sign-up to the verification notice', function () {
    $this->post('/register', [
        'first_name' => 'Plain',
        'last_name' => 'User',
        'email' => 'plain@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect('/email/verify');

    expect(User::where('email', 'plain@example.com')->first()->hasVerifiedEmail())->toBeFalse();
});
