<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    // Fortify's CreateNewUser attaches new sign-ups to the default customer
    // with the platform's default role, so that customer has to exist.
    Tenant::firstOrCreate(
        ['slug' => config('tenancy.default_customer_slug', 'default')],
        ['name' => 'Default', 'status' => 'active'],
    );
});

it('shows the registration page', function () {
    $this->get('/register')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->component('Auth/Register'));
});

it('registers a new user', function () {
    $response = $this->post('/register', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertRedirect('/email/verify');

    $user = User::where('email', 'john@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->first_name)->toBe('John');
    expect($user->last_name)->toBe('Doe');
    $defaultCustomer = Tenant::where('slug', config('tenancy.default_customer_slug', 'default'))->first();
    app(PermissionRegistrar::class)->setPermissionsTeamId($defaultCustomer->id);
    expect($user->hasRole('User'))->toBeTrue();
    expect($user->hasVerifiedEmail())->toBeFalse();
});

it('requires first name to register', function () {
    $this->post('/register', [
        'first_name' => '',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('first_name');
});

it('requires last name to register', function () {
    $this->post('/register', [
        'first_name' => 'John',
        'last_name' => '',
        'email' => 'john@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('last_name');
});

it('requires a valid email to register', function () {
    $this->post('/register', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'not-an-email',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('email');
});

it('requires a unique email to register', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/register', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'taken@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('email');
});

it('requires password confirmation to match', function () {
    $this->post('/register', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'password' => 'password123',
        'password_confirmation' => 'different',
    ])->assertSessionHasErrors('password');
});

it('redirects authenticated users away from register page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/register')
        ->assertRedirect('/app');
});
