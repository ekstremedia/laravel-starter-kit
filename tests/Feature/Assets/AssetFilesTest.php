<?php

declare(strict_types=1);

use App\Domains\Assets\Models\Asset;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Asset demo entity is the reference implementation of "attach files to
 * any entity": it adopts the FileOwner contract via HasFiles + HasFileQuota.
 * These cover the polymorphic ownership, the quota-inheritance chain, the
 * morph alias, and the delegated (tenant-scoped) file permissions.
 */
beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->customer = createCustomer();
    $this->asset = Asset::factory()->create(['workspace_id' => $this->customer->id]);
});

it('owns a polymorphic file tree', function () {
    FileItem::factory()->count(3)->ownedBy($this->asset)->create([
        'workspace_id' => $this->customer->id,
        'user_id' => User::factory()->create()->id,
    ]);

    expect($this->asset->files()->count())->toBe(3)
        ->and(FileItem::query()->forOwner($this->asset)->count())->toBe(3);
});

it('exposes a stable morph alias', function () {
    expect($this->asset->getMorphClass())->toBe('asset');
});

it('resolves quota: per-row override → app default → unlimited', function () {
    $service = app(StorageUsageService::class);
    AppSetting::current()->update(['default_entity_storage_bytes' => null]);

    // No override, no app default → unlimited.
    expect($service->effectiveQuota($this->asset, $this->customer))->toBeNull();

    // App default applies when no per-row override.
    AppSetting::current()->update(['default_entity_storage_bytes' => 5000]);
    expect($service->effectiveQuota($this->asset->fresh(), $this->customer))->toBe(5000);

    // Per-row override wins over the app default.
    $this->asset->update(['file_quota_bytes' => 1000]);
    expect($service->effectiveQuota($this->asset->fresh(), $this->customer))->toBe(1000);

    // -1 = explicit unlimited, overriding the app default.
    $this->asset->update(['file_quota_bytes' => -1]);
    expect($service->effectiveQuota($this->asset->fresh(), $this->customer))->toBeNull();
});

it('denormalizes storage_used_bytes on recompute', function () {
    $service = app(StorageUsageService::class);

    expect($service->recomputeForOwner($this->asset))->toBe(0)
        ->and($this->asset->fresh()->storage_used_bytes)->toBe(0);
});

it('delegates file permissions to the owning customer', function () {
    $admin = User::factory()->create();
    joinCustomer($admin, $this->customer, 'Admin');

    // Scope permission resolution to the customer team *after* membership
    // exists — joinCustomer() resets the team id to null when it returns.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->customer->id);

    $stranger = User::factory()->create();

    expect($this->asset->canManageFiles($admin, $this->customer))->toBeTrue()
        ->and($this->asset->canViewFiles($admin, $this->customer))->toBeTrue()
        ->and($this->asset->canManageFiles($stranger, $this->customer))->toBeFalse();
});

it('authorizes asset-owned files through FileItemPolicy', function () {
    $admin = User::factory()->create();
    joinCustomer($admin, $this->customer, 'Admin');
    // Match the request lifecycle: team scope is set after membership exists.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->customer->id);

    $item = FileItem::factory()->ownedBy($this->asset)->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $admin->id,
    ]);

    expect(Gate::forUser($admin)->check('view', [$item, $this->customer]))->toBeTrue()
        ->and(Gate::forUser($admin)->check('update', [$item, $this->customer]))->toBeTrue();
});
