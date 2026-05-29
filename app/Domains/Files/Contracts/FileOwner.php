<?php

declare(strict_types=1);

namespace App\Domains\Files\Contracts;

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Implemented by any model that can own a FileItem tree (User personal files,
 * Workspace company files, future Building/Workspace files). The HasFiles trait
 * provides the standard implementation; bespoke implementations only need to
 * override hooks where their semantics diverge.
 */
interface FileOwner
{
    /**
     * The polymorphic relationship to FileItem.
     */
    public function files(): MorphMany;

    /**
     * Whether $user is allowed to manage (upload, rename, delete) files
     * owned by this model. Called by FileItemPolicy when the cross-cutting
     * "manage all files" permission isn't present.
     *
     * The $workspace scopes the question to one workspace — relevant for
     * workspace-owned file trees and per-workspace role assignments.
     */
    public function canManageFiles(User $user, ?Workspace $workspace = null): bool;

    /**
     * Whether $user can read files owned by this model. Always at least as
     * permissive as canManageFiles.
     */
    public function canViewFiles(User $user, ?Workspace $workspace = null): bool;
}
