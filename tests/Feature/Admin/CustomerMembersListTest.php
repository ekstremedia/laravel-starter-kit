<?php

declare(strict_types=1);

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('exposes each member with their customer-scoped role(s) and a public_id on the admin edit page', function () {
    $super = makeSuperAdmin(User::factory()->create());
    $customer = createCustomer();

    $admin = User::factory()->create(['email' => 'admin@example.test']);
    $editor = User::factory()->create(['email' => 'editor@example.test']);

    grantRoleOnCustomer($admin, 'Admin', $customer);
    grantRoleOnCustomer($editor, 'Editor', $customer);

    $this->actingAs($super)
        ->get(route('admin.customers.edit', $customer))
        ->assertOk()
        ->assertInertia(function ($page) use ($admin, $editor) {
            $users = collect($page->toArray()['props']['customer']['users']);

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

it('saves customer profile fields from the admin edit page', function () {
    $super = makeSuperAdmin(User::factory()->create());
    $customer = createCustomer();

    $this->actingAs($super)
        ->patch(route('admin.customers.update', $customer), [
            'name' => 'Updated Co',
            'status' => 'suspended',
            // Whitespace-padded — exercises the trim normalization in
            // CustomerController::update().
            'headline' => '  tagline  ',
            'about' => 'about text',
            'location' => 'Oslo',
            'website' => 'https://example.com',
        ])
        ->assertSessionHasNoErrors();

    $fresh = $customer->fresh();
    expect($fresh->name)->toBe('Updated Co');
    expect($fresh->status)->toBe('suspended');
    expect($fresh->headline)->toBe('tagline');
    expect($fresh->about)->toBe('about text');
    expect($fresh->location)->toBe('Oslo');
    expect($fresh->website)->toBe('https://example.com');
});
