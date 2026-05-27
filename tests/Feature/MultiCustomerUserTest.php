<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('can attach the same user to two separate customers', function () {
    $acme = createCustomer('acme', 'Acme');
    $globex = createCustomer('globex', 'Globex');

    $user = User::factory()->create();
    joinCustomer($user, $acme);
    joinCustomer($user, $globex);

    expect($user->customers()->pluck('slug')->sort()->values()->all())
        ->toBe(['acme', 'globex']);
});

it('shows the picker when a user belongs to more than one customer', function () {
    $acme = createCustomer('acme');
    $globex = createCustomer('globex');

    $user = User::factory()->create();
    joinCustomer($user, $acme);
    joinCustomer($user, $globex);

    $this->actingAs($user)
        ->get('/app')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customers/Picker')
            ->has('customers', 2)
        );
});

it('redirects a user with exactly one customer straight into it', function () {
    $only = createCustomer('only');
    $user = User::factory()->create();
    joinCustomer($user, $only);

    $this->actingAs($user)
        ->get('/app')
        ->assertRedirect('/c/only/dashboard');
});

it('lets a user in multiple customers load the dashboard under each slug', function () {
    $acme = createCustomer('acme');
    $globex = createCustomer('globex');

    $user = User::factory()->create();
    joinCustomer($user, $acme);
    joinCustomer($user, $globex);

    $this->actingAs($user)
        ->get(customerUrl($acme, '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('customer.slug', 'acme')
        );

    $this->actingAs($user)
        ->get(customerUrl($globex, '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('customer.slug', 'globex')
        );
});

it('403s when a member of one customer tries to enter another', function () {
    $acme = createCustomer('acme');
    $globex = createCustomer('globex');

    $user = User::factory()->create();
    joinCustomer($user, $acme);

    $this->actingAs($user)
        ->get(customerUrl($acme, '/dashboard'))
        ->assertOk();

    $this->actingAs($user)
        ->get(customerUrl($globex, '/dashboard'))
        ->assertForbidden();
});

it('removes access after detaching the membership', function () {
    $customer = createCustomer('acme');
    $user = User::factory()->create();
    joinCustomer($user, $customer);

    $this->actingAs($user)
        ->get(customerUrl($customer, '/dashboard'))
        ->assertOk();

    $customer->users()->detach($user->id);

    $this->actingAs($user)
        ->get(customerUrl($customer, '/dashboard'))
        ->assertForbidden();
});

it('lets admins enter any customer without a pivot row', function () {
    $customer = createCustomer('acme');

    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();
    // note: NOT attached via joinCustomer

    expect($admin->belongsToCustomer($customer))->toBeFalse();

    $this->actingAs($admin)
        ->get(customerUrl($customer, '/dashboard'))
        ->assertOk();
});

it('keeps the shared customers prop off the default payload (Inertia::optional)', function () {
    $acme = createCustomer('acme');
    $globex = createCustomer('globex');

    $user = User::factory()->create();
    joinCustomer($user, $acme);
    joinCustomer($user, $globex);

    // Normal page visit → picker receives the list as an *explicit* controller
    // prop ('customers'), but the shared-layer optional prop is not resolved.
    $this->actingAs($user)
        ->get('/app')
        ->assertInertia(fn ($page) => $page
            ->has('customers', 2)
            ->where('customers.0.slug', 'acme')
        );
});
