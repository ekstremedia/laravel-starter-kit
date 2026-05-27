<?php

declare(strict_types=1);

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->customer = createCustomer();
});

it('lets a member view the customer about page', function () {
    $member = User::factory()->create();
    grantRoleOnCustomer($member, 'User', $this->customer);

    $this->actingAs($member)
        ->get(customerUrl($this->customer, '/about'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customer/About/Show')
            ->where('profile.slug', $this->customer->slug)
            ->where('can_edit', false)
            ->has('members')
        );
});

it('forbids non-members from the about page', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(customerUrl($this->customer, '/about'))
        ->assertForbidden();
});

it('exposes can_edit=true for customer Admins', function () {
    $admin = User::factory()->create();
    grantRoleOnCustomer($admin, 'Admin', $this->customer);

    $this->actingAs($admin)
        ->get(customerUrl($this->customer, '/about'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_edit', true));
});

it('forbids non-Admin members from the edit page', function () {
    $editor = User::factory()->create();
    grantRoleOnCustomer($editor, 'Editor', $this->customer);

    $this->actingAs($editor)
        ->get(customerUrl($this->customer, '/about/edit'))
        ->assertForbidden();
});

it('lets a customer Admin update the customer profile', function () {
    $admin = User::factory()->create();
    grantRoleOnCustomer($admin, 'Admin', $this->customer);

    $this->actingAs($admin)
        ->put(customerUrl($this->customer, '/about'), [
            'name' => 'New Name LLC',
            'headline' => '  We make widgets  ',
            'about' => "Founded in 2020.\n\nMaking widgets since.",
            'location' => 'Oslo',
            'website' => 'https://example.com',
        ])
        ->assertRedirect(customerUrl($this->customer, '/about'));

    $fresh = $this->customer->fresh();
    expect($fresh->name)->toBe('New Name LLC');
    expect($fresh->headline)->toBe('We make widgets');
    expect($fresh->about)->toBe("Founded in 2020.\n\nMaking widgets since.");
    expect($fresh->location)->toBe('Oslo');
    expect($fresh->website)->toBe('https://example.com');
});

it('rejects a bad website URL', function () {
    $admin = User::factory()->create();
    grantRoleOnCustomer($admin, 'Admin', $this->customer);

    $this->actingAs($admin)
        ->put(customerUrl($this->customer, '/about'), [
            'name' => $this->customer->name,
            'website' => 'javascript:alert(1)',
        ])
        ->assertSessionHasErrors('website');
});

it('forbids non-Admin members from updating', function () {
    $editor = User::factory()->create();
    grantRoleOnCustomer($editor, 'Editor', $this->customer);

    $this->actingAs($editor)
        ->put(customerUrl($this->customer, '/about'), [
            'name' => 'hacker',
        ])
        ->assertForbidden();
});
