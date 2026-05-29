<?php

declare(strict_types=1);

namespace App\Domains\Assets\Http\Controllers;

use App\Domains\Assets\Models\Asset;
use App\Domains\Files\Http\Resources\FileItemResource;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Workspaces\Models\Workspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD for the demo Asset entity plus its embedded document area. The file
 * listing here is the entity-side mirror of FileItemController@index; file
 * *mutations* (upload/folder/rename/delete) go to EntityFileController via the
 * <EntityFiles> browser, scoped by owner_type=asset & owner_id.
 */
class AssetController extends Controller
{
    public function __construct(private readonly StorageUsageService $usage) {}

    public function index(Request $request): Response
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canViewFiles($request->user(), $workspace), 403);

        $assets = Asset::query()
            ->where('workspace_id', $workspace->id)
            ->withCount('files')
            ->when($request->string('q')->toString(), function ($query, string $search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($q) use ($like): void {
                    $q->where('name', 'ilike', $like)
                        ->orWhere('category', 'ilike', $like)
                        ->orWhere('serial', 'ilike', $like);
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Assets/Index', [
            'assets' => $assets,
            'can_manage' => $workspace->canManageFiles($request->user(), $workspace),
            'search' => $request->string('q')->toString() ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canManageFiles($request->user(), $workspace), 403);

        $data = $this->validateAsset($request);
        $asset = Asset::create([...$data, 'workspace_id' => $workspace->id]);

        return redirect()
            ->route('customer.assets.show', ['customer' => $workspace->slug, 'asset' => $asset->id])
            ->with('success', __('assets.created', ['name' => $asset->name]));
    }

    public function show(Request $request, Asset $asset, ?FileItem $folder = null): Response
    {
        $workspace = $this->currentTenant($request);
        $this->assertBelongs($asset, $workspace);
        abort_unless($workspace->canViewFiles($request->user(), $workspace), 403);

        if ($folder !== null && ($folder->owner_id !== $asset->id || $folder->owner_type !== $asset->getMorphClass() || ! $folder->isFolder())) {
            abort(404);
        }

        $items = FileItem::query()
            ->where('workspace_id', $workspace->id)
            ->forOwner($asset)
            ->where('parent_id', $folder?->id)
            ->with(['media'])
            ->orderByRaw("case when type = 'folder' then 0 else 1 end")
            ->orderBy('name')
            ->get();

        $quota = $this->usage->effectiveQuota($asset, $workspace);
        $used = $this->usage->usedBytesForOwnerInTenant($asset, $workspace);

        return Inertia::render('Assets/Show', [
            'asset' => [
                'id' => $asset->id,
                'name' => $asset->name,
                'category' => $asset->category,
                'serial' => $asset->serial,
                'notes' => $asset->notes,
                'file_quota_bytes' => $asset->file_quota_bytes,
            ],
            'owner' => ['type' => $asset->getMorphClass(), 'id' => $asset->id],
            'files' => FileItemResource::collection($items),
            'breadcrumbs' => $this->breadcrumbs($folder),
            'current_folder' => $folder?->only(['id', 'name']),
            'usage' => [
                'used_bytes' => $used,
                'quota_bytes' => $quota,
                'percent' => $quota !== null && $quota > 0 ? min(100.0, round($used / $quota * 100, 1)) : 0.0,
            ],
            'can_manage' => $workspace->canManageFiles($request->user(), $workspace),
        ]);
    }

    public function update(Request $request, Asset $asset): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $this->assertBelongs($asset, $workspace);
        abort_unless($workspace->canManageFiles($request->user(), $workspace), 403);

        $asset->update($this->validateAsset($request));

        return back()->with('success', __('assets.updated'));
    }

    public function destroy(Request $request, Asset $asset): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $this->assertBelongs($asset, $workspace);
        abort_unless($workspace->canManageFiles($request->user(), $workspace), 403);

        $asset->delete();

        return redirect()
            ->route('customer.assets.index', ['customer' => $workspace->slug])
            ->with('success', __('assets.deleted'));
    }

    /**
     * @return array{name: string, category: string|null, serial: string|null, notes: string|null, file_quota_bytes: int|null}
     */
    private function validateAsset(Request $request): array
    {
        /** @var array{name: string, category: string|null, serial: string|null, notes: string|null, file_quota_bytes: int|null} $data */
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'serial' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
            // null = inherit, -1 = unlimited, >=0 = byte cap.
            'file_quota_bytes' => 'nullable|integer|min:-1',
        ]);

        return $data;
    }

    private function assertBelongs(Asset $asset, Workspace $workspace): void
    {
        abort_unless($asset->workspace_id === $workspace->id, 404);
    }

    private function currentTenant(Request $request): Workspace
    {
        $workspace = $request->attributes->get('customer');
        if ($workspace instanceof Workspace) {
            return $workspace;
        }

        $slug = config('workspaces.default_workspace_slug');
        $fallback = $slug ? Workspace::query()->where('slug', $slug)->first() : null;
        abort_if($fallback === null, 404);

        return $fallback;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function breadcrumbs(?FileItem $folder): array
    {
        $crumbs = [];
        while ($folder !== null) {
            array_unshift($crumbs, ['id' => $folder->id, 'name' => $folder->name]);
            $folder = $folder->parent_id !== null ? FileItem::find($folder->parent_id) : null;
        }

        return $crumbs;
    }
}
