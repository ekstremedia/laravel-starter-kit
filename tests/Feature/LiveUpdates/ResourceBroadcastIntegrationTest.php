<?php

declare(strict_types=1);

use App\Domains\EquipmentCategory\Models\EquipmentCategory;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use App\Support\Events\ResourceChanged;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    AppSetting::current()->update(['files_feature_enabled' => true]);
    $this->admin = makeSuperAdmin(User::factory()->create());
});

it('broadcasts a workspace-scoped ResourceChanged when equipment is created', function () {
    Event::fake([ResourceChanged::class]);

    $workspace = createWorkspace();
    $workspace->update(['files_feature_enabled' => true]);
    $category = EquipmentCategory::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($this->admin)
        ->post(workspaceUrl($workspace, '/equipment'), [
            'name' => 'Forklift #7',
            'equipment_category_id' => $category->id,
        ])
        ->assertRedirect();

    Event::assertDispatched(
        ResourceChanged::class,
        fn (ResourceChanged $e) => $e->resource === 'equipment'
            && $e->action === 'created'
            && $e->workspaceId === $workspace->id,
    );
});

it('broadcasts a central ResourceChanged (no workspace) when an admin creates a role', function () {
    Event::fake([ResourceChanged::class]);

    $this->actingAs($this->admin)
        ->post('/admin/roles', ['name' => 'Moderator', 'permissions' => ['view dashboard']])
        ->assertRedirect('/admin/roles');

    Event::assertDispatched(
        ResourceChanged::class,
        fn (ResourceChanged $e) => $e->resource === 'roles'
            && $e->action === 'created'
            && $e->workspaceId === null,
    );
});

it('does not broadcast when a mutation is rejected by validation', function () {
    Event::fake([ResourceChanged::class]);

    $this->actingAs($this->admin)
        ->post('/admin/roles', ['name' => ''])
        ->assertSessionHasErrors('name');

    Event::assertNotDispatched(ResourceChanged::class);
});
