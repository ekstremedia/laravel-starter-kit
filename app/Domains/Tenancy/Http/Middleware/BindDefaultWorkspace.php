<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Http\Middleware;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Single-workspace mode (tenancy disabled). The workspace routes are mounted
 * at the root (no /c/{workspace} prefix), so there is no slug to resolve — we
 * just bind the one default workspace for the request so the BelongsToTenant
 * global scope and the per-workspace permission team still work transparently.
 *
 * Used instead of InitializeTenancyByPath when config('tenancy.enabled') is
 * false. There is exactly one workspace and every user belongs to it, so there
 * is no membership gate here.
 */
class BindDefaultWorkspace
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) config('tenancy.default_customer_slug');

        /** @var Tenant|null $workspace */
        $workspace = Tenant::query()->where('slug', $slug)->where('status', 'active')->first()
            ?? Tenant::query()->where('status', 'active')->orderBy('id')->first();

        if (! $workspace) {
            // Misconfiguration: single-tenant mode with no workspace seeded.
            throw new ServiceUnavailableHttpException(null, 'No workspace is configured. Run database seeding.');
        }

        $request->attributes->set('customer', $workspace);

        return $this->tenancy->runFor($workspace, fn () => $next($request));
    }
}
