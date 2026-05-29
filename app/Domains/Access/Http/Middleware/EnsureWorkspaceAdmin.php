<?php

declare(strict_types=1);

namespace App\Domains\Access\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Gates customer-scoped admin routes to either:
 *   - SuperAdmin  (platform super-user, assignment has team_id = null), or
 *   - Admin on the currently-active customer (team-scoped assignment).
 *
 * Spatie's bundled `role:Admin` middleware wouldn't let SuperAdmin through
 * because their assignment has `team_id = null` and the active team id is
 * the customer's — the role lookup would miss. We need the OR explicitly.
 */
class EnsureWorkspaceAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($user->isSuperAdmin() || $user->hasRole('Admin'))) {
            return $next($request);
        }

        throw new AccessDeniedHttpException;
    }
}
