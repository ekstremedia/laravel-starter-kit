<?php

declare(strict_types=1);

use App\Domains\Tenancy\Support\CustomerMembership;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->customer = createCustomer();
    $this->admin = User::factory()->create();
    grantRoleOnCustomer($this->admin, 'Admin', $this->customer);
});

it('lets a customer-Admin view the members index for their customer', function () {
    $this->actingAs($this->admin)
        ->get(customerUrl($this->customer, '/members'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customer/Members/Index')
            ->has('members')
            ->where('assignable_roles', ['Admin', 'Editor', 'User'])
        );
});

it('forbids a customer-Admin from accessing a customer they are not an Admin on', function () {
    $other = createCustomer('other', 'Other');

    $this->actingAs($this->admin)
        ->get(customerUrl($other, '/members'))
        ->assertForbidden();
});

it('forbids regular members from the members page', function () {
    $regular = User::factory()->create();
    grantRoleOnCustomer($regular, 'User', $this->customer);

    $this->actingAs($regular)
        ->get(customerUrl($this->customer, '/members'))
        ->assertForbidden();
});

it('allows a platform SuperAdmin to access any customer members page', function () {
    $super = makeSuperAdmin(User::factory()->create());
    $other = createCustomer('isolated', 'Isolated');

    $this->actingAs($super)
        ->get(customerUrl($other, '/members'))
        ->assertOk();
});

it('invites an existing user with a role', function () {
    $newUser = User::factory()->create(['email' => 'friend@example.test']);

    $this->actingAs($this->admin)
        ->post(customerUrl($this->customer, '/members'), [
            'email' => 'friend@example.test',
            'roles' => ['Editor'],
        ])
        ->assertRedirect();

    expect($newUser->fresh()->belongsToCustomer($this->customer))->toBeTrue();

    // `rolesOn` saves/restores the PermissionRegistrar team id internally,
    // so we don't leak the customer's team id into whichever test runs next
    // (the singleton persists across cases in the same process).
    expect(CustomerMembership::rolesOn($newUser->fresh(), $this->customer))->toContain('Editor');
});

it('requires a role when inviting', function () {
    User::factory()->create(['email' => 'noRole@example.test']);

    $this->actingAs($this->admin)
        ->post(customerUrl($this->customer, '/members'), [
            'email' => 'noRole@example.test',
        ])
        ->assertSessionHasErrors('roles');
});

it('changes a member role on the customer', function () {
    $member = User::factory()->create();
    grantRoleOnCustomer($member, 'User', $this->customer);

    $this->actingAs($this->admin)
        ->patch(customerUrl($this->customer, "/members/{$member->id}/role"), [
            'roles' => ['Editor'],
        ])
        ->assertRedirect();

    expect(CustomerMembership::roleOn($member->fresh(), $this->customer))->toBe('Editor');
});

it('prevents demoting the last customer-Admin', function () {
    // $this->admin is the only Admin on this customer. The controller redirects
    // back with a flashed error rather than mutating the role.
    $this->actingAs($this->admin)
        ->patch(customerUrl($this->customer, "/members/{$this->admin->id}/role"), [
            'roles' => ['Editor'],
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(CustomerMembership::roleOn($this->admin->fresh(), $this->customer))->toBe('Admin');
});

it('allows demoting when another customer-Admin exists', function () {
    $second = User::factory()->create();
    grantRoleOnCustomer($second, 'Admin', $this->customer);

    $this->actingAs($this->admin)
        ->patch(customerUrl($this->customer, "/members/{$this->admin->id}/role"), [
            'roles' => ['Editor'],
        ])
        ->assertRedirect();

    expect(CustomerMembership::roleOn($this->admin->fresh(), $this->customer))->toBe('Editor');
});

it('removes a member', function () {
    $member = User::factory()->create();
    grantRoleOnCustomer($member, 'User', $this->customer);

    $this->actingAs($this->admin)
        ->delete(customerUrl($this->customer, "/members/{$member->id}"))
        ->assertRedirect();

    expect($member->fresh()->belongsToCustomer($this->customer))->toBeFalse();
    expect(CustomerMembership::roleOn($member->fresh(), $this->customer))->toBeNull();
});

it('prevents removing the last customer-Admin', function () {
    $this->actingAs($this->admin)
        ->delete(customerUrl($this->customer, "/members/{$this->admin->id}"))
        ->assertRedirect()
        ->assertSessionHas('error');

    // Both the membership pivot AND the Admin role assignment must survive —
    // `CustomerMembership::detach` wipes roles, so if the controller ever
    // skipped the early `return` but still called detach, the membership
    // alone could look preserved while the role was silently stripped.
    expect($this->admin->fresh()->belongsToCustomer($this->customer))->toBeTrue();
    expect(CustomerMembership::roleOn($this->admin->fresh(), $this->customer))->toBe('Admin');
});
