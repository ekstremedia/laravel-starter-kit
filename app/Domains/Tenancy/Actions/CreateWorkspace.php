<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Support\CustomerMembership;
use App\Domains\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a new workspace and make $owner its admin.
 *
 * The single place that provisions a workspace + its first member, used by the
 * self-serve "create your space" registration flow and (later) a "create
 * another space" UI. Runs in one transaction so a half-created workspace with
 * no owner can never happen.
 */
class CreateWorkspace
{
    public function forOwner(User $owner, string $name): Tenant
    {
        return DB::connection((string) config('tenancy.database.central_connection'))->transaction(function () use ($owner, $name): Tenant {
            $workspace = Tenant::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'status' => 'active',
                'files_feature_enabled' => true,
                'company_files_enabled' => true,
                // Mirror the seeded default workspace's 5 GB allowance; null = unlimited.
                'storage_quota_bytes' => 5 * 1024 * 1024 * 1024,
            ]);

            // The creator administers their own space (manage members, files,
            // settings) via the existing customer-scoped Admin role.
            CustomerMembership::attach($owner, $workspace, ['Admin']);

            return $workspace;
        });
    }

    /**
     * A URL-safe, unique slug derived from the workspace name. Falls back to a
     * random suffix on collision so two "Alice's space" workspaces don't clash.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
