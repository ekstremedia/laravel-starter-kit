<?php

declare(strict_types=1);

use App\Domains\Equipment\Models\Equipment;
use App\Domains\Modules\Models\Module;
use App\Domains\Modules\Models\WorkspaceModuleFeature;
use App\Domains\Modules\Services\ModuleRegistry;
use App\Domains\Users\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(ModuleSeeder::class);

    $this->workspace = createWorkspace();
    $this->admin = makeSuperAdmin(User::factory()->create());
    $this->registry = app(ModuleRegistry::class);
});

it('resolves capability-based defaults per module', function () {
    $features = $this->registry->featuresFor($this->workspace);

    expect($features['equipment'])->toMatchArray(['enabled' => true, 'files' => true, 'log' => true])
        // The reference lean module ships a Log but no files.
        ->and($features['equipment_category'])->toMatchArray(['enabled' => true, 'files' => false, 'log' => true]);
});

it('shares the resolved module map to the front end', function () {
    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('modules.equipment.enabled', true)
            ->where('modules.equipment.files', true)
            ->where('modules.equipment_category.files', false)
            ->where('modules.equipment_category.log', true));
});

it('lets a super admin disable a platform feature', function () {
    $module = Module::where('key', 'equipment')->firstOrFail();

    $this->actingAs($this->admin)
        ->patch("/admin/modules/{$module->id}", ['enabled' => true, 'features' => ['files' => false]])
        ->assertRedirect();

    expect($this->registry->featuresFor($this->workspace)['equipment']['files'])->toBeFalse();
});

it('never enables a feature the module code does not ship', function () {
    $category = Module::where('key', 'equipment_category')->firstOrFail();

    $this->actingAs($this->admin)
        ->patch("/admin/modules/{$category->id}", ['enabled' => true, 'features' => ['files' => true]])
        ->assertRedirect();

    // Capability clamp: equipment_category ships no files, so it stays off.
    expect($this->registry->featuresFor($this->workspace)['equipment_category']['files'])->toBeFalse();
});

it('lets a workspace admin override a feature for their workspace only', function () {
    $other = createWorkspace('beta');
    $module = Module::where('key', 'equipment')->firstOrFail();

    $this->actingAs($this->admin)
        ->patch(workspaceUrl($this->workspace, "/settings/modules/{$module->id}"), ['feature' => 'log', 'enabled' => false])
        ->assertRedirect();

    expect($this->registry->featuresFor($this->workspace)['equipment']['log'])->toBeFalse()
        ->and($this->registry->featuresFor($other)['equipment']['log'])->toBeTrue();
});

it('resets a workspace override back to the platform default', function () {
    $module = Module::where('key', 'equipment')->firstOrFail();

    $this->actingAs($this->admin)
        ->patch(workspaceUrl($this->workspace, "/settings/modules/{$module->id}"), ['feature' => 'log', 'enabled' => false])
        ->assertRedirect();
    expect($this->registry->featuresFor($this->workspace)['equipment']['log'])->toBeFalse();

    $this->actingAs($this->admin)
        ->delete(workspaceUrl($this->workspace, "/settings/modules/{$module->id}"))
        ->assertRedirect();
    expect($this->registry->featuresFor($this->workspace)['equipment']['log'])->toBeTrue();
});

it('shows the workspace module-settings page to admins and forbids others', function () {
    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, '/settings/modules'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Workspace/ModuleSettings')->has('module_settings'));

    $stranger = User::factory()->create();
    $this->actingAs($stranger)
        ->get(workspaceUrl($this->workspace, '/settings/modules'))
        ->assertForbidden();
});

it('lets a workspace admin disable a whole module for their workspace only', function () {
    $other = createWorkspace('beta');
    $module = Module::where('key', 'equipment')->firstOrFail();

    $this->actingAs($this->admin)
        ->patch(workspaceUrl($this->workspace, "/settings/modules/{$module->id}"), ['feature' => 'enabled', 'enabled' => false])
        ->assertRedirect();

    expect($this->registry->featuresFor($this->workspace)['equipment']['enabled'])->toBeFalse()
        ->and($this->registry->featuresFor($other)['equipment']['enabled'])->toBeTrue();

    // The module's routes 404 for the workspace that turned it off, not the other.
    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, '/equipment'))
        ->assertNotFound();
    $this->actingAs($this->admin)
        ->get(workspaceUrl($other, '/equipment'))
        ->assertOk();
});

it('cascades a disabled parent module to its grouped children', function () {
    $equipment = Module::where('key', 'equipment')->firstOrFail();
    $category = Module::where('key', 'equipment_category')->firstOrFail();

    $this->actingAs($this->admin)
        ->patch(workspaceUrl($this->workspace, "/settings/modules/{$equipment->id}"), ['feature' => 'enabled', 'enabled' => false])
        ->assertRedirect();

    // The child is forced off purely by resolution — it keeps no override of its own.
    expect($this->registry->featuresFor($this->workspace)['equipment_category']['enabled'])->toBeFalse()
        ->and(WorkspaceModuleFeature::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('module_id', $category->id)
            ->exists())->toBeFalse();

    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, '/equipment-categories'))
        ->assertNotFound();
});

it('resets a module enable override back to the platform default', function () {
    $module = Module::where('key', 'equipment')->firstOrFail();

    $this->actingAs($this->admin)
        ->patch(workspaceUrl($this->workspace, "/settings/modules/{$module->id}"), ['feature' => 'enabled', 'enabled' => false])
        ->assertRedirect();
    expect($this->registry->featuresFor($this->workspace)['equipment']['enabled'])->toBeFalse();

    $this->actingAs($this->admin)
        ->delete(workspaceUrl($this->workspace, "/settings/modules/{$module->id}"))
        ->assertRedirect();
    expect($this->registry->featuresFor($this->workspace)['equipment']['enabled'])->toBeTrue();

    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, '/equipment'))
        ->assertOk();
});

it('will not let a workspace enable a module the platform disabled', function () {
    $module = Module::where('key', 'equipment')->firstOrFail();

    // Super admin turns Equipment off platform-wide.
    $this->actingAs($this->admin)
        ->patch("/admin/modules/{$module->id}", ['enabled' => false, 'features' => []])
        ->assertRedirect();

    // A workspace admin can't resurrect it for their workspace.
    $this->actingAs($this->admin)
        ->patchJson(workspaceUrl($this->workspace, "/settings/modules/{$module->id}"), ['feature' => 'enabled', 'enabled' => true])
        ->assertStatus(422);

    expect($this->registry->featuresFor($this->workspace)['equipment']['enabled'])->toBeFalse();
});

it('skips the equipment Log payload when the log feature is off', function () {
    $module = Module::where('key', 'equipment')->firstOrFail();
    $this->actingAs($this->admin)
        ->patch("/admin/modules/{$module->id}", ['enabled' => true, 'features' => ['log' => false]])
        ->assertRedirect();

    $equipment = Equipment::factory()->create(['workspace_id' => $this->workspace->id]);
    // Generate an activity that WOULD show if the Log were on.
    $equipment->update(['name' => 'Touched']);

    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, "/equipment/{$equipment->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('activities', []));
});
