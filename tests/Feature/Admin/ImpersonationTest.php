<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();

    $this->target = User::factory()->create();
});

it('allows an admin to impersonate a non-admin', function () {
    $this->actingAs($this->admin)
        ->post("/admin/users/{$this->target->id}/impersonate")
        ->assertRedirect('/app');

    expect(session('impersonated_by'))->not->toBeNull();
    expect(auth()->id())->toBe($this->target->id);
});

it('forbids impersonating another admin', function () {
    $otherAdmin = User::factory()->create();
    $otherAdmin->forceFill(['is_super_admin' => true])->save();

    $this->actingAs($this->admin)
        ->post("/admin/users/{$otherAdmin->id}/impersonate")
        ->assertForbidden();
});

it('forbids non-admins from impersonating', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post("/admin/users/{$this->target->id}/impersonate")
        ->assertForbidden();
});

it('can leave impersonation and return to the original admin', function () {
    $this->actingAs($this->admin)->post("/admin/users/{$this->target->id}/impersonate");

    // Now we're logged in as target with impersonator set
    $this->post('/impersonate/leave')
        ->assertRedirect('/admin/users');

    expect(auth()->id())->toBe($this->admin->id);
});
