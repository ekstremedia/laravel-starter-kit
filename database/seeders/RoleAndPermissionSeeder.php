<?php

namespace Database\Seeders;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the workspace-scoped role templates.
 *
 * `Admin`, `Editor`, and `User` are role **templates**: the role rows
 * themselves carry `team_id = null` (they're definitions, not assignments),
 * and each per-workspace assignment in `model_has_roles` stamps the workspace
 * id as `team_id`. Every `hasRole`/`can` check then auto-scopes to whichever
 * workspace is active.
 *
 * Platform-wide super-user access is NOT a role — it's a boolean flag on the
 * user row (`users.is_super_admin`). Spatie's team schema forces
 * `model_has_roles.team_id` to be non-null, so "global" role assignments
 * aren't representable there; seeding + checking SuperAdmin via a column
 * keeps that distinction clean.
 */
class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $workspacePermissions = [
            'view dashboard',
            'manage workspace users',     // invite / remove members of the active workspace
            'manage workspace settings',  // toggle workspace-level feature flags
            'manage profile',
            // File manager — gate each mutation individually so workspace-Admins
            // can carve out read-only roles by removing a subset of these.
            'upload files',
            'create folders',
            'rename files',
            'delete files',
            'share files',
            // Company-shared Files. `manage company files` is the catch-all
            // for admin-level actions (delete anyone's shared file, unshare a
            // link, edit any folder regardless of creator) — it doesn't grant
            // the basic `view`/`upload`/... permissions on its own.
            'view company files',
            'upload to company files',
            'create company folders',
            'share files to company',
            'manage company files',
            // Cross-cutting override: holders can manage any FileItem regardless
            // of owner. Granted to workspace Admins so they can curate building
            // / workspace / etc. file trees that aren't strictly "company" or
            // "personal". SuperAdmin always passes the policy without this.
            'manage all files',
        ];

        foreach ($workspacePermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Workspace-scoped role templates. The role rows are team-agnostic;
        // the assignment in model_has_roles carries the team id.
        $adminRole = Role::firstOrCreate(['name' => 'Admin']);
        $adminRole->syncPermissions($workspacePermissions);

        $editorRole = Role::firstOrCreate(['name' => 'Editor']);
        $editorRole->syncPermissions([
            'view dashboard',
            'manage profile',
            'upload files',
            'create folders',
            'rename files',
            'delete files',
            'share files',
            'view company files',
            'upload to company files',
            'create company folders',
            'share files to company',
        ]);

        $userRole = Role::firstOrCreate(['name' => 'User']);
        $userRole->syncPermissions([
            'view dashboard',
            'manage profile',
            'upload files',
            'create folders',
            'rename files',
            'delete files',
            'share files',
            // Users can see the company area and contribute their own files,
            // but can't upload-into or manage the native company tree —
            // Editors+ handle folder organisation.
            'view company files',
            'share files to company',
        ]);
    }
}
