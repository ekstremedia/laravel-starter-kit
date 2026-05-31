<?php

declare(strict_types=1);

namespace App\Domains\Modules\Http\Middleware;

use App\Domains\Modules\Services\ModuleRegistry;
use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a module's routes on its enabled flag at REQUEST time (not registration
 * time). 404s when the module is disabled FOR THE ACTIVE WORKSPACE — a workspace
 * admin can turn a module off for their workspace (and disabling a parent
 * cascades to its children), so the gate is workspace-aware, not just platform.
 *
 * Why a middleware and not `if (...) { Route::... }`: route registration is
 * cached by `php artisan route:cache` in production, which would freeze the
 * enabled set at cache-build time — toggling a module would then have no effect
 * until the cache is rebuilt. Gating per-request keeps the toggle instant and
 * route-cache-safe.
 *
 * Usage: `->middleware('module:equipment')`.
 */
class EnsureModuleEnabled
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        // Resolved by ResolveWorkspace (multi-tenant) / BindDefaultWorkspace
        // (single-tenant); fall back to the request context. Null → platform-only.
        $workspace = $request->attributes->get('workspace');
        if (! $workspace instanceof Workspace) {
            $workspace = app(WorkspaceContext::class)->current();
        }

        abort_unless($this->registry->moduleEnabled($module, $workspace), 404);

        return $next($request);
    }
}
