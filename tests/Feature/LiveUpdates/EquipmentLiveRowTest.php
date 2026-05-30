<?php

declare(strict_types=1);

use App\Domains\Equipment\Models\Equipment;
use App\Domains\EquipmentCategory\Models\EquipmentCategory;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    AppSetting::current()->update(['files_feature_enabled' => true]);
    $this->workspace = createWorkspace();
    $this->workspace->update(['files_feature_enabled' => true]);
    $this->admin = makeSuperAdmin(User::factory()->create());
});

it('returns a single equipment row in the index list-shape', function () {
    $category = EquipmentCategory::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Machine']);
    $equipment = Equipment::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Forklift #9',
        'equipment_category_id' => $category->id,
    ]);

    $this->actingAs($this->admin)
        ->getJson(workspaceUrl($this->workspace, "/equipment/{$equipment->id}/live-row"))
        ->assertOk()
        ->assertJson([
            'id' => $equipment->id,
            'name' => 'Forklift #9',
            'files_count' => 0,
            'category' => ['id' => $category->id, 'name' => 'Machine'],
        ])
        ->assertJsonStructure(['id', 'name', 'files_count', 'files_preview', 'cover', 'category']);
});

it('404s for equipment in another workspace', function () {
    $other = createWorkspace('other');
    $equipment = Equipment::factory()->create(['workspace_id' => $other->id]);

    $this->actingAs($this->admin)
        ->getJson(workspaceUrl($this->workspace, "/equipment/{$equipment->id}/live-row"))
        ->assertNotFound();
});

it('forbids a non-member', function () {
    $equipment = Equipment::factory()->create(['workspace_id' => $this->workspace->id]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(workspaceUrl($this->workspace, "/equipment/{$equipment->id}/live-row"))
        ->assertForbidden();
});
