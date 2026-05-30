<?php

declare(strict_types=1);

use App\Domains\Modules\Models\Module;
use App\Domains\Users\Models\User;
use App\Support\Events\ResourceChanged;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('broadcasts "users updated" when a super admin changes a role', function () {
    Event::fake([ResourceChanged::class]);
    $admin = makeSuperAdmin(User::factory()->create());
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->patch("/admin/users/{$target->id}/role", ['role' => 'SuperAdmin'])
        ->assertRedirect();

    Event::assertDispatched(
        ResourceChanged::class,
        fn (ResourceChanged $e) => $e->resource === 'users' && $e->action === 'updated' && $e->id === $target->id,
    );
});

it('does not broadcast on a rejected role change (self)', function () {
    Event::fake([ResourceChanged::class]);
    $admin = makeSuperAdmin(User::factory()->create());

    $this->actingAs($admin)
        ->patch("/admin/users/{$admin->id}/role", ['role' => 'none'])
        ->assertSessionHas('error');

    Event::assertNotDispatched(ResourceChanged::class);
});

it('broadcasts "module_settings updated" when a workspace admin toggles a feature', function () {
    Event::fake([ResourceChanged::class]);
    $workspace = createWorkspace();
    $admin = User::factory()->create();
    grantRoleOnWorkspace($admin, 'Admin', $workspace);

    $module = Module::create([
        'key' => 'equipment',
        'name' => 'Equipment',
        'enabled' => true,
        'morph_alias' => 'equipment',
        'capabilities' => ['files' => true, 'log' => true],
    ]);

    $this->actingAs($admin)
        ->patch(workspaceUrl($workspace, "/settings/modules/{$module->id}"), ['feature' => 'files', 'enabled' => false])
        ->assertRedirect();

    Event::assertDispatched(
        ResourceChanged::class,
        fn (ResourceChanged $e) => $e->resource === 'module_settings'
            && $e->action === 'updated'
            && $e->id === $module->id
            && $e->workspaceId === $workspace->id,
    );
});
