<?php

declare(strict_types=1);

namespace App\Domains\Access\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Pinned to the central connection — see App\Domains\Access\Models\Permission for rationale.
 */
class Role extends SpatieRole
{
    public function getConnectionName(): ?string
    {
        return (string) config('tenancy.database.central_connection', 'central');
    }
}
