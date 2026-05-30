<?php

declare(strict_types=1);

use App\Domains\Equipment\Models\Equipment;
use App\Domains\Files\Models\FileItem;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Equipment module is the reference implementation of "attach files to any
 * entity": it adopts the FileOwner contract via HasFiles. These cover the
 * polymorphic ownership, the morph alias, and the delegated (workspace-scoped)
 * file permissions. (Equipment opts out of per-row storage quotas.)
 */
beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->workspace = createWorkspace();
    $this->equipment = Equipment::factory()->create(['workspace_id' => $this->workspace->id]);
});

it('owns a polymorphic file tree', function () {
    FileItem::factory()->count(3)->ownedBy($this->equipment)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => User::factory()->create()->id,
    ]);

    expect($this->equipment->files()->count())->toBe(3)
        ->and(FileItem::query()->forOwner($this->equipment)->count())->toBe(3);
});

it('exposes a stable morph alias', function () {
    expect($this->equipment->getMorphClass())->toBe('equipment');
});

it('cascades and restores its file tree on soft delete + restore', function () {
    FileItem::factory()->count(2)->ownedBy($this->equipment)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => User::factory()->create()->id,
    ]);

    $this->equipment->delete();
    expect(FileItem::query()->forOwner($this->equipment)->count())->toBe(0)
        ->and(FileItem::withTrashed()->forOwner($this->equipment)->count())->toBe(2);

    $this->equipment->restore();
    expect(FileItem::query()->forOwner($this->equipment)->count())->toBe(2);
});

it('delegates file permissions to the owning workspace', function () {
    $admin = User::factory()->create();
    joinWorkspace($admin, $this->workspace, 'Admin');

    // Scope permission resolution to the workspace team *after* membership
    // exists — joinWorkspace() resets the team id to null when it returns.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);

    $stranger = User::factory()->create();

    expect($this->equipment->canManageFiles($admin, $this->workspace))->toBeTrue()
        ->and($this->equipment->canViewFiles($admin, $this->workspace))->toBeTrue()
        ->and($this->equipment->canManageFiles($stranger, $this->workspace))->toBeFalse();
});

it('authorizes equipment-owned files through FileItemPolicy', function () {
    $admin = User::factory()->create();
    joinWorkspace($admin, $this->workspace, 'Admin');
    // Match the request lifecycle: team scope is set after membership exists.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);

    $item = FileItem::factory()->ownedBy($this->equipment)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $admin->id,
    ]);

    expect(Gate::forUser($admin)->check('view', [$item, $this->workspace]))->toBeTrue()
        ->and(Gate::forUser($admin)->check('update', [$item, $this->workspace]))->toBeTrue();
});
