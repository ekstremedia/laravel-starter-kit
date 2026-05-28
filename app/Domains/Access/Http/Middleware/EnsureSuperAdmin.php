<?php

declare(strict_types=1);

namespace App\Domains\Access\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gates platform-level (`/admin/*`) routes to SuperAdmins only.
 *
 * SuperAdmin is a boolean flag on the user row (`users.is_super_admin`), not a
 * Spatie role — see `User::isSuperAdmin()` for why. That means the standard
 * `role:…` middleware can't gate on it; this middleware does.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isSuperAdmin()) {
            return $next($request);
        }

        throw new AccessDeniedHttpException;
    }
}
