<?php

namespace App\Domains\Settings\Http\Middleware;

use App\Domains\Settings\Models\AppSetting;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnforceAppSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $settings = AppSetting::current();
        } catch (Throwable) {
            return $next($request);
        }

        $user = $request->user();
        $isAdmin = $user?->isSuperAdmin() ?? false;

        // Site down — admins and the health endpoint always pass
        if (! $settings->site_up && ! $isAdmin && ! $this->isSystemRoute($request)) {
            return Inertia::render('Maintenance', [
                'message' => $settings->maintenance_message ?? 'We\'ll be right back.',
            ])->toResponse($request)->setStatusCode(503);
        }

        // Non-admin login disabled — block the login POST
        if (! $settings->login_enabled && $request->is('login') && $request->isMethod('POST') && ! $isAdmin) {
            return back()->withErrors(['email' => 'Login is temporarily disabled.']);
        }

        // Registration closed — block the register POST and the page
        if (! $settings->registration_open && $request->is('register')) {
            if ($request->isMethod('POST')) {
                return back()->withErrors(['email' => 'Registration is closed.']);
            }

            return Inertia::render('Auth/RegistrationClosed')
                ->toResponse($request)
                ->setStatusCode(403);
        }

        return $next($request);
    }

    private function isSystemRoute(Request $request): bool
    {
        return $request->is('up')
            || $request->is('login')
            || $request->is('logout')
            || $request->is('horizon*')
            || $request->is('pulse*')
            || $request->is('log-viewer*')
            || $request->is('broadcasting/auth');
    }
}
