<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('grants viewHorizon to admins', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue();
});

it('denies viewHorizon to non-admins', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});

it('grants viewPulse to admins', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    expect(Gate::forUser($admin)->allows('viewPulse'))->toBeTrue();
});

it('denies viewPulse to non-admins', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewPulse'))->toBeFalse();
});
