<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Http\Middleware;

use App\Domains\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves the {customer} route parameter (a slug) into a Tenant (our Customer),
 * verifies the authenticated user belongs to it, boots tenancy (PG
 * search_path → tenant<id>), and sets the Spatie permission team id to the
 * customer so every downstream `hasRole`/`can` call auto-scopes.
 *
 * SuperAdmins bypass the membership check — they can enter any customer.
 *
 * Class name mentions "Tenancy" because stancl/tenancy is the underlying
 * mechanism; at the app layer we surface everything as "customer".
 */
class InitializeTenancyByPath
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (! $route) {
            throw new NotFoundHttpException;
        }

        $slug = $route->parameter('customer');

        if (! is_string($slug) || $slug === '') {
            throw new NotFoundHttpException;
        }

        /** @var Tenant|null $customer */
        $customer = Tenant::query()->where('slug', $slug)->first();

        if (! $customer) {
            throw new NotFoundHttpException("Customer [{$slug}] not found.");
        }

        if ($customer->status !== 'active') {
            throw new AccessDeniedHttpException("Customer [{$slug}] is not active.");
        }

        $user = Auth::user();

        if (! $user || (! $user->isSuperAdmin() && ! $user->belongsToCustomer($customer))) {
            throw new AccessDeniedHttpException("You are not a member of [{$slug}].");
        }

        tenancy()->initialize($customer);

        // Scope every downstream role/permission check to this customer.
        // SuperAdmin assignments (team_id = null) still resolve globally via
        // User::isSuperAdmin(); everything else is per-customer from here on.
        // PermissionRegistrar is a container singleton and survives across
        // requests in long-lived workers (Octane, queues, same-process
        // tests), so we capture the previous team id and restore it after
        // the request runs — otherwise customer A's team context leaks into
        // the next request on the same worker.
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($customer->id);

        $route->forgetParameter('customer');

        $request->attributes->set('customer', $customer);

        // Remember the user's most-recent customer so /app can auto-redirect
        // them on their next login. Writes only when it actually changes to
        // avoid hammering the setting row on every request.
        $resolved = $user->settings()->resolved();
        if (($resolved['last_customer_slug'] ?? null) !== $customer->slug) {
            $user->settings()->merge(['last_customer_slug' => $customer->slug]);
        }

        try {
            return $next($request);
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
