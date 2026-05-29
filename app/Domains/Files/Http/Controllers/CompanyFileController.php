<?php

declare(strict_types=1);

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Events\FileItemUpdated;
use App\Domains\Files\Http\Resources\CompanyFileItemResource;
use App\Domains\Files\Jobs\ExtractFileMetadata;
use App\Domains\Files\Jobs\GenerateDocumentPreview;
use App\Domains\Files\Jobs\GenerateImagePreview;
use App\Domains\Files\Jobs\GenerateVideoPreview;
use App\Domains\Files\Models\CompanyFileLink;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Files\Support\CompanyFilesCache;
use App\Domains\Files\Support\UploadLimits;
use App\Domains\Notifications\Notifications\CompanyFileDeletedByAdminNotification;
use App\Domains\Notifications\Notifications\CompanyFileUnlinkedByAdminNotification;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Company-shared files area. Lives alongside the personal FileItemController
 * but scopes differently — no user_id filter, ownership carried on the row
 * itself. See app/Http/Resources/CompanyFileItemResource for the payload
 * shape returned to the Vue layer.
 */
class CompanyFileController extends Controller
{
    public function __construct(private readonly StorageUsageService $usage) {}

    public function index(Request $request, ?FileItem $folder = null): Response
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($tenant, $user);

        if ($folder !== null && $folder->exists) {
            $this->assertCompanyFolder($folder, $tenant);
        }

        $parentId = $folder?->id;
        $search = $request->string('q')->toString();
        $canManage = $this->canManageCompanyFiles($user, $tenant);

        // Cache the resolved resource payload per (tenant, version, folder,
        // search). Every mutation bumps the tenant's version via
        // CompanyFilesCache::bump, so cached entries become unreachable the
        // moment something changes — no explicit forget() walk needed. The
        // `can_manage` column is resolved per-user from permission state, so
        // we re-map that after the cache read.
        $cached = CompanyFilesCache::rememberList(
            tenantId: $tenant->id,
            folderId: $parentId,
            search: $search,
            builder: fn () => $this->buildListingPayload($request, $tenant, $parentId, $search),
        );

        // `can_manage` depends on the *viewer*, so it's patched post-cache.
        // Every other field in the resource payload is user-agnostic and
        // safe to share across members.
        $items = array_map(function (array $row) use ($canManage, $user): array {
            $ownerId = $row['owner']['id'] ?? null;
            $row['can_manage'] = $canManage || ($ownerId !== null && $ownerId === $user->id);

            return $row;
        }, $cached['items']);

        $usedBytes = $cached['used_bytes'];

