<?php

declare(strict_types=1);

namespace App\Domains\Access\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Pinned to the central connection — see App\Domains\Access\Models\Permission for rationale.
 * (The pin is vestigial: it resolves to the single shared database.)
 */
class Role extends SpatieRole
{
    public function getConnectionName(): ?string
    {
        return (string) config('workspaces.database.central_connection', 'central');
    }
}
