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
> steps — you only need steps 1, 2 (trait), 6, 8, 9. **`app/Domains/EquipmentCategory`** is the
> reference *lean* module: no files, only a Log — and it owns the demo **relation** (Equipment
> `belongsTo` EquipmentCategory; see step 10).

## 0. Fast path — `php artisan make:module`

Scaffold the whole module (migration, model, controller, factory, seeder, config, dashboard
widget, Vue Index/Show/Trash) from these same bare bones, then follow the printed wiring
checklist:

```bash
php artisan make:module Car              # full: files + log (like Equipment)
php artisan make:module Tag --no-files   # lean: a Log but no file area (like EquipmentCategory)
php artisan make:module Note --no-log    # no activity log
```

The flags keep/strip the optional `files` and `log` regions in the stubs, and the printed
`ModuleSeeder` row carries the matching `capabilities` (step 7). The generated PHP is auto-Pinted.
The steps below explain what the generator produces so you can extend it (or build by hand).

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
  => 'car', 'enabled' => config('car.enabled', true), 'capabilities' => ['files' => true, 'log' =>
  true]]`. `capabilities` declares which optional features the **code** ships (`false` for a
  feature you didn't scaffold). For a lean module set `morph_alias` to its own alias for record
  counts even with `'files' => false`.
- `ModuleRegistry::configDefaults()` → add `'car' => (bool) config('car.enabled', true)` so the
  pre-migration fallback knows about it.

**Composable features (files / log).** `capabilities` is the ceiling; the effective state per
workspace is resolved by `ModuleRegistry::featuresFor($workspace)` as *workspace override →
platform toggle → capability* and shared to the front end as the `modules` prop
(`{ car: { enabled, files, log } }`). A **super admin** toggles the platform default in
`/admin/modules`; a **workspace admin** overrides it for their workspace under
`/settings/modules`. Pages read the resolved flags with `useModuleFeatures('car')` to show/hide
their Files / Log surfaces, and controllers skip the matching work (e.g. don't load the Log).

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
`page.props.modules?.car?.enabled` (the `modules` prop is `{ key: { enabled, files, log } }`). To
nest a sub-item under a parent (as Categories sit under Equipment), set `indent: true` on the entry
and anchor the parent's `match` so it doesn't also light up the child route. The datatable,
file-preview lightbox (`useFileMedia`), column-toggle, and mass-action bar are all reusable from the
Equipment pages.

## 10. Child relations (e.g. Car → Wheels)

A child entity (a Wheel belonging to a Car) is just another `BelongsToWorkspace` model with a
`car_id` FK. It can own its own files (give it its own morph alias) or not. Nest its routes under
the parent (`/cars/{car}/wheels`), authorize through the parent's `canManageFiles`, and reuse the
same datatable/`<EntityFiles>` building blocks. It does not need its own `modules` row unless you
want to toggle/stat it independently.

The live example of a relation is **Equipment `belongsTo` EquipmentCategory** (each item is filed
under one category; a category `hasMany` equipment). Note the FK direction is the inverse of
Car → Wheels: there the child (Wheel) holds `car_id`, whereas Equipment holds the
`equipment_category_id` of its *parent* category. The category's Show page demonstrates the reverse
side — a count + a short list + a link to the filtered Equipment index — and the picker is the
reusable `Components/Command/MenuDropdown.vue` (the custom-menu select used for the category
filter). EquipmentCategory is registered as its own `modules` row so it can be toggled and nested
("Categories") under Equipment in the rail.

---

That's it — no schema management, no tenant bootstrappers, no per-query filtering. The single
`use BelongsToWorkspace` line is the whole isolation story; the registry row is the whole
enable/disable story.
