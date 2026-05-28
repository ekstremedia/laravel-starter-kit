<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Http\Controllers;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Policies\TenantProfilePolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer "About" page — a small, editable profile card for the company.
 * Distinct from /admin/customers/{id}/edit, which is the platform-admin
 * surface. Here, customer Admins (and super admins) can write a tagline,
 * a longer about-blurb, location, and website without leaving their
 * customer-scoped UI.
 */
class CustomerProfileController extends Controller
{
    /**
     * Public-ish landing for the customer. Any member can view; outsiders
     * cannot (the surrounding tenancy middleware already bounces them, but
     * we re-check via the policy so a future de-scoping doesn't open this up
     * silently).
     */
    public function show(Request $request): Response
    {
        $customer = $this->customer();
        $viewer = $request->user();
        abort_unless($viewer && (new TenantProfilePolicy)->view($viewer, $customer), 403);

        $teamKey = config('permission.column_names.team_foreign_key');
        $mhrTable = config('permission.table_names.model_has_roles');

        // Same JOIN-based role load as the members page so the about page can
        // show "Members" with their role chips without per-row queries.
        $members = $customer->users()
            ->with(['roles' => fn ($q) => $q->where("{$mhrTable}.{$teamKey}", $customer->id), 'media'])
            ->orderBy('users.email')
            ->limit(24)
            ->get(['users.id', 'users.public_id', 'users.first_name', 'users.last_name', 'users.email', 'users.headline'])
            ->map(fn ($u) => [
                'public_id' => $u->public_id,
                'full_name' => $u->fullName(),
                'headline' => $u->headline,
                'avatar_thumb_url' => $u->avatarUrl('thumb'),
                'roles' => $u->roles->pluck('name')->all(),
            ])
            ->values();

        return Inertia::render('Customer/About/Show', [
            'profile' => [
                'id' => $customer->id,
                'slug' => $customer->slug,
                'name' => $customer->name,
                'headline' => $customer->headline,
                'about' => $customer->about,
                'location' => $customer->location,
                'website' => $customer->website,
            ],
            'members' => $members,
            'member_count' => $customer->users()->count(),
            'can_edit' => (new TenantProfilePolicy)->update($viewer, $customer),
        ]);
    }

    public function edit(Request $request): Response
    {
        $customer = $this->customer();
        $viewer = $request->user();
        abort_unless($viewer && (new TenantProfilePolicy)->update($viewer, $customer), 403);

        return Inertia::render('Customer/About/Edit', [
            'profile' => [
                'id' => $customer->id,
                'slug' => $customer->slug,
                'name' => $customer->name,
                'headline' => $customer->headline,
                'about' => $customer->about,
                'location' => $customer->location,
                'website' => $customer->website,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $customer = $this->customer();
        $viewer = $request->user();
        abort_unless($viewer && (new TenantProfilePolicy)->update($viewer, $customer), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'headline' => ['nullable', 'string', 'max:160'],
            'about' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string', 'url:http,https', 'max:255'],
        ]);

        foreach (['headline', 'about', 'location', 'website'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $trimmed = trim($data[$key]);
                $data[$key] = $trimmed === '' ? null : $trimmed;
            }
        }

        $customer->fill($data)->save();

        return redirect()
            ->route('customer.about.show', ['customer' => $customer->slug])
            ->with('success', __('flash.profile.updated'));
    }

    private function customer(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = tenancy()->tenant ?? abort(404);

        return $tenant;
    }
}
