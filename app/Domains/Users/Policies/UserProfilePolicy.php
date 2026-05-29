<?php

declare(strict_types=1);

namespace App\Domains\Users\Policies;

use App\Domains\Users\Models\User;
use Illuminate\Support\Facades\DB;

class UserProfilePolicy
{
    /**
     * A user can view another user's public profile when they share at least
     * one customer membership. Super admins bypass the check, and any user
     * can always view their own profile.
     */
    public function view(User $viewer, User $profile): bool
    {
        if ($viewer->isSuperAdmin()) {
            return true;
        }

        if ($viewer->is($profile)) {
            return true;
        }

        // workspace_user lives in the central schema. Pin the query to the
        // central connection so the check still works if the policy is ever
        // called from inside a tenant-scoped controller (where stancl/tenancy
        // has swapped the default connection to the active tenant).
        return DB::connection(config('workspaces.database.central_connection'))
            ->table('workspace_user as a')
            ->join('workspace_user as b', 'a.workspace_id', '=', 'b.workspace_id')
            ->where('a.user_id', $viewer->id)
            ->where('b.user_id', $profile->id)
            ->exists();
    }
}
