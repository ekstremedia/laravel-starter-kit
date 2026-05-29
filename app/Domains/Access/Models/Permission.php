<?php

declare(strict_types=1);

namespace App\Domains\Access\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Pinned to the central connection — spatie/laravel-permission tables
 * live in the one shared database. The pin is vestigial (it resolves to
 * the single default connection); there is no per-request connection swap
 * to undo. Left in place so the subclass keeps an explicit home.
 */
class Permission extends SpatiePermission
{
    public function getConnectionName(): ?string
    {
        return (string) config('workspaces.database.central_connection', 'central');
    }
}
