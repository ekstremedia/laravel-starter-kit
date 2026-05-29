<?php

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use Database\Seeders\RoleAndPermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    // Fortify's CreateNewUser attaches new sign-ups to the default workspace
    // with the platform's default role, so that workspace has to exist.
    Workspace::firstOrCreate(
        ['slug' => config('workspaces.default_workspace_slug', 'default')],
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
    $defaultWorkspace = Workspace::where('slug', config('workspaces.default_workspace_slug', 'default'))->first();
    app(PermissionRegistrar::class)->setPermissionsTeamId($defaultWorkspace->id);
    expect($user->hasRole('User'))->toBeTrue();
    expect($user->hasVerifiedEmail())->toBeFalse();
});

it('creates the user their own workspace in create_own mode', function () {
    config()->set('workspaces.registration_mode', 'create_own');

    $this->post('/register', [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect('/email/verify');

    $user = User::where('email', 'ada@example.com')->first();
    expect($user)->not->toBeNull();

    // A brand-new workspace was created and the user is its admin — not the
    // shared default workspace.
    $workspace = Workspace::where('name', "Ada's space")->first();
    expect($workspace)->not->toBeNull();
    expect($workspace->slug)->not->toBe(config('workspaces.default_workspace_slug', 'default'));
    expect($user->workspaces()->whereKey($workspace->id)->exists())->toBeTrue();

    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
    $user->unsetRelation('roles');
    expect($user->hasRole('Admin'))->toBeTrue();

    // They did NOT auto-join the shared default workspace in this mode.
    $default = Workspace::where('slug', config('workspaces.default_workspace_slug', 'default'))->first();
    expect($user->workspaces()->whereKey($default->id)->exists())->toBeFalse();
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
