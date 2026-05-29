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
        {role : Customer-scoped role (Admin/Editor/User) or the literal SuperAdmin}
        {--customer= : Customer slug for customer-scoped roles (required unless role=SuperAdmin)}
        {--revoke : Revoke the role instead of granting it}';

    protected $description = 'Grant (or revoke) a customer-scoped role, or toggle the SuperAdmin flag.';

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

        $customerSlug = $this->option('customer');
        if ($customerSlug === null || $customerSlug === '') {
            $this->error('Customer-scoped roles require --customer=<slug>. Pass role=SuperAdmin for the platform flag.');

            return self::FAILURE;
        }

        $customer = Workspace::query()->where('slug', $customerSlug)->first();
        if (! $customer) {
            $this->error("No customer with slug [{$customerSlug}].");

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
                $registrar->setPermissionsTeamId($customer->id);
                $user->removeRole($role);
            } finally {
                $registrar->setPermissionsTeamId($previous);
            }
            $this->info("Revoked {$role} from {$user->email} on [{$customer->slug}].");
        } else {
            WorkspaceMembership::attach($user, $customer, $role);
            $this->info("Granted {$role} to {$user->email} on [{$customer->slug}].");
        }

        return self::SUCCESS;
    }
}
