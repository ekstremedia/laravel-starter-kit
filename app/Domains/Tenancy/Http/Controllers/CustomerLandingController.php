<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Http\Controllers;

use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Post-login landing (`/app`). Central redirects from Fortify, LoginResponse,
 * RedirectIfAuthenticated, impersonation, and DevLogin all point here:
 *
 *   - user has 1 customer  → /c/{slug}/dashboard
 *   - user has many        → render the picker
 */
class CustomerLandingController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        // `/app` is behind `['auth','verified']`, so `user()` is guaranteed non-null.
        $user = $request->user();

        // SuperAdmins can enter any active customer; regular users only their memberships.
        /** @var Collection<int, Tenant> $customers */
        $customers = $user->isSuperAdmin()
            ? Tenant::query()->where('status', 'active')->orderBy('name')->get()
            : $user->customers()->where('status', 'active')->orderBy('name')->get();

        if ($customers->count() === 1) {
            /** @var Tenant $only */
            $only = $customers->first();

            return redirect()->route('customer.dashboard', ['customer' => $only->slug]);
        }

        // Prefer the user's most recently visited customer. Falls through to
        // the picker if the slug is stale (customer suspended, user removed)
        // or has never been set.
        $remembered = $user->settings()->resolved()['last_customer_slug'] ?? null;
        if (is_string($remembered) && $remembered !== '') {
            $match = $customers->firstWhere('slug', $remembered);
            if ($match) {
                return redirect()->route('customer.dashboard', ['customer' => $match->slug]);
            }
        }

        // The picker itself handles the empty case with a friendly "ask an admin
        // to add you" message — let it render so the user sees *why* they can't
        // enter anywhere rather than getting a bare redirect.
        return $this->picker($customers);
    }

    /**
     * @param  Collection<int, Tenant>  $customers
     */
    private function picker($customers): Response
    {
        return Inertia::render('Customers/Picker', [
            'customers' => $customers->map(fn (Tenant $customer) => [
                'id' => $customer->id,
                'slug' => $customer->slug,
                'name' => $customer->name,
            ])->values(),
        ]);
    }
}
