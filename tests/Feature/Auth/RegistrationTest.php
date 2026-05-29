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

it('leaves a create_own sign-up with no workspace and sends them to onboarding', function () {
    config()->set('workspaces.enabled', true);
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

    // No workspace is auto-created in create_own mode — creation is deferred to
    // the self-serve onboarding form — and they did NOT auto-join the default.
    expect($user->workspaces()->count())->toBe(0);
    expect(Workspace::where('name', "Ada's space")->exists())->toBeFalse();

    // Once verified, the landing router sends them to the create-workspace form.
    $user->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($user)
        ->get('/app')
        ->assertRedirect(route('workspaces.onboarding.show'));
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
