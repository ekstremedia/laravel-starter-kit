<?php

declare(strict_types=1);

namespace App\Domains\Files\Models\Concerns;

use App\Domains\Files\Models\FileItem;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Default implementation of the FileOwner contract. Adopt on any Eloquent
 * model that should own a file tree. Override the canManage/canView hooks
 * when the model has its own membership/permission semantics (Workspace,
 * Building, Workspace, etc.).
 */
trait HasFiles
{
    /**
     * @return MorphMany<FileItem, $this>
     */
    public function files(): MorphMany
    {
        return $this->morphMany(FileItem::class, 'owner');
    }

    /**
     * Default rule: only Admin-flagged users can manage files on an
     * arbitrary owner. Models with richer semantics override this — User
     * narrows it to "owner == self", Workspace to "tenant member with role".
     */
    public function canManageFiles(User $user, ?Workspace $workspace = null): bool
    {
        return $user->isSuperAdmin() || $user->can('manage all files');
    }

    public function canViewFiles(User $user, ?Workspace $workspace = null): bool
    {
        return $this->canManageFiles($user, $workspace);
    }
}
