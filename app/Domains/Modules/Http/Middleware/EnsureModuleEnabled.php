<?php

declare(strict_types=1);

namespace App\Domains\Modules\Http\Middleware;

use App\Domains\Modules\Services\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a module's routes on its enabled flag at REQUEST time (not registration
 * time). 404s when the module is disabled.
 *
 * Why a middleware and not `if (...) { Route::... }`: route registration is
 * cached by `php artisan route:cache` in production, which would freeze the
 * enabled set at cache-build time — toggling a module in /admin/modules would
 * then have no effect until the cache is rebuilt. Gating per-request keeps the
 * toggle instant and route-cache-safe.
 *
 * Usage: `->middleware('module:equipment')`.
 */
class EnsureModuleEnabled
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->registry->isEnabled($module), 404);

        return $next($request);
    }
}
