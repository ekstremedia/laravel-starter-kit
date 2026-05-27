<?php

declare(strict_types=1);

namespace App\Domains\Files\Models\Concerns;

/**
 * Adopt on any FileOwner entity that should carry a per-row storage cap with
 * inheritance. The model needs a nullable `file_quota_bytes` column and a
 * `storage_used_bytes` column (see the assets migration for the shape).
 *
 * Resolution (see StorageUsageService::effectiveQuota):
 *   per-row override (file_quota_bytes) → app default_entity_storage_bytes → unlimited
 *
 * Convention for `file_quota_bytes`: null = inherit, -1 = explicit unlimited,
 * 0 = blocked, N>0 = byte cap.
 */
trait HasFileQuota
{
    /**
     * The per-row storage override in bytes, or null to inherit the default.
     */
    public function fileQuotaBytes(): ?int
    {
        $value = $this->getAttribute('file_quota_bytes');

        return $value === null ? null : (int) $value;
    }
}
