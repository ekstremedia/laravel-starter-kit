<?php

declare(strict_types=1);

namespace App\Domains\Access\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Pinned to the central connection — spatie/laravel-permission tables
 * live in the central schema, but stancl/tenancy swaps the default
 * connection to the tenant mid-request, so permission lookups would
 * otherwise hit the wrong database.
 */
class Permission extends SpatiePermission
{
    public function getConnectionName(): ?string
    {
        return (string) config('workspaces.database.central_connection', 'central');
    }
}
