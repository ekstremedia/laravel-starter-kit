<?php

declare(strict_types=1);

namespace App\Domains\Users\Console;

use App\Domains\Users\Models\User;
use Illuminate\Console\Command;

/**
 * Toggle the platform super-admin flag for a user by id. `is_super_admin` is a
 * guarded column (not mass-assignable), so it's force-filled here — the same
 * path UserController::setRole uses. Wrapped by `make super-admin id=<id>`.
 */
class UserSuperAdmin extends Command
{
    protected $signature = 'user:super-admin
        {user : The user id}
        {--revoke : Revoke super admin instead of granting it}';

    protected $description = 'Grant (or revoke with --revoke) the platform super-admin flag for a user by id.';

    public function handle(): int
    {
        $user = User::find($this->argument('user'));
        if (! $user) {
            $this->error("No user with id [{$this->argument('user')}].");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');

        if ($grant === $user->isSuperAdmin()) {
            $this->info("{$user->email} is already ".($grant ? 'a super admin.' : 'not a super admin.'));

            return self::SUCCESS;
        }

        // Don't strip the last remaining super admin (mirrors UserController::setRole).
        if (! $grant && User::query()->where('is_super_admin', true)->where('id', '!=', $user->id)->count() === 0) {
            $this->error('Refusing: this is the last super admin.');

            return self::FAILURE;
        }

        // is_super_admin is guarded against mass-assignment — force it.
        $user->forceFill(['is_super_admin' => $grant])->save();

        $this->info(($grant ? 'Promoted' : 'Demoted')." {$user->email} (#{$user->id}).");

        return self::SUCCESS;
    }
}
