<?php

declare(strict_types=1);

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('lets a viewer who shares a customer with the profile owner view that profile', function () {
    $customer = createCustomer();
    $viewer = User::factory()->create();
    $owner = User::factory()->create(['headline' => 'Senior engineer', 'bio' => 'Hi!']);

    grantRoleOnCustomer($viewer, 'User', $customer);
    grantRoleOnCustomer($owner, 'User', $customer);

    $this->actingAs($viewer)
        ->get('/u/'.$owner->public_id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('UserProfile')
            ->where('profile.public_id', $owner->public_id)
            ->where('profile.headline', 'Senior engineer')
            ->where('profile.bio', 'Hi!')
            ->where('is_self', false)
            ->has('shared_customers', 1)
        );
});

it('forbids viewing the profile of a user who shares no customer with the viewer', function () {
    $a = createCustomer('a', 'Acme');
    $b = createCustomer('b', 'Beta');

    $viewer = User::factory()->create();
    $owner = User::factory()->create();
    grantRoleOnCustomer($viewer, 'User', $a);
    grantRoleOnCustomer($owner, 'User', $b);

    $this->actingAs($viewer)
        ->get('/u/'.$owner->public_id)
        ->assertForbidden();
});

it('lets a SuperAdmin view any profile regardless of shared customers', function () {
    $super = makeSuperAdmin(User::factory()->create());
    $owner = User::factory()->create();

    $this->actingAs($super)
        ->get('/u/'.$owner->public_id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('UserProfile')->where('is_self', false));
});

it('lets a user view their own profile even with no shared customers', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/u/'.$user->public_id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('UserProfile')
            ->where('is_self', true)
        );
});

it('returns 404 for a non-existent UUID rather than leaking the existence boundary', function () {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get('/u/00000000-0000-4000-8000-000000000000')
        ->assertNotFound();
});

it('updates the new profile fields via the Fortify endpoint', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put('/user/profile-information', [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'headline' => '  Building things  ',
            'bio' => "Line one\n\nLine two",
            'location' => 'Oslo, NO',
            'website' => 'https://example.com',
        ])
        ->assertSessionHasNoErrors();

    $fresh = $user->fresh();
    expect($fresh->headline)->toBe('Building things');
    expect($fresh->bio)->toBe("Line one\n\nLine two");
    expect($fresh->location)->toBe('Oslo, NO');
    expect($fresh->website)->toBe('https://example.com');
});

it('rejects a non-URL website on profile update', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put('/user/profile-information', [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'website' => 'not-a-url',
        ])
        ->assertSessionHasErrors('website');
});

it('coerces empty strings to null on profile update so the column stays clean', function () {
    $user = User::factory()->create([
        'headline' => 'old',
        'bio' => 'old',
        'location' => 'old',
        'website' => 'https://old.example',
    ]);

    $this->actingAs($user)
        ->put('/user/profile-information', [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'headline' => '',
            'bio' => '   ',
            'location' => '',
            'website' => '',
        ])
        ->assertSessionHasNoErrors();

    $fresh = $user->fresh();
    expect($fresh->headline)->toBeNull();
    expect($fresh->bio)->toBeNull();
    expect($fresh->location)->toBeNull();
    expect($fresh->website)->toBeNull();
});