        return Inertia::render('Files/Company/Index', [
            'items' => $items,
            'breadcrumbs' => $this->breadcrumbs($folder),
            'current_folder' => $folder?->only(['id', 'name', 'uuid']),
            'usage' => [
                'used_bytes' => $usedBytes,
                'quota_bytes' => $this->resolveCompanyQuotaBytes($tenant),
                'quota_unlimited' => $this->isCompanyQuotaUnlimited($tenant),
                'percent' => $this->percentCompany($tenant, $usedBytes),
            ],
            'can_manage' => $canManage,
            'permissions' => [
                'upload' => (bool) $user->can('upload to company files'),
                'create_folder' => (bool) $user->can('create company folders'),
                'manage' => $canManage,
            ],
            'search' => $search ?: null,
            'realtime_version' => CompanyFilesCache::version($tenant->id),
        ]);
    }

    /**
     * Build the cacheable listing payload: fully-serialized resource
     * arrays plus the company-bucket bytes total. Every field stored here
     * is user-agnostic — the per-viewer `can_manage` flag is re-stamped
     * after the cache read in index(). This way the cache holds one
     * payload per (tenant, folder, search) shared across members.
     *
     * @return array{items: array<int, array<string, mixed>>, used_bytes: int}
     */
    private function buildListingPayload(Request $request, Tenant $tenant, ?int $parentId, string $search): array
    {
        $native = FileItem::query()
            ->where('workspace_id', $tenant->id)
            ->where('scope', FileItem::SCOPE_COMPANY)
            ->where('parent_id', $parentId)
            ->with(['media', 'user'])
            ->get();

        $linkRows = CompanyFileLink::query()
            ->where('workspace_id', $tenant->id)
            ->where('company_parent_id', $parentId)
            ->with(['fileItem.media', 'fileItem.user', 'sharedBy'])
            ->get();

        $resources = [];

        foreach ($native as $item) {
            /** @var FileItem $item */
            $resources[] = new CompanyFileItemResource($item, null, false);
        }

        foreach ($linkRows as $link) {
            /** @var CompanyFileLink $link */
            // fileItem is non-null at the DB level (cascadeOnDelete FK) —
            // no defensive check needed.
            $resources[] = new CompanyFileItemResource($link->fileItem, $link, false);
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = array_map(fn (CompanyFileItemResource $r) => $r->toArray($request), $resources);

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, fn (array $row) => isset($row['name'])
                && str_contains(mb_strtolower((string) $row['name']), $needle)));
        }

        // Folders before files, natural-cased name within each group.
        usort($rows, function (array $a, array $b): int {
            $af = ($a['type'] ?? '') === FileItem::TYPE_FOLDER ? 0 : 1;
            $bf = ($b['type'] ?? '') === FileItem::TYPE_FOLDER ? 0 : 1;

            return $af <=> $bf ?: strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return [
            'items' => $rows,
            'used_bytes' => $this->usage->usedBytesForTenantCompany($tenant),
        ];
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($tenant, $user);
        abort_unless($user->can('create company folders'), 403, __('files.permission_denied'));

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => ['nullable', 'integer', $this->existsFileItemRule()],
        ]);

        if (isset($data['parent_id'])) {
            $parent = FileItem::findOrFail($data['parent_id']);
            $this->assertCompanyFolder($parent, $tenant);
        }

        $folder = FileItem::create([
            'workspace_id' => $tenant->id,
            'user_id' => $user->id,
            'owner_type' => $tenant->getMorphClass(),
            'owner_id' => $tenant->id,
            'parent_id' => $data['parent_id'] ?? null,
            'type' => FileItem::TYPE_FOLDER,
            'scope' => FileItem::SCOPE_COMPANY,
            'name' => $this->uniqueNameCompany($tenant->id, $data['parent_id'] ?? null, $data['name']),
        ]);

        CompanyFilesCache::bump($tenant->id, 'folder_created', $data['parent_id'] ?? null);

        return back()->with('success', __('files.folder_created', ['name' => $folder->name]));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($tenant, $user);
        abort_unless($user->can('upload to company files'), 403, __('files.permission_denied'));

        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'file|max:'.UploadLimits::maxUploadKilobytes(),
            'parent_id' => ['nullable', 'integer', $this->existsFileItemRule()],
        ]);

        $parentId = $request->integer('parent_id') ?: null;
        if ($parentId !== null) {
            $parent = FileItem::findOrFail($parentId);
            $this->assertCompanyFolder($parent, $tenant);
        }

        $created = 0;
        $previewTargets = [];
        $videoTargets = [];
        $imageTargets = [];
        $allTargets = [];
        DB::connection((string) config('tenancy.database.central_connection'))->transaction(function () use ($request, $tenant, $user, $parentId, &$created, &$previewTargets, &$videoTargets, &$imageTargets, &$allTargets): void {
            foreach ($request->file('files', []) as $file) {
                $name = $this->uniqueNameCompany($tenant->id, $parentId, $file->getClientOriginalName());
                $size = $file->getSize();
                $item = FileItem::create([
                    'workspace_id' => $tenant->id,
                    'user_id' => $user->id,
                    'owner_type' => $tenant->getMorphClass(),
                    'owner_id' => $tenant->id,
                    'parent_id' => $parentId,
                    'type' => FileItem::TYPE_FILE,
                    'scope' => FileItem::SCOPE_COMPANY,
                    'name' => $name,
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $size === false ? 0 : (int) $size,
                ]);

                $item->addMedia($file)->toMediaCollection('file');
                $created++;
                $allTargets[] = $item->id;

                if (! $item->isTextPreviewable()
                    && in_array((string) $item->mime_type, config('files.preview_mime_types', []), true)) {
                    $previewTargets[] = $item->id;
                }
                if ($item->isVideo()) {
                    $videoTargets[] = $item->id;
                }
                if ($item->needsImagePreview()) {
                    $imageTargets[] = $item->id;
                }
            }
        });

        foreach ($previewTargets as $id) {
            GenerateDocumentPreview::dispatch($id);
        }
        foreach ($videoTargets as $id) {
            GenerateVideoPreview::dispatch($id);
        }
        foreach ($imageTargets as $id) {
            GenerateImagePreview::dispatch($id);
        }
        foreach ($allTargets as $id) {
            ExtractFileMetadata::dispatch($id);
        }
        foreach (array_unique(array_merge($previewTargets, $videoTargets, $imageTargets)) as $id) {
            $fresh = FileItem::with('media')->find($id);
            if ($fresh) {
                event(new FileItemUpdated($fresh));
            }
        }

        $this->usage->recomputeForTenant($tenant);
        CompanyFilesCache::bump($tenant->id, 'files_uploaded', $parentId);

        return back()->with('success', __('files.upload_success', ['count' => $created]));
    }

    public function update(Request $request, FileItem $file): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($tenant, $user);
        $this->assertCompanyItem($file, $tenant);

        // Renaming/moving a native company item: owner or admin.
        $isOwner = $file->user_id === $user->id;
        $canManage = $this->canManageCompanyFiles($user, $tenant);
        abort_unless($isOwner || $canManage, 403, __('files.permission_denied'));
        abort_unless($user->can('rename files'), 403, __('files.permission_denied'));

        $data = $request->validate([
            'name' => 'sometimes|string|min:1|max:255',
            'parent_id' => ['sometimes', 'nullable', 'integer', $this->existsFileItemRule()],
        ]);

        if (array_key_exists('parent_id', $data)) {
            if ($data['parent_id'] !== null) {
                if ((int) $data['parent_id'] === (int) $file->id) {
                    abort(422, __('files.cannot_self_parent'));
                }
                $parent = FileItem::findOrFail($data['parent_id']);
                $this->assertCompanyFolder($parent, $tenant);
                if ($file->isFolder() && $this->isDescendantOf($parent, $file)) {
                    abort(422, __('files.cannot_move_into_descendant'));
                }
            }
            $file->parent_id = $data['parent_id'];
        }

        if (array_key_exists('name', $data) && $data['name'] !== '') {
            $file->name = $this->uniqueNameCompany($tenant->id, $file->parent_id, $data['name'], $file->id);
        }

        $file->save();

        CompanyFilesCache::bump($tenant->id, 'item_updated', $file->parent_id);

        return back()->with('success', __('files.updated'));
    }

    public function destroy(Request $request, FileItem $file): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($tenant, $user);
        $this->assertCompanyItem($file, $tenant);

        $isOwner = $file->user_id === $user->id;
        $canManage = $this->canManageCompanyFiles($user, $tenant);

        abort_unless($isOwner || $canManage, 403, __('files.permission_denied'));

        [$notifyInApp, $notifyEmail] = $this->notifyFlags($request);
        // `user_id` is NOT NULL on file_items, so the uploader relation is
        // always set — no defensive null-check needed. (For company files the
        // polymorphic owner is the Tenant; `$file->user` is the uploader.)
        $uploader = $file->user;

        $parentIdBeforeDelete = $file->parent_id;
        $file->delete();
        $this->usage->recomputeForTenant($tenant);
        CompanyFilesCache::bump($tenant->id, 'item_deleted', $parentIdBeforeDelete);
        // Uploader's personal denormalized usage doesn't change for native
        // company files (they weren't billable to the user), but keeping the
        // recompute path consistent costs one query and avoids drift.
        $this->usage->recomputeForUser($uploader);

        if (! $isOwner && ($notifyInApp || $notifyEmail)) {
            $uploader->notify(new CompanyFileDeletedByAdminNotification(
                fileName: $file->name,
                tenantId: $tenant->id,
                tenantName: $tenant->name,
                actorName: $user->fullName(),
                sendEmail: $notifyEmail,
                sendDatabase: $notifyInApp,
            ));
        }

        return back()->with('success', __('files.deleted'));
    }

    /**
     * Remove a personal-file link from the company tree. The underlying
     * personal file is left intact — the user still has it in My Files.
     */
    public function unlink(Request $request, CompanyFileLink $link): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($tenant, $user);

        if ($link->workspace_id !== $tenant->id) {
            abort(404);
        }

        // The fileItem FK cascades on hard-delete, BUT FileItem uses
        // SoftDeletes — the default belongsTo relation respects the
        // soft-delete scope and resolves to null once the owner trashes
        // their personal file. Load with `withTrashed()` so the admin
        // can still unlink a stale reference during the 30-day retention
        // window.
        $fileItem = FileItem::withTrashed()->find($link->file_item_id);
        abort_if($fileItem === null, 404);

        // file_items.user_id is NOT NULL (see migration), so the relation
        // is always resolved. phpstan enforces this from the phpdoc.
        // For company files the polymorphic owner is the Tenant; `$fileItem->user`
        // is the uploader, which is what we compare and notify here.
        $uploader = $fileItem->user;
        $isUploader = $uploader->id === $user->id;
        $canManage = $this->canManageCompanyFiles($user, $tenant);

        abort_unless($isUploader || $canManage, 403, __('files.permission_denied'));

        [$notifyInApp, $notifyEmail] = $this->notifyFlags($request);
        $fileName = $fileItem->name;

        $link->delete();
        $this->usage->recomputeForTenant($tenant);
        CompanyFilesCache::bump($tenant->id, 'link_removed');

        // Skip the uploader notification when the file has been trashed —
        // they're already aware they deleted it, and a separate admin-unlink
        // notification would only add noise.
        if (! $isUploader && ! $fileItem->trashed() && ($notifyInApp || $notifyEmail)) {
            $uploader->notify(new CompanyFileUnlinkedByAdminNotification(
                fileName: $fileName,
                tenantId: $tenant->id,
                tenantName: $tenant->name,
                actorName: $user->fullName(),
                sendEmail: $notifyEmail,
                sendDatabase: $notifyInApp,
            ));
        }

        return back()->with('success', __('files.company_unlinked'));
    }

    public function download(Request $request, FileItem $file): BinaryFileResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($tenant, $user);

        // Allow download from either a native company file in this tenant,
        // or a personal file linked into this tenant's company tree.
        $linked = CompanyFileLink::query()
            ->where('workspace_id', $tenant->id)
            ->where('file_item_id', $file->id)
            ->exists();

        $native = $file->scope === FileItem::SCOPE_COMPANY && $file->workspace_id === $tenant->id;

        if (! $native && ! $linked) {
            abort(403, __('files.permission_denied'));
        }

        if ($file->isFolder()) {
            abort(404);
        }

        $media = $file->getFirstMedia('file');
        if (! $media) {
            abort(404);
        }

        // `variant=converted` serves the normalized JPEG rendered from a
        // RAW / TIFF / HEIC original (the `image_preview` collection).
        if ($request->string('variant')->toString() === 'converted') {
            $preview = $file->getFirstMedia('image_preview');
            if ($preview && is_file($preview->getPath())) {
                $ext = pathinfo($preview->getPath(), PATHINFO_EXTENSION) ?: 'jpg';

                return response()->download($preview->getPath(), pathinfo($file->name, PATHINFO_FILENAME).'.'.$ext);
            }
        }

        $requested = $request->string('size')->toString();
        if ($requested !== '' && $requested !== 'original' && $media->hasGeneratedConversion($requested)) {
            $path = $media->getPath($requested);
            $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'webp';
            $filename = pathinfo($file->name, PATHINFO_FILENAME).'-'.$requested.'.'.$ext;
        } else {
            $path = $media->getPath();
            $filename = $file->name;
        }

        if (! is_file($path)) {
            abort(404);
        }

        return response()->download($path, $filename);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function currentTenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('customer');
        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        $slug = config('tenancy.default_customer_slug');
        $fallback = $slug ? Tenant::query()->where('slug', $slug)->first() : null;

        if (! $fallback) {
            abort(404);
        }

        return $fallback;
    }

    private function assertFeatureAvailable(Tenant $tenant, User $user): void
    {
        // The app-level flag is the master kill switch for everything
        // file-related. Per-customer, the shared workspace lives behind
        // its own `company_files_enabled` toggle — independent of the
        // personal `files_feature_enabled`, so a customer can run with
        // one or the other or both.
        if (! AppSetting::current()->files_feature_enabled) {
            abort(404);
        }

        if (! $tenant->company_files_enabled) {
            abort(404);
        }

        abort_unless($user->can('view company files'), 403, __('files.permission_denied'));
    }

    private function assertCompanyFolder(FileItem $folder, Tenant $tenant): void
    {
        if (
            $folder->workspace_id !== $tenant->id
            || $folder->scope !== FileItem::SCOPE_COMPANY
            || ! $folder->isFolder()
        ) {
            abort(404);
        }
    }

    private function assertCompanyItem(FileItem $item, Tenant $tenant): void
    {
        if ($item->workspace_id !== $tenant->id || $item->scope !== FileItem::SCOPE_COMPANY) {
            abort(404);
        }
    }

    private function canManageCompanyFiles(User $user, Tenant $tenant): bool
    {
        // Super admins always qualify. Otherwise the per-customer permission
        // (scoped via the tenancy middleware) governs admin actions.
        return $user->isSuperAdmin() || (bool) $user->can('manage company files');
    }

    private function existsFileItemRule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }
            if (! FileItem::whereKey($value)->exists()) {
                $fail(__('validation.exists', ['attribute' => $attribute]));
            }
        };
    }

    private function uniqueNameCompany(int $tenantId, ?int $parentId, string $name, ?int $ignoreId = null): string
    {
        $base = $name;
        $i = 1;
        while (FileItem::query()
            ->where('workspace_id', $tenantId)
            ->where('scope', FileItem::SCOPE_COMPANY)
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $name = $this->appendSuffix($base, ++$i);
        }

        return $name;
    }

    private function appendSuffix(string $name, int $n): string
    {
        $dot = strrpos($name, '.');
        if ($dot === false || $dot === 0) {
            return $name." ({$n})";
        }

        return substr($name, 0, $dot)." ({$n})".substr($name, $dot);
    }

    private function isDescendantOf(FileItem $candidate, FileItem $possibleAncestor): bool
    {
        $cursor = $candidate;
        while ($cursor->parent_id !== null) {
            if ($cursor->parent_id === $possibleAncestor->id) {
                return true;
            }
            $cursor = FileItem::find($cursor->parent_id);
            if (! $cursor) {
                return false;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function breadcrumbs(?FileItem $folder): array
    {
        $trail = [];
        $cursor = $folder;
        while ($cursor) {
            array_unshift($trail, [
                'id' => $cursor->id,
                'name' => $cursor->name,
            ]);
            $cursor = $cursor->parent;
        }

        return $trail;
    }

    /**
     * Unpack the admin-delete notify flags from the request. Absent/false on
     * either = silent operation. Owner-initiated deletes ignore these.
     *
     * @return array{0: bool, 1: bool} [notifyInApp, notifyEmail]
     */
    private function notifyFlags(Request $request): array
    {
        return [
            (bool) $request->boolean('notify_in_app'),
            (bool) $request->boolean('notify_email'),
        ];
    }

    private function resolveCompanyQuotaBytes(Tenant $tenant): ?int
    {
        $quota = $tenant->storage_quota_bytes;
        if ($quota === null || (int) $quota < 0) {
            return null;
        }

        return (int) $quota;
    }

    private function isCompanyQuotaUnlimited(Tenant $tenant): bool
    {
        $quota = $tenant->storage_quota_bytes;

        return $quota === null || (int) $quota < 0;
    }

    private function percentCompany(Tenant $tenant, int $usedBytes): float
    {
        $quota = $this->resolveCompanyQuotaBytes($tenant);
        if ($quota === null || $quota <= 0) {
            return 0.0;
        }

        return round(($usedBytes / $quota) * 100, 2);
    }
}
