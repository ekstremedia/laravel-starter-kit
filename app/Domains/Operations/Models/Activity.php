<?php

declare(strict_types=1);

namespace App\Domains\Operations\Models;

use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * Pin spatie/laravel-activitylog to the central connection.
 *
 * `activity_log` lives in the one shared database. The pin is vestigial — it
 * resolves to the single default connection, so there is no per-request
 * connection swap to undo. `activity_model` in config/activitylog.php points
 * the whole package at this subclass.
 */
class Activity extends BaseActivity
{
    public function getConnectionName(): ?string
    {
        return config('workspaces.database.central_connection');
    }
}
