<?php

declare(strict_types=1);

namespace App\Domains\Operations\Models;

use App\Domains\Tenancy\Models\Tenant;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * Pin spatie/laravel-activitylog to the central connection.
 *
 * `activity_log` lives on the central schema, but stancl/tenancy swaps the
 * default DB connection to the tenant schema mid-request. Spatie's default
 * Activity model resolves via that swapped default, which means any model
 * event logged during a tenancy-initialized request (e.g. uploading a
 * FileItem with `LogsActivity`) tries to insert into `tenant<id>.activity_log`
 * — a table that doesn't exist, and the request 500s with SQLSTATE 42P01.
 *
 * Overriding `getConnectionName()` here forces every read/write to go to the
 * landlord schema regardless of the ambient tenancy state. `activity_model`
 * in config/activitylog.php points the whole package at this subclass.
 */
class Activity extends BaseActivity
{
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }
}
