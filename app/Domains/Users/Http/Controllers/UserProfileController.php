<?php

declare(strict_types=1);

namespace App\Domains\Users\Http\Controllers;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use App\Domains\Users\Policies\UserProfilePolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public-ish profile page for any user. The viewer must share at least one
 * customer with the profile owner (super admins bypass) — see
 * UserProfilePolicy@view. URLs use the user's UUID `public_id` rather than
 * the auto-increment `id`, so profile pages can't be enumerated by guessing
 * 1..N.
 */
class UserProfileController extends Controller
{
    public function show(Request $request, User $user): Response
    {
        $viewer = $request->user();
        abort_unless($viewer && (new UserProfilePolicy)->view($viewer, $user), 403);

        // Customers the *viewer* is allowed to know about: only their own,
        // unless they're a super admin. Intersected with the profile
        // owner's memberships so we never reveal customers the viewer
        // wouldn't otherwise see.
        $query = $user->customers()->where('status', 'active');
        if (! $viewer->isSuperAdmin()) {
            // Subquery instead of pluck() — the inner SELECT runs as part of
            // the same SQL statement, avoiding a second round-trip and a
            // potentially large WHERE IN payload.
            $query->whereIn('tenants.id', $viewer->customers()->select('tenants.id'));
        }
        /** @var array<int, Tenant> $shared */
        $shared = $query->orderBy('name')->get(['tenants.id', 'tenants.slug', 'tenants.name'])->all();

        return Inertia::render('UserProfile', [
            'profile' => [
                'public_id' => $user->public_id,
                'full_name' => $user->fullName(),
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'headline' => $user->headline,
                'bio' => $user->bio,
                'location' => $user->location,
                'website' => $user->website,
                'avatar_url' => $user->avatarUrl('avatar'),
                'avatar_thumb_url' => $user->avatarUrl('thumb'),
                'created_at' => $user->created_at,
            ],
            'shared_customers' => array_map(fn (Tenant $c) => [
                'id' => $c->id,
                'slug' => $c->slug,
                'name' => $c->name,
            ], $shared),
            'is_self' => $viewer->is($user),
        ]);
    }
}
