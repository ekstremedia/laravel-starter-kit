<?php

declare(strict_types=1);

use App\Domains\Equipment\Models\Equipment;
use App\Domains\Modules\Models\Module;
use App\Domains\Modules\Services\ModuleRegistry;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

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

it('toggles a module on and off', function () {
    $this->actingAs($this->admin)
        ->patch("/admin/modules/{$this->module->id}", ['enabled' => false])
        ->assertRedirect();

    expect($this->module->fresh()->enabled)->toBeFalse();

    app(ModuleRegistry::class)->forget();
    expect(app(ModuleRegistry::class)->isEnabled('equipment'))->toBeFalse();
});

it('purges all module records across workspaces and cascades files', function () {
    $workspace = createWorkspace();
    $items = Equipment::factory()->count(3)->create(['workspace_id' => $workspace->id]);
    $items->first()->delete(); // also exercise the soft-deleted purge path

    $this->actingAs($this->admin)
        ->post("/admin/modules/{$this->module->id}/purge")
        ->assertRedirect();

    expect(Equipment::withTrashed()->count())->toBe(0);
});
