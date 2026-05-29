# Adding a workspace-scoped entity

This kit uses **single-database, row-level multi-tenancy**: every workspace-scoped table
carries a `tenant_id`, and the `BelongsToTenant` global scope filters all queries to the
current workspace (and auto-stamps `tenant_id` on create). There is no schema- or
database-per-tenant — adding a new entity (Car, Medicine, Building, …) is just normal
Laravel plus one trait.

The reference implementation is **`app/Domains/Assets`** (the demo "Assets" module). Copy its
shape. Below is the full recipe using a `Car` example.

> If the entity does **not** own files, skip the `FileOwner` / `HasFiles` / morph-map / files
> steps — you only need steps 1, 2 (trait), 5, 7, 8.

## 1. Migration — a `tenant_id` FK

```php
// database/migrations/xxxx_create_cars_table.php
Schema::create('cars', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
    $table->string('make');
    $table->string('model')->nullable();
    $table->timestamps();
    $table->softDeletes();
    $table->index(['tenant_id']);
});
```

## 2. Model — `use BelongsToTenant`

```php
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Files\Contracts\FileOwner;          // only if it owns files
use App\Domains\Files\Models\Concerns\HasFiles;     // only if it owns files
use App\Domains\Files\Models\Concerns\HasFileQuota; // only if it owns files

class Car extends Model implements FileOwner
{
    use BelongsToTenant;   // ← row-level isolation: auto-scope + auto-stamp tenant_id
    use HasFiles;
    use HasFileQuota;

    protected $fillable = ['make', 'model']; // tenant_id is stamped automatically

    // FileOwner: delegate file authorization to the workspace.
    public function canManageFiles(User $user, ?Tenant $tenant = null): bool
    {
        return $this->tenant->canManageFiles($user, $tenant ?? $this->tenant);
    }

    public function canViewFiles(User $user, ?Tenant $tenant = null): bool
    {
        return $this->tenant->canViewFiles($user, $tenant ?? $this->tenant);
    }
}
```

The `BelongsToTenant` trait means **you never write `where('tenant_id', …)`** — and a stray
`Car::find($id)` inside another workspace returns nothing, so cross-workspace access by id is
blocked. On central/admin routes (no active workspace) the scope is inert; bypass deliberately
with `Car::withoutGlobalScope('tenant')`.

## 3. Morph alias (only if it owns files)

```php
// app/Providers/AppServiceProvider.php → Relation::morphMap([...])
'car' => Car::class,
```

## 4. `config/files.php` (only if it owns files)

Add `'car'` (or `Car::class`) to `allowed_owner_types`.

## 5. Controller — no tenant filtering needed

```php
class CarController extends Controller
{
    public function index(): Response
    {
        // Global scope already limits this to the current workspace.
        return Inertia::render('Cars/Index', ['cars' => Car::orderBy('make')->paginate(20)]);
    }

    public function store(Request $request): RedirectResponse
    {
        // tenant_id is stamped by the trait on create.
        Car::create($request->validate(['make' => 'required', 'model' => 'nullable']));

        return back();
    }
}
```

## 6. Policy (optional)

Add a `CarPolicy` if you want explicit authorization beyond "is a member of the workspace".
Membership + per-workspace role checks resolve via Spatie teams (`$user->can('...')` is already
scoped to the active workspace by `InitializeTenancyByPath`).

## 7. Routes — in `routes/customer.php`

```php
Route::resource('cars', CarController::class);
```

These mount under `/c/{workspace}/cars` when tenancy is on, or at `/cars` when off — no change
on your side.

## 8. Vue pages + nav

Add `resources/js/Pages/Cars/*.vue`. For workspace links use the `useCustomer()` →
`customerUrl('/cars')` helper (or add an entry to `composables/useSidebarItems.ts`); it emits the
right URL in both single- and multi-tenant modes.

---

That's it — no schema management, no tenant bootstrappers, no per-query filtering. The single
`use BelongsToTenant` line is the whole isolation story.
