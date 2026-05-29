<?php

declare(strict_types=1);

namespace App\Domains\Files\Support;

use App\Domains\Files\Events\CompanyFilesChanged;
use Illuminate\Support\Facades\Cache;

/**
 * One source of truth for "the company files for tenant X changed":
 *
 *   - Bump a monotonic version counter kept in the shared cache.
 *   - Broadcast that bump on the tenant's private files channel so every
 *     connected client reloads its Inertia props.
 *   - The same version keys the Cache::remember() that wraps listing
 *     payloads, so concurrent readers get a 5-min-cached response that
 *     self-invalidates the moment the next mutation lands.
 *
 * Keep every mutation path (upload, folder create, rename, move, delete,
 * share, unshare, admin unlink) calling `bump()` exactly once after the
 * transaction commits — firing mid-transaction would broadcast state the
 * database hasn't persisted yet if the outer work then rolls back.
 */
final class CompanyFilesCache
{
    private const VERSION_KEY_PREFIX = 'company_files_version:';

    private const LIST_TTL_SECONDS = 300;

    public static function version(int $workspaceId): int
    {
        return (int) Cache::get(self::versionKey($workspaceId), 1);
    }

    /**
     * Advance the tenant's version counter atomically and notify every
     * connected member. Returns the new version so callers that need to
     * include it in a response payload (e.g. the initial Inertia render)
     * can avoid a second cache read.
     *
     * We rely on Cache::increment so two concurrent bumps don't collapse
     * into a single version number — that would let one client miss a
     * CompanyFilesChanged event because its `lastVersion` check rejects
     * the duplicate version. `add()` seeds the counter when it's missing,
     * then increment returns the new value.
     */
    public static function bump(int $workspaceId, string $reason, ?int $folderId = null): int
    {
        $key = self::versionKey($workspaceId);

        // `add` is atomic-check-and-set: only seeds when absent. Any
        // concurrent caller that seeds first is fine — the following
        // increment steps over that seed value.
        Cache::add($key, 1, now()->addYear());
        $next = (int) Cache::increment($key);

        // Some file/array drivers used in tests/dev don't implement
        // increment; fall back to a read-modify-write path. Under the
        // array driver this still works within a single request.
        if ($next <= 0) {
            $next = self::version($workspaceId) + 1;
            Cache::put($key, $next, now()->addYear());
        }

        event(new CompanyFilesChanged(
            workspaceId: $workspaceId,
            reason: $reason,
            version: $next,
            folderId: $folderId,
        ));

        return $next;
    }

    /**
     * Cache a listing under `(tenant, version, folder, search)`. The key
     * includes the version, so a bump naturally invalidates every cached
     * listing for the tenant without an explicit forget() walk.
     *
     * @template T
     *
     * @param  callable(): T  $builder
     * @return T
     */
    public static function rememberList(int $workspaceId, ?int $folderId, ?string $search, callable $builder)
    {
        $key = sprintf(
            'company_files_list:%d:v%d:f%s:q%s',
            $workspaceId,
            self::version($workspaceId),
            $folderId ?? 'root',
            $search === null || $search === '' ? 'x' : md5($search),
        );

        return Cache::remember($key, self::LIST_TTL_SECONDS, $builder);
    }

    private static function versionKey(int $workspaceId): string
    {
        return self::VERSION_KEY_PREFIX.$workspaceId;
    }
}
