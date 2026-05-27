<?php

namespace App\Domains\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $guarded = ['id'];

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    protected $casts = [
        'site_up' => 'boolean',
        'registration_open' => 'boolean',
        'login_enabled' => 'boolean',
        'require_email_verification' => 'boolean',
        'require_2fa_for_admins' => 'boolean',
        'send_welcome_notification' => 'boolean',
        'files_feature_enabled' => 'boolean',
        'max_share_days' => 'integer',
        'default_personal_storage_bytes' => 'integer',
        'default_entity_storage_bytes' => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'site_up' => true,
            'registration_open' => true,
            'login_enabled' => true,
            'require_email_verification' => true,
            'default_role' => 'User',
            'require_2fa_for_admins' => false,
            'send_welcome_notification' => true,
            'announcement_severity' => 'info',
            // Files on by default so a fresh install has a usable file
            // system right away — admins can still flip it off globally
            // from /admin/settings if they don't want it.
            'files_feature_enabled' => true,
            'max_share_days' => 7,
            // 5 GB baseline per user, cascading down through the 3-tier
            // resolution. Customer/user overrides still take precedence —
            // this is the "nothing configured" fallback.
            'default_personal_storage_bytes' => 5 * 1024 * 1024 * 1024,
        ]);
    }
}
