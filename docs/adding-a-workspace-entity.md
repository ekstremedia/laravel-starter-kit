# Adding a workspace-scoped module

This kit uses **single-database, row-level multi-tenancy**: every workspace-scoped table
carries a `workspace_id`, and the `BelongsToWorkspace` global scope filters all queries to the
current workspace (and auto-stamps `workspace_id` on create). There is no schema- or
database-per-tenant — adding a new module (Car, Medicine, Building, …) is just normal
Laravel plus one trait.

The reference implementation is **`app/Domains/Equipment`** (the demo "Equipment" / "Utstyr"
module). It is a full template: workspace scoping, a polymorphic document tree, a searchable +
sortable datatable with toggleable columns, a file-preview column, a cover image, mass actions
(delete / download-zip / re-categorize), CSV/XLSX export, soft-delete trash & restore, an
activity timeline, and per-workspace statistics. Copy its shape. Below is the recipe using a
`Car` example.

> If the module does **not** own files, skip the `FileOwner` / `HasFiles` / morph-map / files
> steps — you only need steps 1, 2 (trait), 6, 8, 9.

## 1. Migration — a `workspace_id` FK

```php
// database/migrations/xxxx_create_cars_table.php
Schema::create('cars', function (Blueprint $table) {
    $table->id();
    $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
    $table->string('make');
    $table->string('model')->nullable();
    // Only if it owns files + wants a cover image (see Equipment):
    $table->foreignId('cover_file_item_id')->nullable()->constrained('file_items')->nullOnDelete();
    $table->timestamps();
    $table->softDeletes();
    $table->index(['workspace_id', 'make']);
});
```

## 2. Model — `use BelongsToWorkspace`

```php
use App\Domains\Workspaces\Models\Concerns\BelongsToWorkspace;
use App\Domains\Files\Contracts\FileOwner;       // only if it owns files
use App\Domains\Files\Models\Concerns\HasFiles;  // only if it owns files

class Car extends Model implements FileOwner
{
    use BelongsToWorkspace;   // ← row-level isolation: auto-scope + auto-stamp workspace_id
    use HasFiles;
    use SoftDeletes;

    protected $fillable = ['make', 'model', 'cover_file_item_id']; // workspace_id is stamped automatically

    // FileOwner: delegate file authorization to the workspace.
    public function canManageFiles(User $user, ?Workspace $workspace = null): bool
    {
        return $this->workspace->canManageFiles($user, $workspace ?? $this->workspace);
    }

    public function canViewFiles(User $user, ?Workspace $workspace = null): bool
    {
        return $this->workspace->canViewFiles($user, $workspace ?? $this->workspace);
    }
}
```

The `BelongsToWorkspace` trait means **you never write `where('workspace_id', …)`** — and a stray
`Car::find($id)` inside another workspace returns nothing, so cross-workspace access by id is
blocked. On central/admin routes (no active workspace) the scope is inert; bypass deliberately
with `Car::withoutGlobalScope('workspace')` (the module registry uses this to count across all
workspaces).

> **Per-entity storage quotas are optional.** Equipment deliberately does *not* use them. If a
> module wants a per-row byte cap on its attached files, add `use HasFileQuota`, a
> `file_quota_bytes` column, and pass a `usage` prop to `<EntityFiles>`. The shared infra
> (`HasFileQuota`, `StorageUsageService`, `app_settings.default_entity_storage_bytes`) is still
> there for modules that want it.

## 3. Morph alias + allowed owner types (only if it owns files)

```php
// app/Providers/AppServiceProvider.php → Relation::morphMap([...])
'car' => Car::class,
```

Add `Car::class` to `config('files.allowed_owner_types')`. Restore-cascade for trash: mirror
`Equipment::booted()` — a `deleting` hook trashes (or force-deletes) the file tree, and a
`restoring` hook un-trashes it.

## 4. Cover image + file-preview column (only if it owns files)

- Add a `cover_file_item_id` FK (step 1) + a `cover()` belongsTo. Resolve the row thumbnail to the
  cover's `thumbnail_url`, falling back to the first previewable file (stateless "first document
  is the cover").
