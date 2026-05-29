<?php

declare(strict_types=1);

namespace App\Domains\Workspaces\Policies;

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Support\WorkspaceMembership;

class WorkspaceProfilePolicy
{
    /**
     * Members of a customer can view that customer's profile. Super admins
     * see all.
     */
    public function view(User $viewer, Workspace $customer): bool
    {
        if ($viewer->isSuperAdmin()) {
            return true;
        }

        return $viewer->belongsToCustomer($customer);
    }

    /**
     * Customer Admins (a Spatie team-scoped role inside this customer) and
     * super admins may edit a customer's profile. Editor/User roles cannot.
     */
    public function update(User $viewer, Workspace $customer): bool
    {
        if ($viewer->isSuperAdmin()) {
            return true;
        }

        return in_array('Admin', WorkspaceMembership::rolesOn($viewer, $customer), true);
    }
}
