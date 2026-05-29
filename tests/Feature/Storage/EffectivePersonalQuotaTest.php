<?php

declare(strict_types=1);

use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;

beforeEach(function () {
    $this->service = app(StorageUsageService::class);
    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create();
    $this->user->customers()->attach($this->workspace);
    // Fresh installs now ship with a 5 GB global default, but these tests
    // exercise the 3-tier resolver from a blank slate — clear the seeded
    // value so "no override at any level" really means unlimited.
    AppSetting::current()->update(['default_personal_storage_bytes' => null]);
});

it('returns null when nothing is set at any level (unlimited)', function () {
    expect($this->service->effectivePersonalQuota($this->user, $this->workspace))->toBeNull();
});

it('falls back to the global app default when user and tenant are unset', function () {
    AppSetting::current()->update(['default_personal_storage_bytes' => 5_000_000]);

    expect($this->service->effectivePersonalQuota($this->user->fresh(), $this->workspace->fresh()))->toBe(5_000_000);
});

it('customer default overrides the global default', function () {
    AppSetting::current()->update(['default_personal_storage_bytes' => 5_000_000]);
    $this->workspace->update(['default_member_storage_bytes' => 10_000_000]);

    expect($this->service->effectivePersonalQuota($this->user->fresh(), $this->workspace->fresh()))->toBe(10_000_000);
});

it('user override beats both customer and global defaults', function () {
    AppSetting::current()->update(['default_personal_storage_bytes' => 5_000_000]);
    $this->workspace->update(['default_member_storage_bytes' => 10_000_000]);
    $this->user->settings()->merge(['storage_quota_override' => 2_000_000]);

    expect($this->service->effectivePersonalQuota($this->user->fresh(), $this->workspace->fresh()))->toBe(2_000_000);
});

it('-1 at user level means explicit unlimited (returns null)', function () {
    AppSetting::current()->update(['default_personal_storage_bytes' => 5_000_000]);
    $this->user->settings()->merge(['storage_quota_override' => -1]);

    expect($this->service->effectivePersonalQuota($this->user->fresh(), $this->workspace->fresh()))->toBeNull();
});

it('-1 at tenant level overrides a positive global default with unlimited', function () {
    AppSetting::current()->update(['default_personal_storage_bytes' => 5_000_000]);
    $this->workspace->update(['default_member_storage_bytes' => -1]);

    expect($this->service->effectivePersonalQuota($this->user->fresh(), $this->workspace->fresh()))->toBeNull();
});

it('0 at user level blocks uploads even when upstream defaults are unlimited', function () {
    $this->user->settings()->merge(['storage_quota_override' => 0]);

    expect($this->service->effectivePersonalQuota($this->user->fresh(), $this->workspace->fresh()))->toBe(0);
});
