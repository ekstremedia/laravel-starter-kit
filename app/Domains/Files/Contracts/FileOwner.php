<?php

declare(strict_types=1);

namespace App\Domains\Files\Contracts;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Implemented by any model that can own a FileItem tree (User personal files,
 * Tenant company files, future Building/Customer files). The HasFiles trait
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
     * The $tenant scopes the question to one customer — relevant for
     * tenant-owned trees and per-tenant role assignments.
     */
    public function canManageFiles(User $user, ?Tenant $tenant = null): bool;

    /**
     * Whether $user can read files owned by this model. Always at least as
     * permissive as canManageFiles.
     */
    public function canViewFiles(User $user, ?Tenant $tenant = null): bool;
}
