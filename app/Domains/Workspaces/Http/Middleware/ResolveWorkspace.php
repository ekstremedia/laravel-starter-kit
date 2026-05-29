<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Http\Middleware;

use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves the {customer} route parameter (a slug) into a Workspace (our Customer),
 * verifies the authenticated user belongs to it, boots tenancy (PG
 * search_path → tenant<id>), and sets the Spatie permission team id to the
 * customer so every downstream `hasRole`/`can` call auto-scopes.
 *
 * SuperAdmins bypass the membership check — they can enter any customer.
 *
 * Class name mentions "WorkspaceContext" because stancl/tenancy is the underlying
 * mechanism; at the app layer we surface everything as "customer".
 */
class ResolveWorkspace
{
    public function __construct(private readonly WorkspaceContext $tenancy) {}

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

        /** @var Workspace|null $customer */
        $customer = Workspace::query()->where('slug', $slug)->first();

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

        $route->forgetParameter('customer');

        $request->attributes->set('customer', $customer);

        // Remember the user's most-recent customer so /app can auto-redirect
        // them on their next login. Writes only when it actually changes to
        // avoid hammering the setting row on every request.
        $resolved = $user->settings()->resolved();
        if (($resolved['last_customer_slug'] ?? null) !== $customer->slug) {
            $user->settings()->merge(['last_customer_slug' => $customer->slug]);
        }

        // Activate the workspace for this request: the resolver holds the
        // current tenant and points Spatie's permission team scope at it, so
        // every downstream role/permission check resolves per-workspace. The
        // global `workspace_id` scope (BelongsToWorkspace) reads the same resolver.
        // runFor() restores the prior context afterwards so a long-lived
        // worker can't leak one workspace into the next request.
        return $this->tenancy->runFor($customer, fn () => $next($request));
    }
}
