<?php

namespace App\Domains\Auth\Http\Controllers;

use App\Domains\Users\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * OAuth login bridge (Google / GitHub / ...).
 *
 * The whole controller only participates when `socialite.enabled` is true and
 * the specific provider is toggled in `socialite.providers.{name}`. That's
 * enforced by the `Auth\SocialiteEnabled` middleware applied to the routes.
 */
class SocialiteController extends Controller
{
    /**
     * Providers whose successful OAuth response implies the email is
     * verified. Google and GitHub both refuse to release an email to an
     * app unless the user has confirmed ownership. Anything outside this
     * whitelist (even if enabled in config/socialite.php) is treated as
     * unverified and will never adopt an existing local account by email.
     */
    private const PROVIDERS_WITH_VERIFIED_EMAILS = ['google', 'github'];

    public function redirect(string $provider): SymfonyRedirectResponse
    {
        $this->assertProviderAllowed($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $this->assertProviderAllowed($provider);

        // Any exception here would surface as a 500 — instead bounce back to
        // login with a friendly flash. InvalidStateException (stale/forged
        // `state` param) is common enough to merit its own message; anything
        // else is bucketed under "provider unreachable".
        try {
            /** @var SocialiteUser $oauthUser */
            $oauthUser = Socialite::driver($provider)->user();
        } catch (InvalidStateException) {
            return redirect()->route('login')->withErrors([
                'oauth' => 'Your sign-in session expired. Please try again.',
            ]);
        } catch (Throwable $e) {
            Log::warning('Socialite callback failed', ['provider' => $provider, 'exception' => $e]);

            return redirect()->route('login')->withErrors([
                'oauth' => 'Unable to sign in with '.$provider.'. Please try again.',
            ]);
        }

        $user = $this->resolveUser($provider, $oauthUser);

        Auth::login($user, remember: true);
        activity('auth')->causedBy($user)->event('oauth_login')->withProperties(['provider' => $provider])->log('Signed in via '.$provider);

        return redirect()->intended(route('app.landing'));
    }

    /**
     * Find-or-create the local user.
     *
     * Matching rules:
     *   1) an existing user with the same (provider, provider_id) wins;
     *   2) else, when the provider is on our verified-email whitelist,
     *      a user with the same email is adopted and linked;
     *   3) else, a brand-new account is created with a random password.
     *
     * Step 2 is strictly gated on PROVIDERS_WITH_VERIFIED_EMAILS. Without
     * that check, a malicious user could register a brand-new OAuth account
     * using someone else's email on a provider that doesn't enforce email
     * verification, and walk straight into the victim's local session.
     */
    private function resolveUser(string $provider, SocialiteUser $oauthUser): User
    {
        // Providers can return the email with arbitrary casing (Google has
        // been seen returning "Alice@Example.com" for a user the local DB
        // stores as "alice@example.com"). Normalize so the adopt-by-email
        // branch below doesn't miss — and the eventual INSERT doesn't trip
        // the users.email unique constraint with a case-variant duplicate.
        $rawEmail = $oauthUser->getEmail();
        $email = $rawEmail !== null && trim($rawEmail) !== ''
            ? Str::lower(trim($rawEmail))
            : null;
        $providerId = (string) $oauthUser->getId();

        $user = User::query()
            ->where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        if ($user) {
            $user->forceFill([
                'provider_avatar_url' => $oauthUser->getAvatar(),
                'last_login_at' => now(),
            ])->save();

            return $user;
        }

        if ($email !== null && in_array($provider, self::PROVIDERS_WITH_VERIFIED_EMAILS, true)) {
            $existing = User::where('email', $email)->first();
            if ($existing !== null) {
                $existing->forceFill([
                    'provider' => $provider,
                    'provider_id' => $providerId,
                    'provider_avatar_url' => $oauthUser->getAvatar(),
                    'email_verified_at' => $existing->email_verified_at ?? now(),
                    'last_login_at' => now(),
                ])->save();

                return $existing;
            }
        }

        [$first, $last] = $this->splitName((string) $oauthUser->getName(), (string) $oauthUser->getNickname());

        // forceFill so the provider_* columns bypass the strict $fillable list
        // on the User model — they're controller-resolved, never user-input.
        $trustsEmail = $email !== null && in_array($provider, self::PROVIDERS_WITH_VERIFIED_EMAILS, true);

        $new = new User;
        $new->forceFill([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email ?? $providerId.'@'.$provider.'.oauth.local',
            'password' => bcrypt(Str::random(40)),
            'provider' => $provider,
            'provider_id' => $providerId,
            'provider_avatar_url' => $oauthUser->getAvatar(),
            // Only auto-verify when the provider is on the trust list. Untrusted
            // providers land in /email/verify like a password signup would.
            'email_verified_at' => $trustsEmail ? now() : null,
            'last_login_at' => now(),
        ])->save();

        return $new;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $fullName, string $nickname): array
    {
        $name = trim($fullName) !== '' ? $fullName : $nickname;
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        $first = (string) array_shift($parts);
        $last = implode(' ', $parts);

        return [$first !== '' ? $first : 'User', $last];
    }

    private function assertProviderAllowed(string $provider): void
    {
        abort_unless(
            config('socialite.enabled') && (bool) config('socialite.providers.'.$provider, false),
            404,
        );
    }
}
