<?php

declare(strict_types=1);

namespace App\Domains\EquipmentCategory\Http\Controllers;

use App\Domains\Equipment\Models\Equipment;
use App\Domains\EquipmentCategory\Models\EquipmentCategory;
use App\Domains\Modules\Services\ModuleRegistry;
use App\Domains\Operations\Models\Activity;
use App\Domains\Workspaces\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Support\Concerns\BroadcastsResourceChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CRUD for the EquipmentCategory module — the reference *lean* module: the same
 * datatable / create / trash / export / Log standard as Equipment, but with no
 * file area (no upload column, no cover, no zip). It also owns the demo
 * relation: a category's Show page lists the Equipment filed under it.
 *
 * Access piggybacks on the workspace content-management capability (the same
 * gate Equipment uses) — categories are an attribute of equipment, so whoever
 * may view/manage equipment may view/manage its categories.
 */
class EquipmentCategoryController extends Controller
{
    use BroadcastsResourceChanges;

    /** Page size for the index datatable. */
    private const PER_PAGE = 20;

    /** How many of a category's equipment to list on its Show page. */
    private const SHOW_EQUIPMENT = 12;

    public function index(Request $request): Response
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canViewFiles($request->user(), $workspace), 403);

        $allowed = ['name', 'equipment_count', 'created_at'];
        $sort = in_array($request->string('sort')->toString(), $allowed, true)
            ? $request->string('sort')->toString()
            : 'name';
        $dir = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';

        $query = $this->baseQuery($request, $workspace)->withCount('equipment')->orderBy($sort, $dir);
        if ($sort !== 'name') {
            $query->orderBy('name'); // stable secondary order
        }

        $items = $query->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('EquipmentCategories/Index', [
            'categories' => $items,
            'can_manage' => $workspace->canManageEquipment($request->user(), $workspace),
            'search' => $request->string('q')->toString() ?: null,
            'stats' => $this->workspaceStats($workspace),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canManageEquipment($request->user(), $workspace), 403);

        $data = $this->validateCategory($request);
        $category = EquipmentCategory::create([...$data, 'workspace_id' => $workspace->id]);

        $this->broadcastResourceChanged('equipment_categories', 'created', $category->id, $workspace->id);

        return redirect()
            ->route('workspace.equipment-categories.show', ['workspace' => $workspace->slug, 'equipmentCategory' => $category->id])
            ->with('success', __('equipment_category.created', ['name' => $category->name]));
    }

    public function show(Request $request, EquipmentCategory $equipmentCategory): Response
    {
        $workspace = $this->currentTenant($request);
        $this->assertBelongs($equipmentCategory, $workspace);
        abort_unless($workspace->canViewFiles($request->user(), $workspace), 403);

        $equipmentCategory->loadCount('equipment');

        // The demo relation, surfaced as count + a short list + a link to the
        // filtered Equipment index (built on the front end).
        $equipment = $equipmentCategory->equipment()
            ->orderBy('name')
            ->limit(self::SHOW_EQUIPMENT)
            ->get(['id', 'name'])
            ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name])
            ->all();

        return Inertia::render('EquipmentCategories/Show', [
            'category' => [
                'id' => $equipmentCategory->id,
                'name' => $equipmentCategory->name,
                'color' => $equipmentCategory->color,
                'description' => $equipmentCategory->description,
            ],
            'equipment' => [
                'count' => $equipmentCategory->equipment_count,
                'items' => $equipment,
            ],
            // The Log is composable — skip the query when the feature is off.
            'activities' => app(ModuleRegistry::class)->featureEnabled('equipment_category', 'log', $workspace)
                ? $this->activities($equipmentCategory)
                : [],
            'can_manage' => $workspace->canManageEquipment($request->user(), $workspace),
        ]);
    }

    public function update(Request $request, EquipmentCategory $equipmentCategory): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $this->assertBelongs($equipmentCategory, $workspace);
        abort_unless($workspace->canManageEquipment($request->user(), $workspace), 403);

        $equipmentCategory->update($this->validateCategory($request));

        $this->broadcastResourceChanged('equipment_categories', 'updated', $equipmentCategory->id, $workspace->id);

        return back()->with('success', __('equipment_category.updated'));
    }

    public function destroy(Request $request, EquipmentCategory $equipmentCategory): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $this->assertBelongs($equipmentCategory, $workspace);
        abort_unless($workspace->canManageEquipment($request->user(), $workspace), 403);

        $equipmentCategory->delete();

        $this->broadcastResourceChanged('equipment_categories', 'deleted', $equipmentCategory->id, $workspace->id);

        return redirect()
            ->route('workspace.equipment-categories.index', ['workspace' => $workspace->slug])
            ->with('success', __('equipment_category.deleted'));
    }

    // ── Mass actions ─────────────────────────────────────────────────────────

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canManageEquipment($request->user(), $workspace), 403);

        $count = 0;
        $this->targetQuery($request, $workspace)->lazyById()->each(function (EquipmentCategory $category) use (&$count): void {
            $category->delete();
            $count++;
        });

        if ($count > 0) {
            $this->broadcastResourceChanged('equipment_categories', 'deleted', null, $workspace->id);
        }

        return back()->with('success', trans_choice('equipment_category.bulk_deleted', $count, ['count' => $count]));
    }

    /**
     * Export the (search-filtered) list as CSV or XLSX via simple-excel. No ZIP
     * option — categories own no documents.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canViewFiles($request->user(), $workspace), 403);

        $format = $request->string('format')->toString() === 'xlsx' ? 'xlsx' : 'csv';
        $path = sys_get_temp_dir().'/equipment-categories-'.uniqid('', true).'.'.$format;
        $writer = SimpleExcelWriter::create($path);

        $this->baseQuery($request, $workspace)
            ->withCount('equipment')
            ->orderBy('name')
            ->cursor()
            ->each(function (EquipmentCategory $category) use ($writer): void {
                $writer->addRow([
                    'Name' => $category->name,
                    'Color' => $category->color,
                    'Description' => $category->description,
                    'Equipment' => $category->equipment_count,
                    'Created' => $category->created_at?->toDateTimeString(),
                ]);
            });
        $writer->close();

        return response()->download($path, 'equipment-categories.'.$format)->deleteFileAfterSend(true);
    }

    // ── Trash & restore ──────────────────────────────────────────────────────

    public function trash(Request $request): Response
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canManageEquipment($request->user(), $workspace), 403);

        $items = EquipmentCategory::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->withCount('equipment')
            ->orderByDesc('deleted_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('EquipmentCategories/Trash', [
            'categories' => $items,
            'can_manage' => true,
        ]);
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canManageEquipment($request->user(), $workspace), 403);

        $category = EquipmentCategory::onlyTrashed()->where('workspace_id', $workspace->id)->findOrFail($id);
        $category->restore();

        $this->broadcastResourceChanged('equipment_categories', 'updated', $category->id, $workspace->id);

        return back()->with('success', __('equipment_category.restored'));
    }

    public function forceDelete(Request $request, int $id): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        abort_unless($workspace->canManageEquipment($request->user(), $workspace), 403);

        $category = EquipmentCategory::onlyTrashed()->where('workspace_id', $workspace->id)->findOrFail($id);
        $category->forceDelete();

        $this->broadcastResourceChanged('equipment_categories', 'deleted', $id, $workspace->id);

        return back()->with('success', __('equipment_category.purged'));
    }

    /**
     * Resolve a mass action's target set: the full search-filtered query when
     * `all` is set ("select all matching"), otherwise the explicit ids.
     *
     * @return Builder<EquipmentCategory>
     */
    private function targetQuery(Request $request, Workspace $workspace): Builder
    {
        if ($request->boolean('all')) {
            return $this->baseQuery($request, $workspace);
        }

        return EquipmentCategory::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $this->parseIds($request->input('ids')));
    }

    /**
     * Shared base query for index() and export(): workspace-scoped with the
     * search filter so both honour the toolbar state.
     *
     * @return Builder<EquipmentCategory>
     */
    private function baseQuery(Request $request, Workspace $workspace): Builder
    {
        $op = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return EquipmentCategory::query()
            ->where('workspace_id', $workspace->id)
            ->when($request->string('q')->toString(), function (Builder $query, string $search) use ($op): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function (Builder $q) use ($like, $op): void {
                    $q->where('name', $op, $like)
                        ->orWhere('description', $op, $like);
                });
            });
    }

    /**
     * Lightweight workspace statistics for the index header strip.
     *
     * @return array{total: int, with_equipment: int, total_equipment: int}
     */
    private function workspaceStats(Workspace $workspace): array
    {
        $base = EquipmentCategory::query()->where('workspace_id', $workspace->id);

        return [
            'total' => (clone $base)->count(),
            'with_equipment' => (clone $base)->has('equipment')->count(),
            // Scalar count of categorised equipment — avoids hydrating every
            // category row just to sum their counts in PHP.
            'total_equipment' => Equipment::query()
                ->where('workspace_id', $workspace->id)
                ->whereNotNull('equipment_category_id')
                ->count(),
        ];
    }

    /**
     * The category's audit trail for the Show-page Log timeline.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activities(EquipmentCategory $category): array
    {
        return Activity::query()
            ->where('log_name', 'equipment_category')
            ->where('subject_type', $category->getMorphClass())
            ->where('subject_id', $category->id)
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
     * @return array{name: string, color: string|null, description: string|null}
     */
    private function validateCategory(Request $request): array
    {
        /** @var array{name: string, color: string|null, description: string|null} $data */
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'color' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'description' => 'nullable|string|max:2000',
        ]);

        return $data;
    }

    private function assertBelongs(EquipmentCategory $category, Workspace $workspace): void
    {
        abort_unless($category->workspace_id === $workspace->id, 404);
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
}
