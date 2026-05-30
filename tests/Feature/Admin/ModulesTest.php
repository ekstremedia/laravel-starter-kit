<?php

declare(strict_types=1);

use App\Domains\Equipment\Models\Equipment;
use App\Domains\Files\Models\FileItem;
use App\Domains\Modules\Models\Module;
use App\Domains\Modules\Services\ModuleRegistry;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->admin = makeSuperAdmin(User::factory()->create());
    $this->module = Module::create([
        'key' => 'equipment',
        'name' => 'Equipment',
        'enabled' => true,
        'morph_alias' => 'equipment',
    ]);
});

it('lists modules for super admins', function () {
    $this->actingAs($this->admin)
        ->get('/admin/modules')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Modules/Index')->has('modules', 1));
});

it('forbids non-super-admins', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/modules')
        ->assertForbidden();
});

it('toggles a module and invalidates the registry cache', function () {
    // Warm the singleton's memo so this proves the controller's forget() call,
    // not a fresh read. The same instance must reflect the toggle afterwards.
    $registry = app(ModuleRegistry::class);
    expect($registry->isEnabled('equipment'))->toBeTrue();

    $this->actingAs($this->admin)
        ->patch("/admin/modules/{$this->module->id}", ['enabled' => false])
        ->assertRedirect();

    expect($this->module->fresh()->enabled)->toBeFalse()
        ->and($registry->isEnabled('equipment'))->toBeFalse();
});

it('404s a disabled module\'s routes at request time', function () {
    $workspace = createWorkspace();

    // Enabled by default → reachable.
    $this->actingAs($this->admin)
        ->get(workspaceUrl($workspace, '/equipment'))
        ->assertOk();

    // Disable in the registry → the module middleware 404s the same route
    // without re-registering routes (works with route:cache).
    $this->module->update(['enabled' => false]);
    app(ModuleRegistry::class)->forget();

    $this->actingAs($this->admin)
        ->get(workspaceUrl($workspace, '/equipment'))
        ->assertNotFound();
});

it('purges all module records across workspaces and cascades files', function () {
    Storage::fake('public');
    $workspace = createWorkspace();
    $items = Equipment::factory()->count(3)->create(['workspace_id' => $workspace->id]);
    $items->first()->delete(); // also exercise the soft-deleted purge path

    // Attach a document so the file-tree cascade is actually asserted.
    FileItem::factory()->ownedBy($items->last())->create([
        'workspace_id' => $workspace->id,
        'user_id' => $this->admin->id,
    ]);
    expect(FileItem::query()->where('owner_type', 'equipment')->count())->toBe(1);

    $this->actingAs($this->admin)
        ->post("/admin/modules/{$this->module->id}/purge")
        ->assertRedirect();

    expect(Equipment::withTrashed()->count())->toBe(0)
        ->and(FileItem::withTrashed()->where('owner_type', 'equipment')->count())->toBe(0);
});
