<?php

declare(strict_types=1);

namespace App\Domains\Equipment\Http\Controllers;

use App\Domains\Equipment\Models\Equipment;
use App\Domains\EquipmentCategory\Models\EquipmentCategory;
use App\Domains\Files\Http\Resources\FileItemResource;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Support\FileTreeZipper;
use App\Domains\Modules\Services\ModuleRegistry;
use App\Domains\Operations\Models\Activity;
use App\Domains\Workspaces\Models\Workspace;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CRUD for the Equipment module plus its embedded document area. The file
 * listing here is the entity-side mirror of FileItemController@index; file
 * *mutations* (upload/folder/rename/delete) go to EntityFileController via the
 * <EntityFiles> browser, scoped by owner_type=equipment & owner_id.
 *
 * This is the reference controller for a file-owning module — copy its shape
 * for new modules (Car, Medicine, …). See docs/adding-a-workspace-entity.md.
 */
class EquipmentController extends Controller
{
    /** Page size for the index datatable; the seeder fills past this to show pagination. */
    private const PER_PAGE = 20;

    /** How many file thumbnails to preview inline per row before the "…" truncation. */
    private const PREVIEW_FILES = 4;

    public function index(Request $request): Response
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canViewFiles($request->user(), $workspace), 403);

        // Server-side sort with an allowlist; falls back to name. files_count is
        // the withCount alias, orderable directly in Postgres; category sorts on
        // the related category name via a left join.
        $allowed = ['name', 'category', 'serial', 'files_count', 'created_at'];
        $sort = in_array($request->string('sort')->toString(), $allowed, true)
            ? $request->string('sort')->toString()
            : 'name';
        $dir = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';

        $query = $this->baseQuery($request, $workspace)->withCount('files')->with('category:id,name,color');
        if ($sort === 'category') {
            // withCount already qualifies the select to equipment.*, so the join
            // adds no ambiguous columns; order by the related name (nulls last in pg).
            $query->leftJoin('equipment_categories as ec', 'ec.id', '=', 'equipment.equipment_category_id')
                ->orderBy('ec.name', $dir);
        } else {
            $query->orderBy($sort, $dir);
        }
        if ($sort !== 'name') {
            $query->orderBy('equipment.name'); // stable secondary order (qualified under the join)
        }

        $items = $query->paginate(self::PER_PAGE)->withQueryString();

        // Batch-load a few file previews per row + the cover, in one query each
        // (no N+1 across the page). `previewsFor` returns a [equipment_id =>
        // FileItemResource[]] map; the cover map resolves the chosen/main file.
        $ids = collect($items->items())->pluck('id')->all();
        $previews = $this->previewsFor($ids, $workspace);
        $covers = $this->coversFor($items->items(), $previews);

        $items->getCollection()->transform(function (Equipment $equipment) use ($previews, $covers): Equipment {
            $equipment->setAttribute('files_preview', $previews[$equipment->id] ?? []);
            $equipment->setAttribute('cover', $covers[$equipment->id] ?? null);

            return $equipment;
        });

        return Inertia::render('Equipment/Index', [
            'equipment' => $items,
            'can_manage' => $workspace->canManageFiles($request->user(), $workspace),
            'search' => $request->string('q')->toString() ?: null,
            'categories' => $this->categories($workspace),
            'stats' => $this->workspaceStats($workspace),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canManageFiles($request->user(), $workspace), 403);

        $data = $this->validateEquipment($request);
        $equipment = Equipment::create([...$data, 'workspace_id' => $workspace->id]);

        return redirect()
            ->route('workspace.equipment.show', ['workspace' => $workspace->slug, 'equipment' => $equipment->id])
            ->with('success', __('equipment.created', ['name' => $equipment->name]));
    }

    public function show(Request $request, Equipment $equipment, ?FileItem $folder = null): Response
    {
        $workspace = $this->currentTenant($request);
        $this->assertBelongs($equipment, $workspace);
        abort_unless($workspace->canViewFiles($request->user(), $workspace), 403);

        if ($folder !== null && ($folder->owner_id !== $equipment->id || $folder->owner_type !== $equipment->getMorphClass() || ! $folder->isFolder())) {
            abort(404);
        }

        $equipment->loadMissing('category:id,name,color');

        $items = FileItem::query()
            ->where('workspace_id', $workspace->id)
            ->forOwner($equipment)
            ->where('parent_id', $folder?->id)
            ->with(['media'])
            ->orderByRaw("case when type = 'folder' then 0 else 1 end")
            ->orderBy('name')
            ->get();

        return Inertia::render('Equipment/Show', [
            'equipment' => [
                'id' => $equipment->id,
                'name' => $equipment->name,
                'equipment_category_id' => $equipment->equipment_category_id,
                'category' => $equipment->category
                    ? ['id' => $equipment->category->id, 'name' => $equipment->category->name, 'color' => $equipment->category->color]
                    : null,
                'serial' => $equipment->serial,
                'notes' => $equipment->notes,
                'cover_file_item_id' => $equipment->cover_file_item_id,
            ],
            'categories' => $this->categories($workspace),
            'owner' => ['type' => $equipment->getMorphClass(), 'id' => $equipment->id],
            'files' => FileItemResource::collection($items),
            'breadcrumbs' => $this->breadcrumbs($folder),
            'current_folder' => $folder?->only(['id', 'name']),
            // The Log is composable — skip the query when the feature is off.
            'activities' => app(ModuleRegistry::class)->featureEnabled('equipment', 'log', $workspace)
                ? $this->activities($equipment)
                : [],
            'can_manage' => $workspace->canManageFiles($request->user(), $workspace),
        ]);
    }

    public function update(Request $request, Equipment $equipment): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $this->assertBelongs($equipment, $workspace);
        abort_unless($workspace->canManageFiles($request->user(), $workspace), 403);

        $equipment->update($this->validateEquipment($request));

        return back()->with('success', __('equipment.updated'));
    }

    public function destroy(Request $request, Equipment $equipment): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $this->assertBelongs($equipment, $workspace);
        abort_unless($workspace->canManageFiles($request->user(), $workspace), 403);

        $equipment->delete();

        return redirect()
            ->route('workspace.equipment.index', ['workspace' => $workspace->slug])
            ->with('success', __('equipment.deleted'));
    }

    // ── Mass actions ─────────────────────────────────────────────────────────

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canManageFiles($request->user(), $workspace), 403);

        // Iterate so each model's deleting hook cascades its documents. lazyById
        // keeps "select all matching" memory-safe on large workspaces.
        $count = 0;
        $this->targetQuery($request, $workspace)->lazyById()->each(function (Equipment $equipment) use (&$count): void {
            $equipment->delete();
            $count++;
        });

        return back()->with('success', trans_choice('equipment.bulk_deleted', $count, ['count' => $count]));
    }

    public function bulkUpdate(Request $request): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canManageFiles($request->user(), $workspace), 403);

        // `category_id` is the new category (null = clear it); the filter params
        // (q/category) below resolve the target set in "select all matching" mode
        // without colliding. The id must belong to this workspace.
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', $this->categoryExistsRule($workspace)],
        ]);

        $count = $this->targetQuery($request, $workspace)
            ->update(['equipment_category_id' => $data['category_id'] ?? null]);

        return back()->with('success', trans_choice('equipment.bulk_updated', $count, ['count' => $count]));
    }

    /**
     * Stream the selected items' documents. One file → direct download; anything
     * more → a ZIP (each item nested under its own name folder when several are
     * selected). `ids` is a comma-separated query param for plain navigation.
     */
    public function bulkZip(Request $request, FileTreeZipper $zipper): BinaryFileResponse
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canViewFiles($request->user(), $workspace), 403);

        $items = $this->targetQuery($request, $workspace)->orderBy('name')->get();
        abort_if($items->isEmpty(), 404);

        $multi = $items->count() > 1;

        // Batch-load every selected item's root file items in one query (no N+1
        // across the selection), then group by owner.
        $ownerType = (new Equipment)->getMorphClass();
        $rootsByOwner = FileItem::query()
            ->where('workspace_id', $workspace->id)
            ->where('owner_type', $ownerType)
            ->whereIn('owner_id', $items->pluck('id'))
            ->whereNull('parent_id')
            ->with(['media'])
            ->orderByRaw("case when type = 'folder' then 0 else 1 end")
            ->orderBy('name')
            ->get()
            ->groupBy('owner_id');

        $groups = [];
        foreach ($items as $equipment) {
            $roots = $rootsByOwner->get($equipment->id) ?? collect();

            if ($roots->isNotEmpty()) {
                $groups[] = ['label' => $multi ? str_replace(['/', '\\'], '-', $equipment->name) : '', 'items' => $roots];
            }
        }
        abort_if($groups === [], 404);

        // Single loose file → hand it back directly rather than a one-entry zip.
        $flat = collect($groups)->flatMap(fn (array $g) => $g['items']);
        if ($flat->count() === 1 && ! $flat->first()->isFolder()) {
            $media = $flat->first()->getFirstMedia('file');
            if ($media && is_file($media->getPath())) {
                return response()->download($media->getPath(), $flat->first()->name);
            }
        }

        return response()->download($zipper->zipGroups($groups, 'equipment'), 'equipment.zip')->deleteFileAfterSend(true);
    }

    /**
     * Set (or clear, with a null file_item_id) the item's cover/main document.
     */
    public function setCover(Request $request, Equipment $equipment): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $this->assertBelongs($equipment, $workspace);
        abort_unless($workspace->canManageFiles($request->user(), $workspace), 403);

        $data = $request->validate(['file_item_id' => ['nullable', 'integer']]);

        $coverId = null;
        if (($data['file_item_id'] ?? null) !== null) {
            $file = FileItem::query()
                ->where('workspace_id', $workspace->id)
                ->forOwner($equipment)
                ->where('type', 'file')
                ->whereKey($data['file_item_id'])
                ->first();
            abort_if($file === null, 422);
            $coverId = $file->id;
        }

        $equipment->update(['cover_file_item_id' => $coverId]);

        return back()->with('success', __('equipment.cover_set'));
    }

    /**
     * Export the (search/category-filtered) list as CSV or XLSX via simple-excel.
     * Written to a temp file then streamed as a download — simpler to test and
     * avoids streaming to php://output mid-request.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canViewFiles($request->user(), $workspace), 403);

        $format = $request->string('format')->toString() === 'xlsx' ? 'xlsx' : 'csv';
        $path = sys_get_temp_dir().'/equipment-'.uniqid('', true).'.'.$format;
        $writer = SimpleExcelWriter::create($path);

        $this->baseQuery($request, $workspace)
            ->withCount('files')
            ->with('category:id,name')
            ->orderBy('name')
            ->cursor()
            ->each(function (Equipment $equipment) use ($writer): void {
                $writer->addRow([
                    'Name' => $equipment->name,
                    'Category' => $equipment->category?->name,
                    'Serial' => $equipment->serial,
                    'Notes' => $equipment->notes,
                    'Files' => $equipment->files_count,
                    'Created' => $equipment->created_at?->toDateTimeString(),
                ]);
            });
        $writer->close();

        return response()->download($path, 'equipment.'.$format)->deleteFileAfterSend(true);
    }

    // ── Trash & restore ──────────────────────────────────────────────────────

    public function trash(Request $request): Response
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canManageFiles($request->user(), $workspace), 403);

        $items = Equipment::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('deleted_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Equipment/Trash', [
            'equipment' => $items,
            'can_manage' => true,
        ]);
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canManageFiles($request->user(), $workspace), 403);

        $equipment = Equipment::onlyTrashed()->where('workspace_id', $workspace->id)->findOrFail($id);
        $equipment->restore();

        return back()->with('success', __('equipment.restored'));
    }

    public function forceDelete(Request $request, int $id): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canManageFiles($request->user(), $workspace), 403);

        $equipment = Equipment::onlyTrashed()->where('workspace_id', $workspace->id)->findOrFail($id);
        $equipment->forceDelete();

        return back()->with('success', __('equipment.purged'));
    }

    /**
     * Resolve a mass action's target set: the full search/category-filtered
     * query when `all` is set ("select all matching"), otherwise the explicit
     * ids. `ids` may be an array (POST) or a comma-separated string (GET).
     *
     * @return Builder<Equipment>
     */
    private function targetQuery(Request $request, Workspace $workspace): Builder
    {
        if ($request->boolean('all')) {
            return $this->baseQuery($request, $workspace);
        }

        return Equipment::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $this->parseIds($request->input('ids')));
    }

    /**
     * The shared base query for index() and export(): workspace-scoped (the
     * global scope already does this; the explicit where is belt-and-braces)
     * with the same search/category filters so both honour the toolbar state.
     *
     * @return Builder<Equipment>
     */
    private function baseQuery(Request $request, Workspace $workspace): Builder
    {
        // Case-insensitive search: ilike on Postgres, like on SQLite/MySQL.
        $op = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return Equipment::query()
            ->where('equipment.workspace_id', $workspace->id)
            ->when($request->string('q')->toString(), function (Builder $query, string $search) use ($op): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function (Builder $q) use ($like, $op): void {
                    $q->where('equipment.name', $op, $like)
                        ->orWhere('equipment.serial', $op, $like)
                        ->orWhereHas('category', fn (Builder $c) => $c->where('name', $op, $like));
                });
            })
            // The category filter carries a category id (the picker's value).
            ->when($request->integer('category'), function (Builder $query, int $categoryId): void {
                $query->where('equipment.equipment_category_id', $categoryId);
            });
    }

    /**
     * First N file previews per owner, in one batched query (no N+1).
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function previewsFor(array $ids, Workspace $workspace): array
    {
        if ($ids === []) {
            return [];
        }

        return FileItem::query()
            ->where('workspace_id', $workspace->id)
            ->where('owner_type', (new Equipment)->getMorphClass())
            ->whereIn('owner_id', $ids)
            ->where('type', 'file')
            ->with(['media'])
            ->orderBy('created_at')
            ->get()
            ->groupBy('owner_id')
            ->map(fn ($group) => FileItemResource::collection($group->take(self::PREVIEW_FILES))->resolve())
            ->all();
    }

    /**
     * Resolve each row's cover: the explicitly-chosen file when set and present
     * in its previews, else the first previewable file (stateless "first
     * document is the cover" default).
     *
     * @param  array<int, Equipment>  $rows
     * @param  array<int, array<int, array<string, mixed>>>  $previews
     * @return array<int, array<string, mixed>|null>
     */
    private function coversFor(array $rows, array $previews): array
    {
        $covers = [];
        foreach ($rows as $equipment) {
            $files = $previews[$equipment->id] ?? [];
            $chosen = null;
            if ($equipment->cover_file_item_id !== null) {
                $chosen = collect($files)->firstWhere('id', $equipment->cover_file_item_id);
            }
            $chosen ??= collect($files)->first(fn (array $f) => ! empty($f['thumbnail_url']));
            $covers[$equipment->id] = $chosen;
        }

        return $covers;
    }

    /**
     * The workspace's categories, shaped for the filter dropdown / pickers
     * (value = id, label = name, plus the chip colour).
     *
     * @return array<int, array{value: int, label: string, color: string|null}>
     */
    private function categories(Workspace $workspace): array
    {
        return EquipmentCategory::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('name')
            ->get(['id', 'name', 'color'])
            ->map(fn (EquipmentCategory $c) => ['value' => $c->id, 'label' => $c->name, 'color' => $c->color])
            ->all();
    }

    /**
     * Validation rule: the category id must be one of this workspace's categories.
     */
    private function categoryExistsRule(Workspace $workspace): Exists
    {
        return Rule::exists('equipment_categories', 'id')->where('workspace_id', $workspace->id);
    }

    /**
     * Lightweight workspace statistics for the index header strip. by_category
     * groups via the relation, carrying each category's chip colour (and a
     * null-keyed "uncategorised" bucket) for the dashboard donut.
     *
     * @return array{total: int, with_files: int, by_category: array<int, array{label: string, count: int, color: string|null}>}
     */
    private function workspaceStats(Workspace $workspace): array
    {
        $base = Equipment::query()->where('workspace_id', $workspace->id);

        // Names + colours for the workspace's categories, to label the grouped counts.
        $names = EquipmentCategory::query()
            ->where('workspace_id', $workspace->id)
            ->get(['id', 'name', 'color'])
            ->keyBy('id');

        $byCategory = (clone $base)->toBase()
            ->selectRaw('equipment_category_id, count(*) as count')
            ->groupBy('equipment_category_id')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => [
                'label' => $r->equipment_category_id !== null && $names->has($r->equipment_category_id)
                    ? $names[$r->equipment_category_id]->name
                    : __('equipment.no_category'),
                'count' => (int) $r->count,
                'color' => $r->equipment_category_id !== null ? ($names[$r->equipment_category_id]->color ?? null) : null,
            ])
            ->all();

        return [
            'total' => (clone $base)->count(),
            'with_files' => (clone $base)->whereHas('files')->count(),
            'by_category' => $byCategory,
        ];
    }

    /**
     * The item's audit trail (created/updated/…) for the Show-page timeline.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activities(Equipment $equipment): array
    {
        return Activity::query()
            ->where('log_name', 'equipment')
            ->where('subject_type', $equipment->getMorphClass())
            ->where('subject_id', $equipment->id)
            ->with('causer:id,email,first_name,last_name')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (Activity $a) => [
                'id' => $a->id,
                'event' => $a->event,
                'description' => $a->description,
                'created_at' => $a->created_at?->toIso8601String(),
                'causer' => $a->causer ? [
                    'id' => $a->causer->getKey(),
                    'name' => trim(($a->causer->first_name ?? '').' '.($a->causer->last_name ?? '')) ?: ($a->causer->email ?? null),
                ] : null,
            ])
            ->all();
    }

    /**
     * Normalise an `ids` payload (array or comma-separated string) to ints.
     *
     * @return array<int, int>
     */
    private function parseIds(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        return collect(is_array($raw) ? $raw : [])
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{name: string, equipment_category_id: int|null, serial: string|null, notes: string|null}
     */
    private function validateEquipment(Request $request): array
    {
        $workspace = $this->currentTenant($request);

        /** @var array{name: string, equipment_category_id: int|null, serial: string|null, notes: string|null} $data */
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'equipment_category_id' => ['nullable', 'integer', $this->categoryExistsRule($workspace)],
            'serial' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
        ]);

        return $data;
    }

    private function assertBelongs(Equipment $equipment, Workspace $workspace): void
    {
        abort_unless($equipment->workspace_id === $workspace->id, 404);
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
     * @return array<int, array{id: int, name: string}>
     */
    private function breadcrumbs(?FileItem $folder): array
    {
        if ($folder === null) {
            return [];
        }

        // Load the owner's folders once and walk the parent chain in memory
        // (the whole chain lives within this owner's tree), so depth no longer
        // costs one query per level.
        $folders = FileItem::query()
            ->where('owner_type', $folder->owner_type)
            ->where('owner_id', $folder->owner_id)
            ->where('type', 'folder')
            ->get(['id', 'name', 'parent_id'])
            ->keyBy('id');

        $crumbs = [];
        $current = $folder;
        while ($current !== null) {
            array_unshift($crumbs, ['id' => $current->id, 'name' => $current->name]);
            $current = $current->parent_id !== null ? ($folders[$current->parent_id] ?? null) : null;
        }

        return $crumbs;
    }
}
