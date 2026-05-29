<?php

declare(strict_types=1);

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('exposes each member with their workspace-scoped role(s) and a public_id on the admin edit page', function () {
    $super = makeSuperAdmin(User::factory()->create());
    $workspace = createWorkspace();

    $admin = User::factory()->create(['email' => 'admin@example.test']);
    $editor = User::factory()->create(['email' => 'editor@example.test']);

    grantRoleOnWorkspace($admin, 'Admin', $workspace);
    grantRoleOnWorkspace($editor, 'Editor', $workspace);

    $this->actingAs($super)
        ->get(route('admin.workspaces.edit', $workspace))
        ->assertOk()
        ->assertInertia(function ($page) use ($admin, $editor) {
            $users = collect($page->toArray()['props']['workspace']['users']);

            $a = $users->firstWhere('email', $admin->email);
            $e = $users->firstWhere('email', $editor->email);

            expect($a)->not->toBeNull();
            expect($e)->not->toBeNull();
            expect($a['public_id'])->toBe($admin->public_id);
            expect($e['public_id'])->toBe($editor->public_id);
            expect($a['roles'])->toContain('Admin');
            expect($e['roles'])->toContain('Editor');
        });
});

it('saves workspace name and status from the admin edit page', function () {
    $super = makeSuperAdmin(User::factory()->create());
    $workspace = createWorkspace();

    $this->actingAs($super)
        ->patch(route('admin.workspaces.update', $workspace), [
            'name' => 'Updated Co',
            'status' => 'suspended',
        ])
        ->assertSessionHasNoErrors();

    $fresh = $workspace->fresh();
    expect($fresh->name)->toBe('Updated Co');
    expect($fresh->status)->toBe('suspended');
});
