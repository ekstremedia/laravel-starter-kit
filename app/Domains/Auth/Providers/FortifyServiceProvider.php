<?php

namespace App\Domains\Auth\Providers;

use App\Domains\Auth\Actions\CreateNewUser;
use App\Domains\Auth\Actions\ResetUserPassword;
use App\Domains\Auth\Actions\UpdateUserPassword;
use App\Domains\Auth\Actions\UpdateUserProfileInformation;
use App\Domains\Auth\Http\Responses\LoginResponse;
use App\Domains\Auth\Http\Responses\RegisterResponse;
use App\Domains\Users\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Inertia view overrides
        Fortify::loginView(fn () => Inertia::render('Auth/Login'));
        Fortify::registerView(fn () => Inertia::render('Auth/Register'));

        Fortify::verifyEmailView(function (Request $request) {
            if ($request->user()->hasVerifiedEmail()) {
                return redirect()->route('app.landing');
            }

            return Inertia::render('Auth/VerifyEmail');
        });

        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword'));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));

        // Block login for banned users. Returning null here signals "invalid
        // credentials" to Fortify so Laravel still shows the generic error
        // without leaking that a specific account is banned.
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', $request->input(Fortify::username()))->first();

            if (! $user || ! Hash::check($request->input('password'), $user->password)) {
                return null;
            }

            if ($user->isBanned()) {
                return null;
            }

            $user->forceFill(['last_login_at' => now()])->save();

            return $user;
        });

        // Two throttles guard /login:
        //   1) email+IP keeps a single browser from brute-forcing one account,
        //   2) IP-only catches a single host spraying attempts across many
        //      addresses during credential stuffing.
        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by($email.'|'.$ip),
                Limit::perMinute(20)->by($ip),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by((string) ($request->session()->get('login.id') ?? $request->ip()));
        });
    }
}
