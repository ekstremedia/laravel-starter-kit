<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Http\Controllers;

use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Models\WorkspaceInvitation;
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
 *   - user has 1 workspace  → /w/{slug}/dashboard
 *   - user has many        → render the picker
 */
class WorkspaceLandingController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        // `/app` is behind `['auth','verified']`, so `user()` is guaranteed non-null.
        $user = $request->user();

        // Finish a pending invitation the user started while logged out (they
        // were sent here after registering / logging in). Convergence point for
        // both new-account and existing-account invitees.
        if ($token = $request->session()->pull('workspace_invitation_token')) {
            $invitation = WorkspaceInvitation::query()->where('token', (string) $token)->first();
            if ($invitation && $invitation->isPending() && mb_strtolower($invitation->email) === mb_strtolower($user->email)) {
                WorkspaceInvitationController::acceptFor($user, $invitation);

                return WorkspaceInvitationController::toWorkspace($invitation->workspace)
                    ->with('success', __('invitations.flash.joined', ['workspace' => $invitation->workspace->name]));
            }
        }

        // Single-workspace mode: there's no picker and no slug — go straight to
        // the (root-mounted) dashboard.
        if (! config('workspaces.enabled')) {
            return redirect()->route('workspace.dashboard');
        }

        // SuperAdmins can enter any active workspace; regular users only their memberships.
        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = $user->isSuperAdmin()
            ? Workspace::query()->where('status', 'active')->orderBy('name')->get()
            : $user->workspaces()->where('status', 'active')->orderBy('name')->get();

        // Self-serve sign-up (create_own) with no workspace yet → send them to
        // the onboarding form to create their own. Invitees were already routed
        // to their workspace above; SuperAdmins see every workspace so aren't
        // empty here. join_default users have auto-joined the default workspace.
        if (config('workspaces.registration_mode') === 'create_own'
            && ! $user->isSuperAdmin()
            && $workspaces->isEmpty()) {
            return redirect()->route('workspaces.onboarding.show');
        }

        if ($workspaces->count() === 1) {
            /** @var Workspace $only */
            $only = $workspaces->first();

            return redirect()->route('workspace.dashboard', ['workspace' => $only->slug]);
        }

        // Prefer the user's most recently visited workspace. Falls through to
        // the picker if the slug is stale (workspace suspended, user removed)
        // or has never been set.
        $remembered = $user->settings()->resolved()['last_workspace_slug'] ?? null;
        if (is_string($remembered) && $remembered !== '') {
            $match = $workspaces->firstWhere('slug', $remembered);
            if ($match) {
                return redirect()->route('workspace.dashboard', ['workspace' => $match->slug]);
            }
        }

        // The picker itself handles the empty case with a friendly "ask an admin
        // to add you" message — let it render so the user sees *why* they can't
        // enter anywhere rather than getting a bare redirect.
        return $this->picker($workspaces);
    }

    /**
     * @param  Collection<int, Workspace>  $workspaces
     */
    private function picker($workspaces): Response
    {
        return Inertia::render('Workspaces/Picker', [
            'workspaces' => $workspaces->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'slug' => $workspace->slug,
                'name' => $workspace->name,
            ])->values(),
        ]);
    }
}
