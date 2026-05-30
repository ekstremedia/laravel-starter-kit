<?php

declare(strict_types=1);

use App\Domains\Equipment\Models\Equipment;
use App\Domains\EquipmentCategory\Models\EquipmentCategory;
use App\Domains\Operations\Models\Activity;
use App\Domains\Users\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    // Seed the module registry so the Log feature resolves on (capabilities).
    $this->seed(ModuleSeeder::class);

    $this->workspace = createWorkspace();
    $this->admin = makeSuperAdmin(User::factory()->create());
});

it('requires authentication', function () {
    $this->get(workspaceUrl($this->workspace, '/equipment-categories'))->assertRedirect('/login');
});

it('lists and creates categories', function () {
    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, '/equipment-categories'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('EquipmentCategories/Index')->has('stats'));

    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/equipment-categories'), [
            'name' => 'Vehicles',
            'color' => '#3b82f6',
            'description' => 'Anything that drives.',
        ])
        ->assertRedirect();

    expect(EquipmentCategory::where('workspace_id', $this->workspace->id)->where('name', 'Vehicles')->where('color', '#3b82f6')->exists())->toBeTrue();
});

it('rejects an invalid colour', function () {
    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/equipment-categories'), ['name' => 'Bad', 'color' => 'red'])
        ->assertSessionHasErrors('color');
});

it('shows a category with its equipment count and log', function () {
    $category = EquipmentCategory::factory()->create(['workspace_id' => $this->workspace->id]);
    Equipment::factory()->count(2)->create(['workspace_id' => $this->workspace->id, 'equipment_category_id' => $category->id]);

    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, "/equipment-categories/{$category->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('EquipmentCategories/Show')
            ->where('category.name', $category->name)
            ->where('equipment.count', 2)
            ->has('equipment.items', 2)
            ->has('activities'));
});

it('blocks non-members', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(workspaceUrl($this->workspace, '/equipment-categories'))
        ->assertForbidden();
});

it('updates and deletes a category', function () {
    $category = EquipmentCategory::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->put(workspaceUrl($this->workspace, "/equipment-categories/{$category->id}"), ['name' => 'Renamed'])
        ->assertRedirect();
    expect($category->fresh()->name)->toBe('Renamed');

    $this->actingAs($this->admin)
        ->delete(workspaceUrl($this->workspace, "/equipment-categories/{$category->id}"))
        ->assertRedirect();
    expect(EquipmentCategory::find($category->id))->toBeNull();
});

it('nulls the equipment FK when a category is permanently deleted', function () {
    $category = EquipmentCategory::factory()->create(['workspace_id' => $this->workspace->id]);
    $equipment = Equipment::factory()->create(['workspace_id' => $this->workspace->id, 'equipment_category_id' => $category->id]);

    $category->delete();
    $this->actingAs($this->admin)
        ->delete(workspaceUrl($this->workspace, "/equipment-categories/trash/{$category->id}"))
        ->assertRedirect();

    expect($equipment->fresh()->equipment_category_id)->toBeNull();
});

it('mass-deletes selected categories', function () {
    $ids = EquipmentCategory::factory()->count(3)->create(['workspace_id' => $this->workspace->id])->pluck('id')->all();

    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/equipment-categories/bulk/delete'), ['ids' => $ids])
        ->assertRedirect();

    expect(EquipmentCategory::whereIn('id', $ids)->count())->toBe(0)
        ->and(EquipmentCategory::withTrashed()->whereIn('id', $ids)->count())->toBe(3);
});

it('exports the list as CSV', function () {
    EquipmentCategory::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Exportable']);

    $res = $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, '/equipment-categories/export?format=csv'));

    $res->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('equipment-categories.csv');
});

it('lists, restores and force-deletes trashed categories', function () {
    $category = EquipmentCategory::factory()->create(['workspace_id' => $this->workspace->id]);
    $category->delete();

    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, '/equipment-categories/trash'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('EquipmentCategories/Trash'));

    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, "/equipment-categories/trash/{$category->id}/restore"))
        ->assertRedirect();
    expect(EquipmentCategory::find($category->id))->not->toBeNull();

    $category->delete();
    $this->actingAs($this->admin)
        ->delete(workspaceUrl($this->workspace, "/equipment-categories/trash/{$category->id}"))
        ->assertRedirect();
    expect(EquipmentCategory::withTrashed()->find($category->id))->toBeNull();
});

it('records an activity entry under the equipment_category log', function () {
    $category = EquipmentCategory::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->put(workspaceUrl($this->workspace, "/equipment-categories/{$category->id}"), ['name' => 'Audited'])
        ->assertRedirect();

    expect(Activity::query()
        ->where('log_name', 'equipment_category')
        ->where('subject_type', 'equipment_category')
        ->where('subject_id', $category->id)
        ->exists())->toBeTrue();
});
