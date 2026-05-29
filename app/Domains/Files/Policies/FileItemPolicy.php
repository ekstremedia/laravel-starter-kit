<?php

declare(strict_types=1);

namespace App\Domains\Files\Policies;

use App\Domains\Files\Contracts\FileOwner;
use App\Domains\Files\Models\FileItem;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Model;

/**
 * Authorization for FileItem actions. The policy answers "is $user allowed
 * to do X to this item?" by combining:
 *
 *   1. SuperAdmin / cross-cutting `manage all files` permission → always yes
 *      (SuperAdmin already passes via Gate::before in AppServiceProvider).
 *   2. The item's polymorphic owner's own rules — User-owned items defer to
 *      "is the user the owner", Workspace-owned items defer to membership +
 *      `manage company files` permission, future owners (Building, Workspace)
 *      implement the FileOwner contract to opt in.
 *   3. Per-action capability permissions (`upload files`, `delete files`, …)
 *      gate the action on top of ownership — a member of a tenant who can
 *      view files but not delete them still passes view, fails delete.
 *
 * Policy methods receive the *active tenant context* as the third argument
 * for cases where membership matters (uploads, listings). Pass it from the
 * controller via Gate::forUser($user)->check('update', [$item, $workspace]).
 */
class FileItemPolicy
{
    use HandlesAuthorization;

    public function view(User $user, FileItem $item, ?Workspace $workspace = null): bool
    {
        if ($this->hasOverride($user)) {
            return true;
        }

        return $this->ownerAllowsView($user, $item, $workspace);
    }

    public function download(User $user, FileItem $item, ?Workspace $workspace = null): bool
    {
        return $this->view($user, $item, $workspace);
    }

    public function update(User $user, FileItem $item, ?Workspace $workspace = null): bool
    {
        if ($this->hasOverride($user)) {
            return true;
        }

        return $this->ownerAllowsManage($user, $item, $workspace)
            && $user->can('rename files');
    }

    public function delete(User $user, FileItem $item, ?Workspace $workspace = null): bool
    {
        if ($this->hasOverride($user)) {
            return true;
        }

        return $this->ownerAllowsManage($user, $item, $workspace)
            && $user->can('delete files');
    }

    public function share(User $user, FileItem $item, ?Workspace $workspace = null): bool
    {
        if ($this->hasOverride($user)) {
            return true;
        }

        return $this->ownerAllowsManage($user, $item, $workspace)
            && $user->can('share files');
    }

    /**
     * Can $user upload a new FileItem owned by $owner inside $workspace. Used
     * when no concrete FileItem exists yet (the upload endpoint).
     */
    public function uploadTo(User $user, Model $owner, ?Workspace $workspace = null): bool
    {
        if ($this->hasOverride($user)) {
            return true;
        }

        if (! $this->resolveOwnerCanManage($owner, $user, $workspace)) {
            return false;
        }

        if ($owner instanceof Workspace) {
            return $user->can('upload to company files');
        }

        return $user->can('upload files');
    }

    /**
     * Can $user create a folder owned by $owner. Mirrors uploadTo with the
     * folder-creation permission instead.
     */
    public function createFolderFor(User $user, Model $owner, ?Workspace $workspace = null): bool
    {
        if ($this->hasOverride($user)) {
            return true;
        }

        if (! $this->resolveOwnerCanManage($owner, $user, $workspace)) {
            return false;
        }

        if ($owner instanceof Workspace) {
            return $user->can('create company folders');
        }

        return $user->can('create folders');
    }

    private function hasOverride(User $user): bool
    {
        return $user->isSuperAdmin() || $user->can('manage all files');
    }

    private function ownerAllowsView(User $user, FileItem $item, ?Workspace $workspace): bool
    {
        $owner = $item->owner;

        if ($owner instanceof FileOwner) {
            return $owner->canViewFiles($user, $workspace);
        }

        // Unknown owner — fall back to "uploader can read their own uploads"
        // so we never lock a user out of a file they personally created.
        return $item->user_id === $user->getKey();
    }

    private function ownerAllowsManage(User $user, FileItem $item, ?Workspace $workspace): bool
    {
        return $this->resolveOwnerCanManage($item->owner, $user, $workspace);
    }

    private function resolveOwnerCanManage(?Model $owner, User $user, ?Workspace $workspace): bool
    {
        if ($owner instanceof FileOwner) {
            return $owner->canManageFiles($user, $workspace);
        }

        return false;
    }
}
