<?php

namespace App\Domains\Users\Console;

use App\Domains\Access\Models\Role;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use App\Domains\Workspaces\Support\WorkspaceMembership;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;

class UserGrantRole extends Command
{
    protected $signature = 'user:grant-role
        {email}
        {role : Workspace-scoped role (Admin/Editor/User) or the literal SuperAdmin}
        {--workspace= : Workspace slug for workspace-scoped roles (required unless role=SuperAdmin)}
        {--revoke : Revoke the role instead of granting it}';

    protected $description = 'Grant (or revoke) a workspace-scoped role, or toggle the SuperAdmin flag.';

    public function handle(): int
    {
        $user = User::where('email', (string) $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $role = (string) $this->argument('role');
        $revoke = (bool) $this->option('revoke');

        if ($role === 'SuperAdmin') {
            $user->forceFill(['is_super_admin' => ! $revoke])->save();
            $this->info(($revoke ? 'Demoted' : 'Promoted')." {$user->email} (SuperAdmin).");

            return self::SUCCESS;
        }

        $workspaceSlug = $this->option('workspace');
        if ($workspaceSlug === null || $workspaceSlug === '') {
            $this->error('Workspace-scoped roles require --workspace=<slug>. Pass role=SuperAdmin for the platform flag.');

            return self::FAILURE;
        }

        $workspace = Workspace::query()->where('slug', $workspaceSlug)->first();
        if (! $workspace) {
            $this->error("No workspace with slug [{$workspaceSlug}].");

            return self::FAILURE;
        }

        if (! Role::where('name', $role)->where('guard_name', 'web')->exists()) {
            $this->error("No role named {$role}.");

            return self::FAILURE;
        }

        if ($revoke) {
            $registrar = app(PermissionRegistrar::class);
            $previous = $registrar->getPermissionsTeamId();
            try {
                $registrar->setPermissionsTeamId($workspace->id);
                $user->removeRole($role);
            } finally {
                $registrar->setPermissionsTeamId($previous);
            }
            $this->info("Revoked {$role} from {$user->email} on [{$workspace->slug}].");
        } else {
            WorkspaceMembership::attach($user, $workspace, $role);
            $this->info("Granted {$role} to {$user->email} on [{$workspace->slug}].");
        }

        return self::SUCCESS;
    }
}
