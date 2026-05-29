<?php

declare(strict_types=1);

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Contracts\FileOwner;
use App\Domains\Files\Events\FileItemUpdated;
use App\Domains\Files\Http\Resources\FileItemResource;
use App\Domains\Files\Jobs\ExtractFileMetadata;
use App\Domains\Files\Jobs\GenerateDocumentPreview;
use App\Domains\Files\Jobs\GenerateImagePreview;
use App\Domains\Files\Jobs\GenerateVideoPreview;
use App\Domains\Files\Jobs\ShareFolderToCompany;
use App\Domains\Files\Models\CompanyFileLink;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Services\FileMetadataExtractor;
use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Files\Support\CompanyFilesCache;
use App\Domains\Files\Support\OwnerResolver;
use App\Domains\Files\Support\UploadLimits;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
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
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);

        $owner = $this->resolveOwner($request, $user);
        $this->authorizeOwnerAccess($user, $owner, $workspace, view: true);

        if ($folder !== null && $folder->exists) {
            Gate::forUser($user)->authorize('view', [$folder, $workspace]);
            if (! $folder->isFolder()) {
                abort(404);
            }
        }

        $parentId = $folder?->id;

        $query = FileItem::query()
            ->where('workspace_id', $workspace->id)
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

        $usedBytes = $this->usage->usedBytesForOwnerInTenant($owner, $workspace);

        $trashedCount = FileItem::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->forOwner($owner)
            ->count();

        return Inertia::render('Files/Index', [
            'items' => FileItemResource::collection($items),
            'breadcrumbs' => $this->breadcrumbs($folder),
            'current_folder' => $folder?->only(['id', 'name', 'uuid']),
            'usage' => [
                'used_bytes' => $usedBytes,
                'quota_bytes' => $this->usage->effectiveQuota($owner, $workspace),
                'percent' => $owner instanceof User
                    ? $this->usage->percentUsedInTenant($owner, $workspace)
                    : 0.0,
            ],
            'trashed_count' => $trashedCount,
            'search' => $search ?: null,
            'max_upload_bytes' => UploadLimits::maxUploadBytes(),
        ]);
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);

        $owner = $this->resolveOwner($request, $user);
        Gate::forUser($user)->authorize('createFolderFor', [FileItem::class, $owner, $workspace]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => ['nullable', 'integer', $this->existsFileItemRule()],
        ]);

        if (isset($data['parent_id'])) {
            $parent = FileItem::findOrFail($data['parent_id']);
            Gate::forUser($user)->authorize('update', [$parent, $workspace]);
            if (! $parent->isFolder()) {
                abort(422, 'Parent must be a folder.');
            }
        }

        $folder = FileItem::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'parent_id' => $data['parent_id'] ?? null,
            'type' => FileItem::TYPE_FOLDER,
            'scope' => $this->scopeFor($owner),
            'name' => $this->uniqueName($workspace->id, $owner, $data['parent_id'] ?? null, $data['name']),
        ]);

        return back()->with('success', __('files.folder_created', ['name' => $folder->name]));
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);

        $owner = $this->resolveOwner($request, $user);
        Gate::forUser($user)->authorize('uploadTo', [FileItem::class, $owner, $workspace]);

        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'file|max:'.UploadLimits::maxUploadKilobytes(),
            'parent_id' => ['nullable', 'integer', $this->existsFileItemRule()],
        ]);

        $parentId = $request->integer('parent_id') ?: null;
        if ($parentId !== null) {
            $parent = FileItem::findOrFail($parentId);
            Gate::forUser($user)->authorize('update', [$parent, $workspace]);
            if (! $parent->isFolder()) {
                abort(422, 'Parent must be a folder.');
            }
        }

        $created = 0;
        $previewTargets = [];
        $videoTargets = [];
        $imageTargets = [];
        $allTargets = [];
        DB::connection((string) config('workspaces.database.central_connection'))->transaction(function () use ($request, $workspace, $user, $owner, $parentId, &$created, &$previewTargets, &$videoTargets, &$imageTargets, &$allTargets): void {
            foreach ($request->file('files', []) as $file) {
                $name = $this->uniqueName($workspace->id, $owner, $parentId, $file->getClientOriginalName());
                $size = $file->getSize();
                $item = FileItem::create([
                    'workspace_id' => $workspace->id,
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
        // Metadata for everything else (plain images, audio, text) — the
        // preview-bearing types above get their own jobs but still want EXIF,
        // so extract uniformly for all uploads.
        foreach ($allTargets as $id) {
            ExtractFileMetadata::dispatch($id);
        }
        foreach (array_unique(array_merge($previewTargets, $videoTargets, $imageTargets)) as $id) {
            $fresh = FileItem::with('media')->find($id);
            if ($fresh) {
                event(new FileItemUpdated($fresh));
            }
        }

        // Refresh whichever owner-scoped denormalization exists, then fire
        // threshold alerts (only meaningful for User owners today).
        $this->usage->recomputeForOwner($owner);
        if ($owner instanceof User) {
            $this->usage->checkAndNotifyThresholds($owner->fresh(), $workspace);
        }

        return back()->with('success', __('files.upload_success', ['count' => $created]));
    }

    public function update(Request $request, FileItem $file): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);
        Gate::forUser($user)->authorize('update', [$file, $workspace]);

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
                Gate::forUser($user)->authorize('update', [$parent, $workspace]);
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
            $file->name = $this->uniqueNameForItem($workspace->id, $file, $data['name']);
        }

        $file->save();

        return back()->with('success', __('files.updated'));
    }

    public function destroy(Request $request, FileItem $file): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);
        Gate::forUser($user)->authorize('delete', [$file, $workspace]);

        $file->delete();
        $this->usage->recomputeForOwner($file->owner ?? $user);

        return back()->with('success', __('files.deleted'));
    }

    /**
     * Share an owned personal file to the active workspace's company tree.
     */
    public function share(Request $request, FileItem $file): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);
        Gate::forUser($user)->authorize('share', [$file, $workspace]);

        abort_unless(
            $workspace->company_files_enabled,
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
                $parent->workspace_id !== $workspace->id
                || $parent->scope !== FileItem::SCOPE_COMPANY
                || ! $parent->isFolder()
            ) {
                abort(422, __('files.invalid_company_folder'));
            }
        }

        if ($file->isFolder()) {
            CompanyFilesCache::bump($workspace->id, 'folder_share_started', $parentId);
            ShareFolderToCompany::dispatch(
                personalFolderId: $file->id,
                workspaceId: $workspace->id,
                actingUserId: $user->id,
                companyParentId: $parentId,
            );

            return back()->with('success', __('files.shared_to_company_queued'));
        }

        DB::connection((string) config('workspaces.database.central_connection'))
            ->transaction(function () use ($file, $workspace, $user, $parentId): void {
                CompanyFileLink::updateOrCreate(
                    ['workspace_id' => $workspace->id, 'file_item_id' => $file->id],
                    ['company_parent_id' => $parentId, 'shared_by_user_id' => $user->id],
                );
            });

        $this->usage->recomputeForTenant($workspace);
        CompanyFilesCache::bump($workspace->id, 'file_shared', $parentId);

        return back()->with('success', __('files.shared_to_company'));
    }

    public function unshare(Request $request, FileItem $file): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);
        Gate::forUser($user)->authorize('share', [$file, $workspace]);

        if ($file->scope !== FileItem::SCOPE_PERSONAL) {
            abort(422, __('files.cannot_unshare_non_personal'));
        }

        DB::connection((string) config('workspaces.database.central_connection'))
            ->transaction(function () use ($file, $workspace): void {
                if ($file->isFolder()) {
                    $this->unshareFolderFromCompany($file, $workspace);
                } else {
                    CompanyFileLink::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('file_item_id', $file->id)
                        ->delete();
                }
            });

        $this->usage->recomputeForTenant($workspace);
        CompanyFilesCache::bump($workspace->id, 'unshared');

        return back()->with('success', __('files.unshared_from_company'));
    }

    private function unshareFolderFromCompany(FileItem $personalFolder, Workspace $workspace): void
    {
        foreach ($personalFolder->children()->get() as $child) {
            /** @var FileItem $child */
            if ($child->isFolder()) {
                $this->unshareFolderFromCompany($child, $workspace);
            } else {
                CompanyFileLink::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('file_item_id', $child->id)
                    ->delete();
            }
        }
    }

    public function download(Request $request, FileItem $file): BinaryFileResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);
        Gate::forUser($user)->authorize('download', [$file, $workspace]);

        if ($file->isFolder()) {
            abort(404);
        }

        $media = $file->getFirstMedia('file');
        if (! $media) {
            abort(404);
        }

        // `variant=converted` serves the normalized JPEG we render from RAW /
        // TIFF / HEIC originals (the `image_preview` collection), so the user
        // can grab a displayable copy alongside the (default) original.
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

        return response()->download($path, $filename);
    }

    /**
     * Return normalized file metadata (EXIF/GPS/dimensions/codec…) for the
     * Details panel. Legacy files uploaded before metadata extraction get it
     * lazily on first open and the result is persisted.
     */
    public function details(Request $request, FileItem $file, FileMetadataExtractor $extractor): JsonResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);
        Gate::forUser($user)->authorize('view', [$file, $workspace]);

        if ($file->isFolder()) {
            abort(404);
        }

        $metadata = $file->metadata;
        if ($metadata === null) {
            $media = $file->getFirstMedia('file');
            if ($media && is_file($media->getPath())) {
                $metadata = $extractor->extract($media->getPath());
                if ($metadata !== []) {
                    $file->update(['metadata' => $metadata]);
                }
            }
        }

        return response()->json([
            'id' => $file->id,
            'name' => $file->name,
            'mime_type' => $file->mime_type,
            'size' => (int) $file->size,
            'created_at' => $file->created_at->toIso8601String(),
            'updated_at' => $file->updated_at->toIso8601String(),
            'metadata' => $metadata ?: null,
        ]);
    }

    /**
     * Stream the first N KB of a text/code/markdown file for inline preview.
     * Always served as application/json with the content forced to a string —
     * never echoed with the file's own mime, so a .html/.svg upload can't
     * execute in the viewer.
     */
    public function text(Request $request, FileItem $file): JsonResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);
        Gate::forUser($user)->authorize('view', [$file, $workspace]);

        if ($file->isFolder() || ! $file->isTextPreviewable()) {
            abort(404);
        }

        $media = $file->getFirstMedia('file');
        if (! $media || ! is_file($media->getPath())) {
            abort(404);
        }

        $max = (int) config('files.text_preview_max_bytes', 256 * 1024);
        $raw = (string) file_get_contents($media->getPath(), false, null, 0, $max + 1);
        $truncated = strlen($raw) > $max;
        if ($truncated) {
            $raw = substr($raw, 0, $max);
        }

        // Coerce to valid UTF-8 so json_encode never fails on a binary-ish file.
        $content = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');

        return response()->json([
            'content' => $content,
            'truncated' => $truncated,
            'is_markdown' => $file->isMarkdown(),
            'language' => $file->extension(),
        ]);
    }

    /**
     * Flat list of every folder the current owner has, for building a move
     * destination picker. Returns id/name/parent_id; the client indents.
     */
    public function folders(Request $request): JsonResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);

        $owner = $this->resolveOwner($request, $user);
        $this->authorizeOwnerAccess($user, $owner, $workspace, view: true);

        $folders = FileItem::query()
            ->where('workspace_id', $workspace->id)
            ->forOwner($owner)
            ->where('type', FileItem::TYPE_FOLDER)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        return response()->json(['folders' => $folders]);
    }

    /**
     * Soft-delete several items at once. Each is authorized individually so a
     * single unauthorized id aborts the batch rather than silently skipping.
     */
    public function bulkDelete(Request $request): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $items = FileItem::query()->with('owner')->whereIn('id', $data['ids'])->get();
        foreach ($items as $item) {
            Gate::forUser($user)->authorize('delete', [$item, $workspace]);
        }
        $owner = $user;
        foreach ($items as $item) {
            $owner = $item->owner ?? $user;
            $item->delete();
        }

        $this->usage->recomputeForOwner($owner);

        return back()->with('success', __('files.bulk_deleted', ['count' => $items->count()]));
    }

    /**
     * Move several items into a destination folder (or root when null).
     */
    public function bulkMove(Request $request): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'parent_id' => ['nullable', 'integer', $this->existsFileItemRule()],
        ]);

        $destId = $data['parent_id'] ?? null;
        if ($destId !== null) {
            $dest = FileItem::findOrFail($destId);
            Gate::forUser($user)->authorize('update', [$dest, $workspace]);
            if (! $dest->isFolder()) {
                abort(422, __('files.invalid_company_folder'));
            }
        }

        $items = FileItem::query()->with('owner')->whereIn('id', $data['ids'])->get();
        foreach ($items as $item) {
            Gate::forUser($user)->authorize('update', [$item, $workspace]);
        }

        DB::connection((string) config('workspaces.database.central_connection'))->transaction(function () use ($items, $destId, $workspace): void {
            foreach ($items as $item) {
                if ((int) $item->id === (int) $destId) {
                    continue; // can't move into itself
                }
                if ($item->isFolder() && $destId !== null) {
                    $dest = FileItem::find($destId);
                    if ($dest && $this->isDescendantOf($dest, $item)) {
                        abort(422, __('files.invalid_company_folder'));
                    }
                }
                $item->parent_id = $destId;
                $item->name = $this->uniqueNameForItem($workspace->id, $item, $item->name);
                $item->save();
            }
        });

        return back()->with('success', __('files.bulk_moved', ['count' => $items->count()]));
    }

    /**
     * Stream a ZIP of the selected items (folders recurse into subdirectories).
     * `ids` is a comma-separated query param so the browser can fetch it with a
     * plain navigation.
     */
    public function bulkZip(Request $request): BinaryFileResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);

        $ids = collect(explode(',', $request->string('ids')->toString()))
            ->map(fn ($v) => (int) trim($v))
            ->filter()
            ->unique()
            ->values();
        abort_if($ids->isEmpty(), 404);

        $items = FileItem::query()->with(['owner', 'media'])->whereIn('id', $ids)->get();
        foreach ($items as $item) {
            Gate::forUser($user)->authorize('download', [$item, $workspace]);
        }

        $zipPath = sys_get_temp_dir().'/files-'.uniqid('', true).'.zip';
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Could not create archive.');
        }

        $usedNames = [];
        foreach ($items as $item) {
            $this->addItemToZip($zip, $item, '', $usedNames);
        }
        $zip->close();

        return response()->download($zipPath, 'files.zip')->deleteFileAfterSend(true);
    }

    /**
     * Recursively add a FileItem to the open zip. Files contribute their
     * original media; folders create a directory and recurse their children.
     *
     * @param  array<string, bool>  $usedNames  guards against name collisions at a level
     */
    private function addItemToZip(\ZipArchive $zip, FileItem $item, string $prefix, array &$usedNames): void
    {
        $name = $this->uniqueZipEntry($prefix.$item->name, $usedNames);

        if ($item->isFolder()) {
            $zip->addEmptyDir($name);
            foreach ($item->children()->with('media')->get() as $child) {
                $childUsed = [];
                $this->addItemToZip($zip, $child, $name.'/', $childUsed);
            }

            return;
        }

        $media = $item->getFirstMedia('file');
        if ($media && is_file($media->getPath())) {
            $zip->addFile($media->getPath(), $name);
        }
    }

    /**
     * @param  array<string, bool>  $usedNames
     */
    private function uniqueZipEntry(string $name, array &$usedNames): string
    {
        $candidate = $name;
        $i = 1;
        while (isset($usedNames[$candidate])) {
            $candidate = $this->appendSuffix($name, ++$i);
        }
        $usedNames[$candidate] = true;

        return $candidate;
    }

    /**
     * Refuse listing/management when the caller can't access this owner's
     * files. Mirrors FileTrashController so a crafted owner_type/owner_id
     * pair on the index endpoint can't expose another owner's tree.
     */
    private function authorizeOwnerAccess(User $user, Model $owner, Workspace $workspace, bool $view): void
    {
        if (! $owner instanceof FileOwner) {
            abort(403, __('files.permission_denied'));
        }

        $allowed = $view
            ? $owner->canViewFiles($user, $workspace)
            : $owner->canManageFiles($user, $workspace);

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
        return OwnerResolver::fromRequest($request, $user);
    }

    private function scopeFor(Model $owner): string
    {
        return $owner instanceof Workspace ? FileItem::SCOPE_COMPANY : FileItem::SCOPE_PERSONAL;
    }

    private function currentTenant(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');

        if ($workspace instanceof Workspace) {
            return $workspace;
        }

        $slug = config('workspaces.default_workspace_slug');
        $fallback = $slug ? Workspace::query()->where('slug', $slug)->first() : null;

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

    private function assertFeatureAvailable(Request $request, Workspace $workspace): void
    {
        if (! AppSetting::current()->files_feature_enabled) {
            abort(404);
        }

        $user = $request->user();
        $settings = $user->settings()->resolved();

        if (! $workspace->files_feature_enabled) {
            abort(404);
        }

        if (! ($settings['files_enabled'] ?? false)) {
            abort(403, __('files.not_enabled'));
        }

        if ($this->usage->effectivePersonalQuota($user, $workspace) === 0) {
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
    private function uniqueName(int $workspaceId, Model $owner, ?int $parentId, string $name, ?int $ignoreId = null): string
    {
        return $this->uniqueNameByColumns(
            $workspaceId,
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
    private function uniqueNameForItem(int $workspaceId, FileItem $item, string $name): string
    {
        return $this->uniqueNameByColumns(
            $workspaceId,
            (string) $item->owner_type,
            (int) $item->owner_id,
            $item->parent_id,
            $name,
            $item->id,
        );
    }

    private function uniqueNameByColumns(int $workspaceId, string $ownerType, int $ownerId, ?int $parentId, string $name, ?int $ignoreId): string
    {
        $base = $name;
        $i = 1;
        while (FileItem::query()
            ->where('workspace_id', $workspaceId)
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
