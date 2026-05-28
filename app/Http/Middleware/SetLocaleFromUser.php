<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Users\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the request-scoped application locale from the authenticated user's
 * `user_settings.locale` preference so backend messages (`__()`, validation,
 * quota-exceeded flashes) come out in the user's chosen language.
 *
 * Guests fall back to `config('app.locale')` via Laravel's default. We only
 * flip the locale when we have an authenticated user and their preference is
 * one of the supported languages.
 */
class SetLocaleFromUser
{
    /** @var array<int, string> */
    private const SUPPORTED = ['en', 'no'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            $locale = $user->settings()->resolved()['locale'] ?? null;
            if (is_string($locale) && in_array($locale, self::SUPPORTED, true)) {
                app()->setLocale($locale);
            }
        }

        return $next($request);
    }
}
