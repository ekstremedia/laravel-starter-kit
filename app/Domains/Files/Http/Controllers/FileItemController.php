<?php

declare(strict_types=1);

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Contracts\FileOwner;
use App\Domains\Files\Events\FileItemUpdated;
use App\Domains\Files\Http\Resources\FileItemResource;
use App\Domains\Files\Jobs\GenerateDocumentPreview;
use App\Domains\Files\Jobs\GenerateVideoPreview;
use App\Domains\Files\Jobs\ShareFolderToCompany;
use App\Domains\Files\Models\CompanyFileLink;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Files\Support\CompanyFilesCache;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileItemController extends Controller
{
    public function __construct(private readonly StorageUsageService $usage) {}

    public function index(Request $request, ?FileItem $folder = null): Response
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $tenant);

        $owner = $this->resolveOwner($request, $user);
        $this->authorizeOwnerAccess($user, $owner, $tenant, view: true);

        if ($folder !== null && $folder->exists) {
            Gate::forUser($user)->authorize('view', [$folder, $tenant]);
            if (! $folder->isFolder()) {
                abort(404);
            }
        }

        $parentId = $folder?->id;

        $query = FileItem::query()
            ->where('tenant_id', $tenant->id)
            ->forOwner($owner)
            ->where('parent_id', $parentId)
            ->with(['media', 'companyLink']);

        if ($search = $request->string('q')->toString()) {
            $escaped = addcslashes($search, '%_\\');
            $driver = DB::connection()->getDriverName();
            $op = $driver === 'pgsql' ? 'ilike' : 'like';
            $query->where('name', $op, "%{$escaped}%");
        }

        $items = $query->orderByRaw("case when type = 'folder' then 0 else 1 end")
            ->orderBy('name')
            ->get();

        $usedBytes = $this->usage->usedBytesForOwnerInTenant($owner, $tenant);

        $trashedCount = FileItem::onlyTrashed()
            ->where('tenant_id', $tenant->id)
            ->forOwner($owner)
            ->count();

        return Inertia::render('Files/Index', [
            'items' => FileItemResource::collection($items),
            'breadcrumbs' => $this->breadcrumbs($folder),
            'current_folder' => $folder?->only(['id', 'name', 'uuid']),
            'usage' => [
                'used_bytes' => $usedBytes,
                'quota_bytes' => $this->usage->effectiveQuota($owner, $tenant),
                'percent' => $owner instanceof User
                    ? $this->usage->percentUsedInTenant($owner, $tenant)
                    : 0.0,
            ],
            'trashed_count' => $trashedCount,
            'search' => $search ?: null,
        ]);
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $tenant);

        $owner = $this->resolveOwner($request, $user);
        Gate::forUser($user)->authorize('createFolderFor', [FileItem::class, $owner, $tenant]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => ['nullable', 'integer', $this->existsFileItemRule()],
        ]);

        if (isset($data['parent_id'])) {
            $parent = FileItem::findOrFail($data['parent_id']);
            Gate::forUser($user)->authorize('update', [$parent, $tenant]);
            if (! $parent->isFolder()) {
                abort(422, 'Parent must be a folder.');
            }
        }

        $folder = FileItem::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'parent_id' => $data['parent_id'] ?? null,
            'type' => FileItem::TYPE_FOLDER,
            'scope' => $this->scopeFor($owner),
            'name' => $this->uniqueName($tenant->id, $owner, $data['parent_id'] ?? null, $data['name']),
        ]);

        return back()->with('success', __('files.folder_created', ['name' => $folder->name]));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $tenant);

        $owner = $this->resolveOwner($request, $user);
        Gate::forUser($user)->authorize('uploadTo', [FileItem::class, $owner, $tenant]);

        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'file|max:'.config('files.max_upload_kilobytes', 51200),
            'parent_id' => ['nullable', 'integer', $this->existsFileItemRule()],
        ]);

        $parentId = $request->integer('parent_id') ?: null;
        if ($parentId !== null) {
            $parent = FileItem::findOrFail($parentId);
            Gate::forUser($user)->authorize('update', [$parent, $tenant]);
            if (! $parent->isFolder()) {
                abort(422, 'Parent must be a folder.');
            }
        }

        $created = 0;
        $previewTargets = [];
        $videoTargets = [];
        DB::connection((string) config('tenancy.database.central_connection'))->transaction(function () use ($request, $tenant, $user, $owner, $parentId, &$created, &$previewTargets, &$videoTargets): void {
            foreach ($request->file('files', []) as $file) {
                $name = $this->uniqueName($tenant->id, $owner, $parentId, $file->getClientOriginalName());
                $size = $file->getSize();
                $item = FileItem::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => $owner->getKey(),
                    'parent_id' => $parentId,
                    'type' => FileItem::TYPE_FILE,
                    'scope' => $this->scopeFor($owner),
                    'name' => $name,
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $size === false ? 0 : (int) $size,
                ]);

                $item->addMedia($file)->toMediaCollection('file');
                $created++;

                if (in_array((string) $item->mime_type, config('files.preview_mime_types', []), true)) {
                    $previewTargets[] = $item->id;
                }
                if ($item->isVideo()) {
                    $videoTargets[] = $item->id;
                }
            }
        });

        foreach ($previewTargets as $id) {
            GenerateDocumentPreview::dispatch($id);
        }
        foreach ($videoTargets as $id) {
            GenerateVideoPreview::dispatch($id);
        }
        foreach (array_unique(array_merge($previewTargets, $videoTargets)) as $id) {
            $fresh = FileItem::with('media')->find($id);
            if ($fresh) {
                event(new FileItemUpdated($fresh));
            }
        }

        // Refresh whichever owner-scoped denormalization exists, then fire
        // threshold alerts (only meaningful for User owners today).
        $this->usage->recomputeForOwner($owner);
        if ($owner instanceof User) {
            $this->usage->checkAndNotifyThresholds($owner->fresh(), $tenant);
        }

        return back()->with('success', __('files.upload_success', ['count' => $created]));
    }

    public function update(Request $request, FileItem $file): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $tenant);
        Gate::forUser($user)->authorize('update', [$file, $tenant]);

        $data = $request->validate([
            'name' => 'sometimes|string|min:1|max:255',
            'parent_id' => ['sometimes', 'nullable', 'integer', $this->existsFileItemRule()],
        ]);

        if (array_key_exists('parent_id', $data)) {
            if ($data['parent_id'] !== null) {
                if ((int) $data['parent_id'] === (int) $file->id) {
                    abort(422, 'Cannot set an item as its own parent.');
                }
                $parent = FileItem::findOrFail($data['parent_id']);
                Gate::forUser($user)->authorize('update', [$parent, $tenant]);
                if (! $parent->isFolder()) {
                    abort(422, 'Destination must be a folder.');
                }
                if ($file->isFolder() && $this->isDescendantOf($parent, $file)) {
                    abort(422, 'Cannot move a folder into its own descendant.');
                }
            }
            $file->parent_id = $data['parent_id'];
        }

        if (array_key_exists('name', $data) && $data['name'] !== '') {
            $file->name = $this->uniqueNameForItem($tenant->id, $file, $data['name']);
        }

        $file->save();

        return back()->with('success', __('files.updated'));
    }

    public function destroy(Request $request, FileItem $file): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $tenant);
        Gate::forUser($user)->authorize('delete', [$file, $tenant]);

        $file->delete();
        $this->usage->recomputeForOwner($file->owner ?? $user);

        return back()->with('success', __('files.deleted'));
    }

    /**
     * Share an owned personal file to the active customer's company tree.
     */
    public function share(Request $request, FileItem $file): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $tenant);
        Gate::forUser($user)->authorize('share', [$file, $tenant]);

        abort_unless(
            $tenant->company_files_enabled,
            404,
            __('files.company_not_enabled'),
        );
        abort_unless($user->can('share files to company'), 403, __('files.permission_denied'));

        if ($file->scope !== FileItem::SCOPE_PERSONAL) {
            abort(422, __('files.cannot_share_non_personal'));
        }

        $data = $request->validate([
            'company_parent_id' => ['nullable', 'integer', $this->existsFileItemRule()],
        ]);

        $parentId = $data['company_parent_id'] ?? null;
        if ($parentId !== null) {
            $parent = FileItem::findOrFail($parentId);
            if (
                $parent->tenant_id !== $tenant->id
                || $parent->scope !== FileItem::SCOPE_COMPANY
                || ! $parent->isFolder()
            ) {
                abort(422, __('files.invalid_company_folder'));
            }
        }

        if ($file->isFolder()) {
            CompanyFilesCache::bump($tenant->id, 'folder_share_started', $parentId);
            ShareFolderToCompany::dispatch(
                personalFolderId: $file->id,
                tenantId: $tenant->id,
                actingUserId: $user->id,
                companyParentId: $parentId,
            );

            return back()->with('success', __('files.shared_to_company_queued'));
        }

        DB::connection((string) config('tenancy.database.central_connection'))
            ->transaction(function () use ($file, $tenant, $user, $parentId): void {
                CompanyFileLink::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'file_item_id' => $file->id],
                    ['company_parent_id' => $parentId, 'shared_by_user_id' => $user->id],
                );
            });

        $this->usage->recomputeForTenant($tenant);
        CompanyFilesCache::bump($tenant->id, 'file_shared', $parentId);

        return back()->with('success', __('files.shared_to_company'));
    }

    public function unshare(Request $request, FileItem $file): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $tenant);
        Gate::forUser($user)->authorize('share', [$file, $tenant]);

        if ($file->scope !== FileItem::SCOPE_PERSONAL) {
            abort(422, __('files.cannot_unshare_non_personal'));
        }

        DB::connection((string) config('tenancy.database.central_connection'))
            ->transaction(function () use ($file, $tenant): void {
                if ($file->isFolder()) {
                    $this->unshareFolderFromCompany($file, $tenant);
                } else {
                    CompanyFileLink::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('file_item_id', $file->id)
                        ->delete();
                }
            });

        $this->usage->recomputeForTenant($tenant);
        CompanyFilesCache::bump($tenant->id, 'unshared');

        return back()->with('success', __('files.unshared_from_company'));
    }

    private function unshareFolderFromCompany(FileItem $personalFolder, Tenant $tenant): void
    {
        foreach ($personalFolder->children()->get() as $child) {
            /** @var FileItem $child */
            if ($child->isFolder()) {
                $this->unshareFolderFromCompany($child, $tenant);
            } else {
                CompanyFileLink::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('file_item_id', $child->id)
                    ->delete();
            }
        }
    }

    public function download(Request $request, FileItem $file): BinaryFileResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $tenant);
        Gate::forUser($user)->authorize('download', [$file, $tenant]);

        if ($file->isFolder()) {
            abort(404);
        }

        $media = $file->getFirstMedia('file');
        if (! $media) {
            abort(404);
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

        return response()->download($path, $filename);
    }

    /**
     * Refuse listing/management when the caller can't access this owner's
     * files. Mirrors FileTrashController so a crafted owner_type/owner_id
     * pair on the index endpoint can't expose another owner's tree.
     */
    private function authorizeOwnerAccess(User $user, Model $owner, Tenant $tenant, bool $view): void
    {
        if (! $owner instanceof FileOwner) {
            abort(403, __('files.permission_denied'));
        }

        $allowed = $view
            ? $owner->canViewFiles($user, $tenant)
            : $owner->canManageFiles($user, $tenant);

        abort_unless($allowed, 403, __('files.permission_denied'));
    }

    /**
     * Resolve the polymorphic owner the request is acting on. Defaults to
     * the authenticated user (personal files) — future routes can pass an
     * `owner_type` + `owner_id` pair (e.g. /files/buildings/12) and this
     * method will resolve and authorize it.
     */
    private function resolveOwner(Request $request, User $user): Model
    {
        $type = $request->input('owner_type');
        $id = $request->input('owner_id');

        if (! is_string($type) || ! is_numeric($id)) {
            return $user;
        }

        // The client sends the polymorphic morph alias (e.g. the value stored
        // in owner_type), not a raw class path. Resolve it through the morph
        // map and only allow classes the app has registered as owners — this
        // refuses crafted owner_type payloads probing arbitrary classes.
        $class = Relation::getMorphedModel($type);
        $allowed = config('files.allowed_owner_types', [User::class, Tenant::class]);
        if ($class === null || ! in_array($class, $allowed, true) || ! class_exists($class)) {
            abort(422, 'Unknown owner type.');
        }

        $resolved = $class::query()->find((int) $id);
        if ($resolved === null) {
            abort(404);
        }

        return $resolved;
    }

    private function scopeFor(Model $owner): string
    {
        return $owner instanceof Tenant ? FileItem::SCOPE_COMPANY : FileItem::SCOPE_PERSONAL;
    }

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

    private function assertFeatureAvailable(Request $request, Tenant $tenant): void
    {
        if (! AppSetting::current()->files_feature_enabled) {
            abort(404);
        }

        $user = $request->user();
        $settings = $user->settings()->resolved();

        if (! $tenant->files_feature_enabled) {
            abort(404);
        }

        if (! ($settings['files_enabled'] ?? false)) {
            abort(403, __('files.not_enabled'));
        }

        if ($this->usage->effectivePersonalQuota($user, $tenant) === 0) {
            abort(403, __('files.quota_disabled'));
        }
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
     * Suffix " (n)" until the name is unique within the (owner, parent)
     * folder. Operates on owner_type/owner_id so company and personal files
     * have independent name spaces even when they share a tenant + parent.
     */
    private function uniqueName(int $tenantId, Model $owner, ?int $parentId, string $name, ?int $ignoreId = null): string
    {
        return $this->uniqueNameByColumns(
            $tenantId,
            $owner->getMorphClass(),
            (int) $owner->getKey(),
            $parentId,
            $name,
            $ignoreId,
        );
    }

    /**
     * Same uniqueness check, driven from the FileItem's stored owner_type/
     * owner_id columns rather than the loaded relation. Used by rename so
     * we don't depend on owner being eagerly loadable — a deleted owner row
     * still has stable stored ids and we want the rename to succeed.
     */
    private function uniqueNameForItem(int $tenantId, FileItem $item, string $name): string
    {
        return $this->uniqueNameByColumns(
            $tenantId,
            (string) $item->owner_type,
            (int) $item->owner_id,
            $item->parent_id,
            $name,
            $item->id,
        );
    }

    private function uniqueNameByColumns(int $tenantId, string $ownerType, int $ownerId, ?int $parentId, string $name, ?int $ignoreId): string
    {
        $base = $name;
        $i = 1;
        while (FileItem::query()
            ->where('tenant_id', $tenantId)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
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
}
