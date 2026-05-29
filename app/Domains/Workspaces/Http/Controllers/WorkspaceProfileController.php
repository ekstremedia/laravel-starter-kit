<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Http\Controllers;

use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Policies\WorkspaceProfilePolicy;
use App\Domains\Workspaces\Support\WorkspaceContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace "About" page — a small, editable profile card for the company.
 * Distinct from /admin/workspaces/{id}/edit, which is the platform-admin
 * surface. Here, workspace Admins (and super admins) can write a tagline,
 * a longer about-blurb, location, and website without leaving their
 * workspace-scoped UI.
 */
class WorkspaceProfileController extends Controller
{
    /**
     * Public-ish landing for the workspace. Any member can view; outsiders
     * cannot (the surrounding tenancy middleware already bounces them, but
     * we re-check via the policy so a future de-scoping doesn't open this up
     * silently).
     */
    public function show(Request $request): Response
    {
        $workspace = $this->workspace();
        $viewer = $request->user();
        abort_unless($viewer && (new WorkspaceProfilePolicy)->view($viewer, $workspace), 403);

        $teamKey = config('permission.column_names.team_foreign_key');
        $mhrTable = config('permission.table_names.model_has_roles');

        // Same JOIN-based role load as the members page so the about page can
        // show "Members" with their role chips without per-row queries.
        $members = $workspace->users()
            ->with(['roles' => fn ($q) => $q->where("{$mhrTable}.{$teamKey}", $workspace->id), 'media'])
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

        return Inertia::render('Workspace/About/Show', [
            'profile' => [
                'id' => $workspace->id,
                'slug' => $workspace->slug,
                'name' => $workspace->name,
                'headline' => $workspace->headline,
                'about' => $workspace->about,
                'location' => $workspace->location,
                'website' => $workspace->website,
            ],
            'members' => $members,
            'member_count' => $workspace->users()->count(),
            'can_edit' => (new WorkspaceProfilePolicy)->update($viewer, $workspace),
        ]);
    }

    public function edit(Request $request): Response
    {
        $workspace = $this->workspace();
        $viewer = $request->user();
        abort_unless($viewer && (new WorkspaceProfilePolicy)->update($viewer, $workspace), 403);

        return Inertia::render('Workspace/About/Edit', [
            'profile' => [
                'id' => $workspace->id,
                'slug' => $workspace->slug,
                'name' => $workspace->name,
                'headline' => $workspace->headline,
                'about' => $workspace->about,
                'location' => $workspace->location,
                'website' => $workspace->website,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->workspace();
        $viewer = $request->user();
        abort_unless($viewer && (new WorkspaceProfilePolicy)->update($viewer, $workspace), 403);

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

        $workspace->fill($data)->save();

        return redirect()
            ->route('workspace.about.show', ['workspace' => $workspace->slug])
            ->with('success', __('flash.profile.updated'));
    }

    private function workspace(): Workspace
    {
        /** @var Workspace $workspace */
        $workspace = app(WorkspaceContext::class)->current() ?? abort(404);

        return $workspace;
    }
}
