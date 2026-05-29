<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Http\Controllers;

use App\Domains\Workspaces\Actions\CreateWorkspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-serve "create your workspace" onboarding (central route, auth+verified).
 *
 * Only relevant in multi-tenant `create_own` mode: a freshly-registered,
 * non-invited user lands here from WorkspaceLandingController (zero workspaces)
 * to name and create their own space, becoming its Admin. Mounted centrally —
 * NOT under /w/{workspace} — because a user with no membership can't pass the
 * ResolveWorkspace middleware yet. Invited users never reach this: the landing
 * controller accepts their invitation first.
 */
class WorkspaceOnboardingController extends Controller
{
    /** Show the prefilled, descriptive create-your-workspace form. */
    public function show(Request $request): RedirectResponse|Response
    {
        if ($redirect = $this->unavailable()) {
            return $redirect;
        }

        return Inertia::render('Workspaces/Create', [
            'suggestedName' => CreateWorkspace::suggestedNameFor($request->user()),
        ]);
    }

    /** Create the workspace, make the user its Admin, and enter it. */
    public function store(Request $request, CreateWorkspace $createWorkspace): RedirectResponse
    {
        if ($redirect = $this->unavailable()) {
            return $redirect;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $workspace = $createWorkspace->forOwner($request->user(), $data['name']);

        return WorkspaceInvitationController::toWorkspace($workspace)
            ->with('success', __('flash.workspaces.created', ['name' => $workspace->name]));
    }

    /**
     * Self-serve creation only exists in multi-tenant `create_own` mode; any
     * other configuration sends the user back to the landing router.
     */
    private function unavailable(): ?RedirectResponse
    {
        if (config('workspaces.enabled') && config('workspaces.registration_mode') === 'create_own') {
            return null;
        }

        return redirect()->route('app.landing');
    }
}
