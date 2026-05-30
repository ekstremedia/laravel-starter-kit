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

// ── Equipment categories ─────────────────────────────────────────────────────

it('returns a single category row with equipment_count', function () {
    $category = EquipmentCategory::factory()->create(['workspace_id' => $this->workspace->id]);
    Equipment::factory()->count(2)->create(['workspace_id' => $this->workspace->id, 'equipment_category_id' => $category->id]);

    $this->actingAs($this->admin)
        ->getJson(workspaceUrl($this->workspace, "/equipment-categories/{$category->id}/live-row"))
        ->assertOk()
        ->assertJson(['id' => $category->id, 'equipment_count' => 2])
        ->assertJsonStructure(['id', 'name', 'color', 'description', 'equipment_count']);
});

it('404s a category in another workspace', function () {
    $other = createWorkspace('other');
    $foreign = EquipmentCategory::factory()->create(['workspace_id' => $other->id]);

    $this->actingAs($this->admin)
        ->getJson(workspaceUrl($this->workspace, "/equipment-categories/{$foreign->id}/live-row"))
        ->assertNotFound();
});

// ── Workspace members ────────────────────────────────────────────────────────

it('returns a single member row in the index shape for a workspace admin', function () {
    $wsAdmin = User::factory()->create();
    grantRoleOnWorkspace($wsAdmin, 'Admin', $this->workspace);

    $member = User::factory()->create(['email' => 'member@example.test']);
    grantRoleOnWorkspace($member, 'Editor', $this->workspace);

    $this->actingAs($wsAdmin)
        ->getJson(workspaceUrl($this->workspace, "/members/{$member->id}/live-row"))
        ->assertOk()
        ->assertExactJson([
            'id' => $member->id,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'full_name' => $member->fullName(),
            'email' => 'member@example.test',
            'roles' => ['Editor'],
        ]);
});

it('404s a member live row for a non-member of the workspace', function () {
    $wsAdmin = User::factory()->create();
    grantRoleOnWorkspace($wsAdmin, 'Admin', $this->workspace);
    $stranger = User::factory()->create();

    $this->actingAs($wsAdmin)
        ->getJson(workspaceUrl($this->workspace, "/members/{$stranger->id}/live-row"))
        ->assertNotFound();
});

it('forbids a non-admin member from a member live row', function () {
    $regular = User::factory()->create();
    grantRoleOnWorkspace($regular, 'User', $this->workspace);
    $other = User::factory()->create();
    grantRoleOnWorkspace($other, 'User', $this->workspace);

    $this->actingAs($regular)
        ->getJson(workspaceUrl($this->workspace, "/members/{$other->id}/live-row"))
        ->assertForbidden();
});
