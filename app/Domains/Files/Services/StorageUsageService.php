<?php

declare(strict_types=1);

namespace App\Domains\Files\Services;

use App\Domains\Chat\Models\Message;
use App\Domains\Files\Models\FileItem;
use App\Domains\Notifications\Notifications\ApproachingStorageLimitNotification;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Two-tier storage accounting:
 *
 *   - **Billable** — uploads where the FileItem is in the `file` collection
 *     and is owned by the model in question. Owners are polymorphic (User,
 *     Tenant, Building, etc.). This is what quota enforcement and the /files
 *     usage bar use. Scoped per-(owner, tenant) so each customer gets its
 *     own bucket.
 *
 *   - **System total** — every row in the `media` table, regardless of owner
 *     or collection. Used by the admin dashboard for capacity planning.
 *     Previews (doc_preview, video_preview, video_web), chat attachments, and
 *     user avatars live here only — never in billable.
 *
 * Image conversions (thumb/medium/large/xlarge) are stored as files on disk
 * under `{media.id}/conversions/` without their own DB row, so they're
 * naturally excluded from every query here.
 */
class StorageUsageService
{
    /** User-uploaded originals only — the collection that counts toward quotas. */
    private const BILLABLE_COLLECTION = 'file';

    // -----------------------------------------------------------------------
    // Polymorphic-owner API (preferred for new code)
    // -----------------------------------------------------------------------

    /**
     * Billable bytes across every tenant for this owner.
     */
    public function usedBytesForOwner(Model $owner): int
    {
        return (int) $this->billableQuery($owner, null)->sum('media.size');
    }

    /**
     * Billable bytes for (owner, tenant).
     */
    public function usedBytesForOwnerInTenant(Model $owner, Tenant $tenant): int
    {
        return (int) $this->billableQuery($owner, $tenant)->sum('media.size');
    }

    /**
     * Effective storage quota for this owner in this tenant. Returns `null`
     * for unlimited, `0` for hard-disabled, positive bytes otherwise.
     *
     * Resolution order depends on owner type:
     *   - User    → user override → tenant default → app default → unlimited
     *   - Tenant  → tenant.storage_quota_bytes (no fallback chain)
     *   - other   → null (caller must override via service binding if needed)
     */
    public function effectiveQuota(Model $owner, ?Tenant $tenant = null): ?int
    {
        if ($owner instanceof User && $tenant !== null) {
            return $this->effectivePersonalQuota($owner, $tenant);
        }

        if ($owner instanceof Tenant) {
            $quota = $owner->storage_quota_bytes;
            if ($quota === null) {
                return null;
            }

            return (int) $quota < 0 ? null : (int) $quota;
        }

        // Generic file-owning entities (Asset, future Vehicle/Building…) that
        // carry a per-row override via the HasFileQuota concern. Chain:
        //   entity override → app default_entity_storage_bytes → unlimited
        // Convention: null = inherit, -1 = explicit unlimited, N>=0 = byte cap.
        if (method_exists($owner, 'fileQuotaBytes')) {
            $override = $owner->fileQuotaBytes();
            if ($override !== null) {
                return $override < 0 ? null : $override;
            }

            $appDefault = AppSetting::current()->default_entity_storage_bytes;
            if ($appDefault === null) {
                return null;
            }

            return (int) $appDefault < 0 ? null : (int) $appDefault;
        }

        return null;
    }

    /**
     * Remaining bytes for this owner in this tenant, or `null` for unlimited.
     */
    public function remainingBytesForOwner(Model $owner, ?Tenant $tenant = null): ?int
    {
        $quota = $this->effectiveQuota($owner, $tenant);

        if ($quota === null) {
            return null;
        }

        $used = $tenant !== null
            ? $this->usedBytesForOwnerInTenant($owner, $tenant)
            : $this->usedBytesForOwner($owner);

        return max(0, $quota - $used);
    }

    /**
     * Refresh whichever denormalized column tracks this owner's used bytes.
     * Returns the freshly-computed total.
     */
    public function recomputeForOwner(Model $owner): int
    {
        if ($owner instanceof User) {
            return $this->recomputeForUser($owner);
        }

        if ($owner instanceof Tenant) {
            return $this->recomputeForTenant($owner);
        }

        $used = $this->usedBytesForOwner($owner);

        // Entities using HasFileQuota carry a denormalized storage_used_bytes
        // column (see the assets migration) — keep it in sync.
        if (method_exists($owner, 'fileQuotaBytes')) {
            $owner->forceFill(['storage_used_bytes' => $used])->saveQuietly();
        }

        return $used;
    }

    // -----------------------------------------------------------------------
    // User-specific shims (kept so existing call-sites keep working)
    // -----------------------------------------------------------------------

