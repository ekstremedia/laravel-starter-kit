<?php

declare(strict_types=1);

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Events\FileItemUpdated;
use App\Domains\Files\Jobs\ExtractFileMetadata;
use App\Domains\Files\Jobs\GenerateDocumentPreview;
use App\Domains\Files\Jobs\GenerateImagePreview;
use App\Domains\Files\Jobs\GenerateVideoPreview;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Files\Support\OwnerResolver;
use App\Domains\Files\Support\UploadLimits;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Workspaces\Models\Workspace;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Generic file mutations for ANY polymorphic owner entity (Asset, future
 * Vehicle/Medicine/Building…). The owner is resolved from owner_type/owner_id
 * via OwnerResolver and authorized through FileItemPolicy + the owner's own
 * canManageFiles rules — so this controller never needs to know about a
 * specific entity. Listing is provided by the entity's own controller (e.g.
 * AssetController@show) which renders the embedded <EntityFiles> browser.
 *
 * Distinct from FileItemController (personal files), which additionally gates
 * on per-user `files_enabled` + personal quota — concepts that don't apply to
 * entity documents.
 */
class EntityFileController extends Controller
{
    public function __construct(private readonly StorageUsageService $usage) {}

    public function storeFolder(Request $request): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $this->assertFilesEnabled($workspace);
        $owner = OwnerResolver::fromRequest($request, $request->user());

        Gate::forUser($request->user())->authorize('createFolderFor', [FileItem::class, $owner, $workspace]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => ['nullable', 'integer', $this->existsRule($workspace, $owner)],
        ]);

        $folder = FileItem::create([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'parent_id' => $data['parent_id'] ?? null,
            'type' => FileItem::TYPE_FOLDER,
            'scope' => FileItem::SCOPE_PERSONAL,
            'name' => $this->uniqueName($workspace, $owner, $data['parent_id'] ?? null, $data['name']),
        ]);

        return back()->with('success', __('files.folder_created', ['name' => $folder->name]));
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $this->assertFilesEnabled($workspace);
        $owner = OwnerResolver::fromRequest($request, $request->user());

        Gate::forUser($request->user())->authorize('uploadTo', [FileItem::class, $owner, $workspace]);

        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'file|max:'.UploadLimits::maxUploadKilobytes(),
            'parent_id' => ['nullable', 'integer', $this->existsRule($workspace, $owner)],
        ]);

        $parentId = $request->integer('parent_id') ?: null;
        $created = 0;
        $previewTargets = [];
        $videoTargets = [];
        $imageTargets = [];
        $allTargets = [];

        DB::connection((string) config('workspaces.database.central_connection'))->transaction(function () use ($request, $workspace, $owner, $parentId, &$created, &$previewTargets, &$videoTargets, &$imageTargets, &$allTargets): void {
            foreach ($request->file('files', []) as $file) {
                $size = $file->getSize();
                $item = FileItem::create([
                    'workspace_id' => $workspace->id,
                    'user_id' => $request->user()->id,
                    'owner_type' => $owner->getMorphClass(),
                    'owner_id' => $owner->getKey(),
                    'parent_id' => $parentId,
                    'type' => FileItem::TYPE_FILE,
                    'scope' => FileItem::SCOPE_PERSONAL,
                    'name' => $this->uniqueName($workspace, $owner, $parentId, $file->getClientOriginalName()),
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
            if ($fresh = FileItem::with('media')->find($id)) {
                event(new FileItemUpdated($fresh));
            }
        }

        $this->usage->recomputeForOwner($owner);

        return back()->with('success', __('files.upload_success', ['count' => $created]));
    }

    public function update(Request $request, FileItem $file): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $this->assertFilesEnabled($workspace);
        Gate::forUser($request->user())->authorize('update', [$file, $workspace]);

        $data = $request->validate(['name' => 'required|string|min:1|max:255']);
        $file->name = $this->uniqueName($workspace, $file->owner, $file->parent_id, $data['name'], $file->id);
        $file->save();

        return back()->with('success', __('files.updated'));
    }

    public function destroy(Request $request, FileItem $file): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $this->assertFilesEnabled($workspace);
        Gate::forUser($request->user())->authorize('delete', [$file, $workspace]);

        $owner = $file->owner;
        $file->delete();

        if ($owner !== null) {
            $this->usage->recomputeForOwner($owner);
        }

        return back()->with('success', __('files.deleted'));
    }

    public function download(Request $request, FileItem $file): BinaryFileResponse
    {
        $workspace = $this->currentTenant($request);
        $this->assertFilesEnabled($workspace);
        Gate::forUser($request->user())->authorize('download', [$file, $workspace]);

        $media = $file->getFirstMedia('file');
        abort_if($media === null, 404);

        return response()->download($media->getPath(), $media->file_name);
    }

    private function assertFilesEnabled(Workspace $workspace): void
    {
        abort_unless(AppSetting::current()->files_feature_enabled, 404);
        abort_unless($workspace->files_feature_enabled, 404);
    }

    private function currentTenant(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');
        if ($workspace instanceof Workspace) {
            return $workspace;
        }

        $slug = config('workspaces.default_workspace_slug');
        $fallback = $slug ? Workspace::query()->where('slug', $slug)->first() : null;
        abort_if($fallback === null, 404);

        return $fallback;
    }

    /**
     * Validate parent_id exists as a folder owned by the same (workspace, owner).
     * The explicit central connection is vestigial and resolves to the default
     * connection.
     */
    private function existsRule(Workspace $workspace, Model $owner): Exists
    {
        return Rule::exists((string) config('workspaces.database.central_connection').'.file_items', 'id')
            ->where('workspace_id', $workspace->id)
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('type', FileItem::TYPE_FOLDER);
    }

    private function uniqueName(Workspace $workspace, ?Model $owner, ?int $parentId, string $name, ?int $ignoreId = null): string
    {
        if ($owner === null) {
            return $name;
        }

        $base = $name;
        $i = 1;
        while (FileItem::query()
            ->where('workspace_id', $workspace->id)
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $ext = pathinfo($base, PATHINFO_EXTENSION);
            $stem = $ext !== '' ? mb_substr($base, 0, -(mb_strlen($ext) + 1)) : $base;
            $name = $ext !== '' ? "{$stem} ({$i}).{$ext}" : "{$base} ({$i})";
            $i++;
        }

        return $name;
    }
}
