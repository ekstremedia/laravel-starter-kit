<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    config([
        'socialite.enabled' => true,
        'socialite.providers.google' => true,
        'socialite.providers.github' => true,
        'services.google.client_id' => 'id',
        'services.google.client_secret' => 'secret',
        'services.google.redirect' => '/auth/google/callback',
        'services.github.client_id' => 'id',
        'services.github.client_secret' => 'secret',
        'services.github.redirect' => '/auth/github/callback',
    ]);
});

it('aborts redirect when socialite is globally disabled', function () {
    config(['socialite.enabled' => false]);

    $this->get('/auth/google/redirect')->assertNotFound();
});

it('aborts redirect when the specific provider is not enabled', function () {
    config(['socialite.providers.github' => false]);

    $this->get('/auth/github/redirect')->assertNotFound();
});

it('redirects the user to the provider when enabled', function () {
    $response = $this->get('/auth/google/redirect');

    expect($response->getStatusCode())->toBe(302);
    expect((string) $response->headers->get('Location'))->toContain('accounts.google.com');
});

it('creates a new user on callback when no match exists', function () {
    $oauthUser = fakeOauthUser('newcomer@example.test', '123456', 'Ada Lovelace');
    Socialite::shouldReceive('driver->user')->andReturn($oauthUser);

    $this->get('/auth/google/callback?code=x')->assertRedirect();

    $user = User::where('email', 'newcomer@example.test')->first();
    expect($user)->not->toBeNull();
    expect($user->provider)->toBe('google');
    expect($user->provider_id)->toBe('123456');
    expect($user->first_name)->toBe('Ada');
    expect($user->last_name)->toBe('Lovelace');
    expect($user->email_verified_at)->not->toBeNull();
});

it('links the provider to an existing account with the same verified email', function () {
    $existing = User::factory()->create(['email' => 'known@example.test']);

    $oauthUser = fakeOauthUser('known@example.test', '999', 'Known User');
    Socialite::shouldReceive('driver->user')->andReturn($oauthUser);

    $this->get('/auth/google/callback?code=x')->assertRedirect();

    $existing->refresh();
    expect($existing->provider)->toBe('google');
    expect($existing->provider_id)->toBe('999');
    expect(auth()->id())->toBe($existing->id);
});

it('signs a returning OAuth user straight in by (provider, provider_id)', function () {
    $user = User::factory()->create(['email' => 'returning@example.test']);
    $user->forceFill(['provider' => 'google', 'provider_id' => 'stable-id-1'])->save();

    $oauthUser = fakeOauthUser('returning@example.test', 'stable-id-1', 'Returning User');
    Socialite::shouldReceive('driver->user')->andReturn($oauthUser);

    $this->get('/auth/google/callback?code=x')->assertRedirect();

    expect(auth()->id())->toBe($user->id);
});

it('bounces back to login with a friendly flash when the oauth state is invalid', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new InvalidStateException);

    $response = $this->from('/login')->get('/auth/google/callback?code=x');

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors(['oauth']);
    expect(auth()->check())->toBeFalse();
});

it('bounces back to login when the oauth provider throws anything else', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new RuntimeException('provider down'));

    $response = $this->from('/login')->get('/auth/google/callback?code=x');

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors(['oauth']);
    expect(auth()->check())->toBeFalse();
});

function fakeOauthUser(string $email, string $id, string $name): Laravel\Socialite\Contracts\User
{
    return new class($email, $id, $name) implements Laravel\Socialite\Contracts\User
    {
        public function __construct(private string $email, private string $id, private string $name) {}

        public function getId(): string
        {
            return $this->id;
        }

        public function getNickname(): ?string
        {
            return null;
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function getEmail(): string
        {
            return $this->email;
        }

        public function getAvatar(): ?string
        {
            return 'https://example.test/avatar.png';
        }
    };
}
