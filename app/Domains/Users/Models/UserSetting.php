<?php

namespace App\Domains\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-type UserSettingsShape array{
 *     locale: string,
 *     dark_mode: bool,
 *     notification_email_immediate: bool,
 *     notification_digest: 'none'|'daily'|'weekly',
 *     notification_chat_messages: bool,
 *     notification_account_updates: bool,
 *     notification_system_alerts: bool,
 *     notification_storage_alerts: bool,
 *     files_enabled: bool,
 *     storage_quota_override: int|null,
 *     storage_last_alerted_threshold: array<string, int>|null,
 *     last_workspace_slug: string|null,
 *     dashboard_hidden_widgets: list<string>,
 * }
 */
class UserSetting extends Model
{
    protected $fillable = ['user_id', 'settings'];

    /**
     * Pin every read/write to the central connection. The pin is vestigial —
     * user_settings lives in the one shared database and this resolves to the
     * single default connection, so there is no per-request connection swap
     * to undo.
     */
    public function getConnectionName(): ?string
    {
        return config('workspaces.database.central_connection');
    }

    protected $casts = [
        'settings' => 'array',
    ];

    /**
     * Default values for all settings.
     * Add new settings here — they are automatically merged when reading.
     *
     * @var UserSettingsShape
     */
    public static array $defaults = [
        'locale' => 'en',
        'dark_mode' => true,
        'notification_email_immediate' => false,
        'notification_digest' => 'none', // 'none', 'daily', 'weekly'
        'notification_chat_messages' => true,
        'notification_account_updates' => true,
        'notification_system_alerts' => true,
        'notification_storage_alerts' => true,
        // Per-user opt-OUT for the personal file system. Defaults to true so
        // anyone who's a member of a workspace with files_feature_enabled
        // sees /files automatically. Power users can flip this off via the
        // settings API; most never will.
        'files_enabled' => true,
        // Per-user storage override. null/missing = inherit from the 3-tier
        // resolution (tenant default → app default → unlimited). -1 = explicit
        // unlimited for this user. 0 = hard-disabled. N > 0 = byte cap.
        // Resolve through StorageUsageService::effectivePersonalQuota — don't
        // read this key directly.
        'storage_quota_override' => null,
        // Highest threshold (80/95/100) we've already notified about, so we
        // don't spam the same warning every upload. Reset to null on delete.
        'storage_last_alerted_threshold' => null,
        // Most recently visited workspace slug — used by WorkspaceLandingController
        // to auto-redirect returning users instead of forcing them through the picker.
        'last_workspace_slug' => null,
        // Dashboard widget keys the user has hidden. Empty = all module widgets
        // shown (default on). Toggled from the dashboard's "Customize" panel.
        'dashboard_hidden_widgets' => [],
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Return settings merged with defaults, so missing keys always have a value.
     */
    public function resolved(): array
    {
        return array_merge(static::$defaults, $this->settings ?? []);
    }

    /**
     * Merge a partial array of settings into the existing ones.
     *
     * IMPORTANT: we store only what's been explicitly set, not the full
     * resolved() view. Storing the full resolved set would freeze whichever
     * defaults were in force at that moment into the row, which means any
     * later change to $defaults would silently fail to propagate to existing
     * users (exactly the "Files nav hidden" bug from the starter kit's early
     * life). resolved() still applies the current defaults on read.
     */
    public function merge(array $partial): void
    {
        $this->settings = array_merge($this->settings ?? [], $partial);
        $this->save();
    }
}
