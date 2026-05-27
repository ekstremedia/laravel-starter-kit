<?php

namespace App\Domains\Users\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Central-connection-pinned replacement for Sanctum's default token model.
 *
 * The personal_access_tokens table lives on the landlord (central) schema
 * alongside users. Without this override, Sanctum queries would follow the
 * tenant connection after stancl/tenancy swaps the PDO, failing with
 * "relation personal_access_tokens does not exist" inside any
 * customer-scoped request.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }
}