    public function usedBytesForUser(User $user): int
    {
        return $this->usedBytesForOwner($user);
    }

    public function usedBytesForUserInTenant(User $user, Tenant $tenant): int
    {
        return $this->usedBytesForOwnerInTenant($user, $tenant);
    }

    /**
     * Coarse-grained mime breakdown of the user's billable storage.
     *
     * @return array<string, int>
     */
    public function breakdownByTypeForUser(User $user): array
    {
        $rows = $this->billableQuery($user, null)
            ->selectRaw('media.mime_type, SUM(media.size) as total')
            ->groupBy('media.mime_type')
            ->get();

        $out = ['image' => 0, 'video' => 0, 'pdf' => 0, 'document' => 0, 'other' => 0];
        foreach ($rows as $row) {
            $out[$this->categorize((string) $row->mime_type)] += (int) $row->total;
        }

        return $out;
    }

    /**
     * @return array<int, array{user_id: int, name: string, email: string, bytes: int}>
     */
    public function topUsers(int $limit = 20, ?string $search = null): array
    {
        $query = User::query();

        if ($search !== null && $search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $like = "%{$escaped}%";
            $query->where(function ($q) use ($like): void {
                $q->where('first_name', 'ilike', $like)
                    ->orWhere('last_name', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like);
            });
        }

        return $query->orderByDesc('storage_used_bytes')
            ->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'email', 'storage_used_bytes'])
            ->map(fn (User $u) => [
                'user_id' => (int) $u->id,
                'name' => $u->fullName(),
                'email' => $u->email,
                'bytes' => (int) $u->storage_used_bytes,
            ])
            ->all();
    }

    /**
     * Billable usage per customer (tenant). Sums the `file` collection only.
     *
     * @return array<int, array{workspace_id: int, name: string, slug: string, bytes: int, file_count: int}>
     */
    public function usageByTenant(?string $search = null, int $limit = 50): array
    {
        $conn = $this->central();

        $usage = DB::connection($conn)
            ->table('media')
            ->join('file_items', function ($join): void {
                $join->on('file_items.id', '=', 'media.model_id')
                    ->where('media.model_type', (new FileItem)->getMorphClass());
            })
            ->where('media.collection_name', self::BILLABLE_COLLECTION)
            ->selectRaw('file_items.workspace_id as workspace_id, SUM(media.size) as bytes, COUNT(*) as file_count')
            ->groupBy('file_items.workspace_id')
            ->get()
            ->keyBy('workspace_id');

        $tenantsQuery = Tenant::query()->orderBy('name');

        if ($search !== null && $search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $like = "%{$escaped}%";
            $tenantsQuery->where(function ($q) use ($like): void {
                $q->where('name', 'ilike', $like)->orWhere('slug', 'ilike', $like);
            });
        }

        /** @var Collection<int, Tenant> $tenants */
        $tenants = $tenantsQuery->limit($limit)->get(['id', 'name', 'slug']);

        return $tenants
            ->map(function (Tenant $t) use ($usage): array {
                $row = $usage->get($t->id);

                return [
                    'workspace_id' => (int) $t->id,
                    'name' => (string) $t->name,
                    'slug' => (string) $t->slug,
                    'bytes' => (int) ($row->bytes ?? 0),
                    'file_count' => (int) ($row->file_count ?? 0),
                ];
            })
            ->sortByDesc('bytes')
            ->values()
            ->all();
    }

    /** System-wide total bytes (includes previews, chat, avatars). */
    public function systemTotalBytes(): int
    {
        return (int) Media::on($this->central())->sum('size');
    }

    /**
     * @return array<string, int>
     */
    public function systemBreakdownByType(): array
    {
        $rows = DB::connection($this->central())
            ->table('media')
            ->selectRaw('mime_type, SUM(size) as total')
            ->groupBy('mime_type')
            ->get();

        $out = ['image' => 0, 'video' => 0, 'pdf' => 0, 'document' => 0, 'other' => 0];
        foreach ($rows as $row) {
            $out[$this->categorize((string) $row->mime_type)] += (int) $row->total;
        }

        return $out;
    }

    /**
     * @return array<string, int> bucket => bytes
     */
    public function systemBreakdownByCollection(): array
    {
        $rows = DB::connection($this->central())
            ->table('media')
            ->selectRaw('model_type, collection_name, SUM(size) as total')
            ->groupBy('model_type', 'collection_name')
            ->get();

        $out = [
            'billable' => 0,
            'doc_preview' => 0,
            'video_preview' => 0,
            'video_web' => 0,
            'chat' => 0,
            'avatar' => 0,
            'other' => 0,
        ];

        foreach ($rows as $row) {
            $bytes = (int) $row->total;
            $bucket = $this->bucketFor((string) $row->model_type, (string) $row->collection_name);
            $out[$bucket] += $bytes;
        }

        return $out;
    }

    /**
     * Billable file storage grouped by the polymorphic owner entity type
     * (personal User files, company Tenant files, Asset documents, …). Powers
     * the "storage by entity type" panel on the admin dashboard.
     *
     * @return array<int, array{type: string, file_count: int, bytes: int}>
     */
    public function systemBreakdownByOwnerType(): array
    {
        $rows = DB::connection($this->central())
            ->table('media')
            ->join('file_items', function ($join): void {
                $join->on('file_items.id', '=', 'media.model_id')
                    ->where('media.model_type', (new FileItem)->getMorphClass());
            })
            ->where('media.collection_name', self::BILLABLE_COLLECTION)
            ->selectRaw('file_items.owner_type as type, COUNT(*) as file_count, SUM(media.size) as bytes')
            ->groupBy('file_items.owner_type')
            ->get();

        return $rows->map(fn ($row) => [
            'type' => (string) $row->type,
            'file_count' => (int) $row->file_count,
            'bytes' => (int) $row->bytes,
        ])->sortByDesc('bytes')->values()->all();
    }

    public function recomputeForUser(User $user): int
    {
        $bytes = $this->usedBytesForOwner($user);

        if ((int) $user->storage_used_bytes !== $bytes) {
            $user->forceFill(['storage_used_bytes' => $bytes])->saveQuietly();
        }

        return $bytes;
    }

    /**
     * Remaining bytes in this tenant under the user's quota. `null` when
     * quota is unlimited, `0` when quota is disabled or cap reached.
     */
    public function remainingBytesInTenant(User $user, Tenant $tenant): ?int
    {
        return $this->remainingBytesForOwner($user, $tenant);
    }

    /** 0-100(+) percent of quota consumed by this tenant's files. */
    public function percentUsedInTenant(User $user, Tenant $tenant): float
    {
        $quota = $this->effectivePersonalQuota($user, $tenant);

        if ($quota === null || $quota <= 0) {
            return 0.0;
        }

        return round(($this->usedBytesForOwnerInTenant($user, $tenant) / $quota) * 100, 2);
    }

    /**
     * Resolve a user's effective personal-storage quota following the 3-tier
     * fallback order: user override → customer default → global default →
     * unlimited.
     *
     * Sentinels:
     *   - `-1` at any level = explicit unlimited (returns null).
     *   - `0` is a hard block (returns 0) — never inherited past.
     *   - null at a given level means "defer to the next level".
     */
    public function effectivePersonalQuota(User $user, Tenant $tenant): ?int
    {
        $override = $user->settings()->resolved()['storage_quota_override'] ?? null;
        if ($override !== null) {
            $override = (int) $override;
            if ($override < 0) {
                return null;
            }

            return $override;
        }

        $tenantDefault = $tenant->default_member_storage_bytes;
        if ($tenantDefault !== null) {
            $tenantDefault = (int) $tenantDefault;
            if ($tenantDefault < 0) {
                return null;
            }

            return $tenantDefault;
        }

        $appDefault = AppSetting::current()->default_personal_storage_bytes;
        if ($appDefault !== null) {
            $appDefault = (int) $appDefault;
            if ($appDefault < 0) {
                return null;
            }

            return $appDefault;
        }

        return null;
    }

    /**
     * Bytes consumed by the company-shared bucket for this tenant: the sum of
     * Tenant-owned uploads plus every personal file currently linked into
     * this tenant's company tree. A linked file counts toward BOTH its
     * owner's personal bucket and the company bucket — intentional: the
     * shared copy increases the tenant's footprint without affecting the
     * user's own storage accounting.
     */
    public function usedBytesForTenantCompany(Tenant $tenant): int
    {
        $conn = $this->central();

        $native = DB::connection($conn)
            ->table('media')
            ->join('file_items', function ($join): void {
                $join->on('file_items.id', '=', 'media.model_id')
                    ->where('media.model_type', (new FileItem)->getMorphClass());
            })
            ->where('media.collection_name', self::BILLABLE_COLLECTION)
            ->where('file_items.owner_type', $tenant->getMorphClass())
            ->where('file_items.owner_id', $tenant->id)
            ->where('file_items.workspace_id', $tenant->id)
            ->sum('media.size');

        $linked = DB::connection($conn)
            ->table('media')
            ->join('file_items', function ($join): void {
                $join->on('file_items.id', '=', 'media.model_id')
                    ->where('media.model_type', (new FileItem)->getMorphClass());
            })
            ->join('company_file_links', 'company_file_links.file_item_id', '=', 'file_items.id')
            ->where('media.collection_name', self::BILLABLE_COLLECTION)
            ->where('company_file_links.workspace_id', $tenant->id)
            ->sum('media.size');

        return (int) $native + (int) $linked;
    }

    /**
     * Remaining bytes in the company bucket. `null` = unlimited,
     * `0` = disabled or cap reached.
     */
    public function remainingBytesForTenantCompany(Tenant $tenant): ?int
    {
        $quota = $tenant->storage_quota_bytes;

        if ($quota === null) {
            return null;
        }

        $quota = (int) $quota;

        if ($quota < 0) {
            return null;
        }

        $used = $this->usedBytesForTenantCompany($tenant);

        return max(0, $quota - $used);
    }

    /** Sync the denormalized `tenants.storage_used_bytes` column from media. */
    public function recomputeForTenant(Tenant $tenant): int
    {
        $bytes = $this->usedBytesForTenantCompany($tenant);

        if ((int) $tenant->storage_used_bytes !== $bytes) {
            $tenant->forceFill(['storage_used_bytes' => $bytes])->saveQuietly();
        }

        return $bytes;
    }

    /**
     * Fire threshold alerts for this user in this tenant. Each tenant has
     * its own slot in `storage_last_alerted_threshold` (keyed by tenant id),
     * so a user crossing 80% in Company A doesn't suppress an 80% alert for
     * Company B later.
     */
    public function checkAndNotifyThresholds(User $user, Tenant $tenant): void
    {
        $settings = $user->settings();
        $resolved = $settings->resolved();
        $quota = $this->effectivePersonalQuota($user, $tenant);

        if ($quota === null || $quota <= 0) {
            return;
        }

        $used = $this->usedBytesForOwnerInTenant($user, $tenant);
        $percent = ($used / $quota) * 100;

        $thresholdMap = is_array($resolved['storage_last_alerted_threshold'] ?? null)
            ? $resolved['storage_last_alerted_threshold']
            : [];
        $key = (string) $tenant->id;
        $last = $thresholdMap[$key] ?? null;

        $current = match (true) {
            $percent >= 100 => 100,
            $percent >= 95 => 95,
            $percent >= 80 => 80,
            default => null,
        };

        if ($current === null) {
            if (array_key_exists($key, $thresholdMap)) {
                unset($thresholdMap[$key]);
                $settings->merge(['storage_last_alerted_threshold' => $thresholdMap]);
            }

            return;
        }

        if ($last === null || $current > (int) $last) {
            $user->notify(new ApproachingStorageLimitNotification(
                thresholdPercent: $current,
                usedBytes: $used,
                quotaBytes: (int) $quota,
                tenantId: $tenant->id,
                tenantName: $tenant->name,
            ));
            $thresholdMap[$key] = $current;
            $settings->merge(['storage_last_alerted_threshold' => $thresholdMap]);
        }
    }

    private function central(): string
    {
        return (string) config('tenancy.database.central_connection');
    }

    /**
     * Build the billable subquery: media rows from the FileItem `file`
     * collection scoped to one polymorphic owner, optionally narrowed to a
     * single tenant. Previews + chat + avatars are intentionally excluded.
     */
    private function billableQuery(Model $owner, ?Tenant $tenant): Builder
    {
        $conn = $this->central();

        return DB::connection($conn)
            ->table('media')
            ->join('file_items', function ($join): void {
                $join->on('file_items.id', '=', 'media.model_id')
                    ->where('media.model_type', (new FileItem)->getMorphClass());
            })
            ->where('media.collection_name', self::BILLABLE_COLLECTION)
            ->where('file_items.owner_type', $owner->getMorphClass())
            ->where('file_items.owner_id', $owner->getKey())
            ->when($tenant !== null, fn ($q) => $q->where('file_items.workspace_id', $tenant->id));
    }

    private function bucketFor(string $modelType, string $collection): string
    {
        // $modelType is the stored polymorphic morph alias (see the morph map
        // in AppServiceProvider), so compare against getMorphClass(), not the
        // raw ::class FQCN — they diverge once models live under app/Domains.
        if ($modelType === (new FileItem)->getMorphClass()) {
            return match ($collection) {
                'file' => 'billable',
                'doc_preview' => 'doc_preview',
                'video_preview' => 'video_preview',
                'video_web' => 'video_web',
                default => 'other',
            };
        }

        if ($modelType === (new Message)->getMorphClass()) {
            return 'chat';
        }

        if ($modelType === (new User)->getMorphClass()) {
            return 'avatar';
        }

        return 'other';
    }

    private function categorize(string $mime): string
    {
        if ($mime === '') {
            return 'other';
        }
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if ($mime === 'application/pdf') {
            return 'pdf';
        }
        if (str_contains($mime, 'word') || str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet')
            || str_contains($mime, 'presentation') || str_contains($mime, 'opendocument')
            || str_starts_with($mime, 'text/')) {
            return 'document';
        }

        return 'other';
    }
}
