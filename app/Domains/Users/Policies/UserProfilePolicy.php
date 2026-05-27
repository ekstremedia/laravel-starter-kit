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

        // tenant_user lives in the central schema. Pin the query to the
        // central connection so the check still works if the policy is ever
        // called from inside a tenant-scoped controller (where stancl/tenancy
        // has swapped the default connection to the active tenant).
        return DB::connection(config('tenancy.database.central_connection'))
            ->table('tenant_user as a')
            ->join('tenant_user as b', 'a.tenant_id', '=', 'b.tenant_id')
            ->where('a.user_id', $viewer->id)
            ->where('b.user_id', $profile->id)
            ->exists();
    }
}
