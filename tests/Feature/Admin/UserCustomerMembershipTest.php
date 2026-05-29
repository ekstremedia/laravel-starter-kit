<?php

use App\Domains\Notifications\Notifications\WorkspaceMemberAddedNotification;
use App\Domains\Notifications\Notifications\WorkspaceMemberRemovedNotification;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Support\WorkspaceMembership;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config()->set('workspaces.enabled', true);
    $this->seed(RoleAndPermissionSeeder::class);
});

it('shows customers on user show page when tenancy enabled', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $user = User::factory()->create();
    $customer = createCustomer();
    joinCustomer($user, $customer);

    $this->actingAs($admin)
        ->get("/admin/users/{$user->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('user.customers', 1)
            ->where('user.customers.0.slug', $customer->slug)
        );
});

it('shows customers and all_customers on user edit page', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $user = User::factory()->create();
    $customer = createCustomer();
    joinCustomer($user, $customer);

    $this->actingAs($admin)
        ->get("/admin/users/{$user->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('user.customers', 1)
            ->has('all_customers', 1)
        );
});

it('allows admin to attach a customer to a user', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $user = User::factory()->create();
    $customer = createCustomer();

    $this->actingAs($admin)
        ->post("/admin/users/{$user->id}/customers", [
            'customer_ids' => [$customer->id],
            'roles' => ['User'],
            'notify' => false,
        ])
        ->assertRedirect();

    expect($user->fresh()->customers()->count())->toBe(1);
});

it('allows admin to detach a customer from a user', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $user = User::factory()->create();
    $customer = createCustomer();
    joinCustomer($user, $customer);

    $this->actingAs($admin)
        ->delete("/admin/users/{$user->id}/customers/{$customer->id}", [
            'notify' => false,
        ])
        ->assertRedirect();

    expect($user->fresh()->customers()->count())->toBe(0);
});

it('dispatches notification when notify is true on attach', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $user = User::factory()->create();
    $customer = createCustomer();

    $this->actingAs($admin)
        ->post("/admin/users/{$user->id}/customers", [
            'customer_ids' => [$customer->id],
            'roles' => ['User'],
            'notify' => true,
        ])
        ->assertRedirect();

    Notification::assertSentTo($user, WorkspaceMemberAddedNotification::class);
});

it('does not dispatch notification when notify is false', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $user = User::factory()->create();
    $customer = createCustomer();

    $this->actingAs($admin)
        ->post("/admin/users/{$user->id}/customers", [
            'customer_ids' => [$customer->id],
            'roles' => ['User'],
            'notify' => false,
        ])
        ->assertRedirect();

    Notification::assertNotSentTo($user, WorkspaceMemberAddedNotification::class);
});

it('dispatches removal notification when notify is true on detach', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $user = User::factory()->create();
    $customer = createCustomer();
    joinCustomer($user, $customer);

    $this->actingAs($admin)
        ->delete("/admin/users/{$user->id}/customers/{$customer->id}", [
            'notify' => true,
        ])
        ->assertRedirect();

    Notification::assertSentTo($user, WorkspaceMemberRemovedNotification::class);
});

it('lets super admin set a users role on a specific customer', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $user = User::factory()->create();
    $customer = createCustomer();
    grantRoleOnCustomer($user, 'User', $customer);

    $this->actingAs($admin)
        ->patch("/admin/users/{$user->id}/customers/{$customer->id}/role", ['roles' => ['Admin']])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(WorkspaceMembership::roleOn($user->fresh(), $customer))->toBe('Admin');
});

it('rejects setting a customer role for a user not in the customer', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $user = User::factory()->create();
    $customer = createCustomer();

    $this->actingAs($admin)
        ->patch("/admin/users/{$user->id}/customers/{$customer->id}/role", ['roles' => ['Admin']])
        ->assertRedirect();

    expect(WorkspaceMembership::roleOn($user->fresh(), $customer))->toBeNull();
});

it('shows per-customer roles in the user show payload', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $user = User::factory()->create();
    $a = createCustomer('a-co', 'A Co');
    $b = createCustomer('b-co', 'B Co');
    grantRoleOnCustomer($user, 'Admin', $a);
    grantRoleOnCustomer($user, 'User', $b);

    $this->actingAs($admin)
        ->get("/admin/users/{$user->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('user.customers', 2)
            ->where('user.customers', fn ($customers) => collect($customers)
                ->contains(fn ($c) => $c['slug'] === 'a-co' && $c['roles'] === ['Admin'])
                && collect($customers)->contains(fn ($c) => $c['slug'] === 'b-co' && $c['roles'] === ['User'])
            )
        );
});

it('rejects non-admin from attaching customers', function () {
    $user = User::factory()->create();

    $target = User::factory()->create();
    $customer = createCustomer();

    $this->actingAs($user)
        ->post("/admin/users/{$target->id}/customers", [
            'customer_ids' => [$customer->id],
            'roles' => ['User'],
            'notify' => false,
        ])
        ->assertForbidden();
});