- In the index controller, batch-load the first few files per row in **one** query
  (`FileItem::where('owner_type','car')->whereIn('owner_id',$ids)->where('type','file')->with('media')->get()->groupBy('owner_id')`),
  run them through `FileItemResource`, and expose `files_preview` + `files_count`.
- `<EntityFiles>` takes an opt-in `:allow-set-cover` prop + `@set-cover` emit; the Show page
  PATCHes `…/cover`.

## 5. Bulk ZIP download (only if it owns files)

Reuse `App\Domains\Files\Support\FileTreeZipper` — `zipGroups([['label' => $car->make, 'items' =>
$rootFileItems]])` returns a temp path; stream it with
`response()->download($path, 'cars.zip')->deleteFileAfterSend(true)`. One loose file → hand it
back directly instead of a one-entry zip (see `EquipmentController::bulkZip`).

## 6. Controller — no workspace filtering needed

```php
class CarController extends Controller
{
    public function index(Request $request): Response
    {
        // Global scope already limits this to the current workspace.
        return Inertia::render('Cars/Index', ['cars' => Car::orderBy('make')->paginate(20)]);
    }
}
```

For search, server-side sort, the file-preview column, mass actions (`bulkDestroy` / `bulkUpdate`
/ `bulkZip`), `setCover`, `export` (via `spatie/simple-excel`), and `trash`/`restore`/`forceDelete`,
copy `EquipmentController` method-for-method. Case-insensitive search uses
`DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like'` so it works on Postgres and the
SQLite test DB.

## 7. Register the module in the registry

Every module is a row in the `modules` table, which drives **route gating**, **sidebar
visibility**, the **enabled-modules Inertia share**, and the `/admin/modules` admin page
(enable/disable, record/storage stats, delete-all).

- `config/car.php` → `'enabled' => env('CAR_ENABLED', true)` (the seed default).
- `database/seeders/ModuleSeeder.php` → add a row `['key' => 'car', 'name' => 'Car', 'morph_alias'
  => 'car', 'enabled' => config('car.enabled', true)]`.
- `ModuleRegistry::configDefaults()` → add `'car' => (bool) config('car.enabled', true)` so the
  pre-migration fallback knows about it.

## 8. Routes — in `routes/workspace.php`

Gate the block on the registry and register **literal segments before the numeric `{car}`
catch-all** (so `/cars/export`, `/cars/bulk/*`, `/cars/trash` aren't swallowed as ids):

```php
if (app(\App\Domains\Modules\Services\ModuleRegistry::class)->isEnabled('car')) {
    Route::get('/cars', [CarController::class, 'index'])->name('cars.index');
    Route::get('/cars/export', [CarController::class, 'export'])->name('cars.export');
    Route::get('/cars/bulk/zip', [CarController::class, 'bulkZip'])->name('cars.bulk.zip');
    // … bulk/delete, bulk/update, trash, trash/{id}/restore, trash/{id} …
    Route::get('/cars/{car}', [CarController::class, 'show'])->whereNumber('car')->name('cars.show');
    // … {car}/cover, {car}/folders/{folder}, PUT/DELETE {car} …
}
```

These mount under `/w/{workspace}/cars` when tenancy is on, or at `/cars` when off.

## 9. Vue pages + nav

Add `resources/js/Pages/Cars/{Index,Show,Trash}.vue` (copy the Equipment pages) and an admin page
if needed. For workspace links use the `useWorkspace()` → `workspaceUrl('/cars')` helper. Add a
sidebar entry in `composables/useSidebarItems.ts` gated on
`page.props.modules?.car`. The datatable, file-preview lightbox (`useFileMedia`), column-toggle,
and mass-action bar are all reusable from the Equipment pages.

## 10. Child relations (e.g. Car → Wheels)

A child entity (a Wheel belonging to a Car) is just another `BelongsToWorkspace` model with a
`car_id` FK. It can own its own files (give it its own morph alias) or not. Nest its routes under
the parent (`/cars/{car}/wheels`), authorize through the parent's `canManageFiles`, and reuse the
same datatable/`<EntityFiles>` building blocks. It does not need its own `modules` row unless you
want to toggle/stat it independently.

---

That's it — no schema management, no tenant bootstrappers, no per-query filtering. The single
`use BelongsToWorkspace` line is the whole isolation story; the registry row is the whole
enable/disable story.
