<?php

namespace App\Domains\Users\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Central-connection-pinned replacement for Sanctum's default token model.
 *
 * The personal_access_tokens table lives in the one shared database alongside
 * users. The pin is vestigial — it resolves to the single default connection,
 * so there is no per-request connection swap to undo. Left in place so the
 * subclass keeps an explicit home.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public function getConnectionName(): ?string
    {
        return config('workspaces.database.central_connection');
    }
}
