<?php

declare(strict_types=1);

namespace App\Domains\Files\Support;

use App\Domains\Settings\Models\AppSetting;

/**
 * Single source of truth for "how big a single uploaded file may be".
 *
 * Two ceilings stack: the admin-configured `max_upload_bytes` setting and the
 * hard limit the running PHP process accepts (the smaller of
 * upload_max_filesize / post_max_size). We always clamp the configured value
 * to the PHP ceiling so the validation rule can never promise more than the
 * server will actually accept.
 */
final class UploadLimits
{
    /**
     * Hard ceiling the running PHP process accepts for a single upload —
     * min(upload_max_filesize, post_max_size). A `0`/empty ini value means
     * "unlimited", which we model as PHP_INT_MAX so it never wins the min().
     */
    public static function phpCeilingBytes(): int
    {
        return min(
            self::parseIniSize((string) ini_get('upload_max_filesize')),
            self::parseIniSize((string) ini_get('post_max_size')),
        );
    }

    /**
     * Admin-configured per-file ceiling in bytes, clamped to the PHP ceiling
     * and floored at 1 KB. Falls back to the legacy config constant when the
     * setting column is somehow null.
     */
    public static function maxUploadBytes(): int
    {
        $configured = (int) (AppSetting::current()->max_upload_bytes
            ?: ((int) config('files.max_upload_kilobytes', 51200)) * 1024);

        return max(1024, min($configured, self::phpCeilingBytes()));
    }

    /**
     * Kilobytes, for Laravel's `max:` validation rule (which counts in KB).
     */
    public static function maxUploadKilobytes(): int
    {
        return (int) ceil(self::maxUploadBytes() / 1024);
    }

    /**
     * Admin-configured per-file ceiling for chat attachments, in bytes,
     * clamped to the PHP ceiling and floored at 1 KB. Falls back to 10 MB.
     */
    public static function chatMaxUploadBytes(): int
    {
        $configured = (int) (AppSetting::current()->chat_max_upload_bytes ?: 10 * 1024 * 1024);

        return max(1024, min($configured, self::phpCeilingBytes()));
    }

    /**
     * Chat attachment ceiling in kilobytes, for the `max:` validation rule.
     */
    public static function chatMaxUploadKilobytes(): int
    {
        return (int) ceil(self::chatMaxUploadBytes() / 1024);
    }

    /**
     * Parse a PHP ini shorthand size ("500M", "2G", "51200K", "1048576") into
     * bytes. `ini_get` returns these strings, never raw byte counts — parsing
     * is mandatory. `0`/empty means unlimited → PHP_INT_MAX.
     */
    public static function parseIniSize(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return PHP_INT_MAX;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $num = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $value,
        };
    }
}
