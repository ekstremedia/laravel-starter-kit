<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Policies;

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Support\WorkspaceMembership;

class WorkspaceProfilePolicy
{
    /**
     * Members of a workspace can view that workspace's profile. Super admins
     * see all.
     */
    public function view(User $viewer, Workspace $workspace): bool
    {
        if ($viewer->isSuperAdmin()) {
            return true;
        }

        return $viewer->belongsToWorkspace($workspace);
    }

    /**
     * Workspace Admins (a Spatie team-scoped role inside this workspace) and
     * super admins may edit a workspace's profile. Editor/User roles cannot.
     */
    public function update(User $viewer, Workspace $workspace): bool
    {
        if ($viewer->isSuperAdmin()) {
            return true;
        }

        return in_array('Admin', WorkspaceMembership::rolesOn($viewer, $workspace), true);
    }
}
