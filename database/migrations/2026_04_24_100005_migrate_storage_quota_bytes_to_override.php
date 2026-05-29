<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move any explicitly set `storage_quota_bytes` values in user_settings.settings
 * into the new `storage_quota_override` key so the 3-tier quota resolution
 * (user override → customer default → global default → unlimited) can tell
 * "admin explicitly set a value" apart from "admin never touched it".
 *
 * Semantics for the new key:
 *   missing/null  = inherit from tenant/app defaults
 *   -1            = explicit unlimited
 *   0             = blocked
 *   N > 0         = byte cap
 *
 * Old values of null (= unlimited under the previous semantics) are converted
 * to -1 so the behaviour stays "unlimited" after the switchover. Rows that
 * never had the key set are left alone — they'll naturally inherit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conn = (string) config('workspaces.database.central_connection');

        DB::connection($conn)->table('user_settings')
            ->orderBy('id')
            ->each(function ($row) use ($conn): void {
                $settings = json_decode((string) $row->settings, true);
                if (! is_array($settings) || ! array_key_exists('storage_quota_bytes', $settings)) {
                    return;
                }

                $previous = $settings['storage_quota_bytes'];
                $override = $previous === null ? -1 : (int) $previous;

                $settings['storage_quota_override'] = $override;
                unset($settings['storage_quota_bytes']);

                DB::connection($conn)->table('user_settings')
                    ->where('id', $row->id)
                    ->update(['settings' => json_encode($settings)]);
            });
    }

    public function down(): void
    {
        $conn = (string) config('workspaces.database.central_connection');

        DB::connection($conn)->table('user_settings')
            ->orderBy('id')
            ->each(function ($row) use ($conn): void {
                $settings = json_decode((string) $row->settings, true);
                if (! is_array($settings) || ! array_key_exists('storage_quota_override', $settings)) {
                    return;
                }

                $override = $settings['storage_quota_override'];
                $settings['storage_quota_bytes'] = $override === -1 ? null : (int) $override;
                unset($settings['storage_quota_override']);

                DB::connection($conn)->table('user_settings')
                    ->where('id', $row->id)
                    ->update(['settings' => json_encode($settings)]);
            });
    }
};
