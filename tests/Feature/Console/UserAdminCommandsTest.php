<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

beforeEach(fn () => $this->seed(RoleAndPermissionSeeder::class));

it('creates a user with an explicit password and customer-scoped role', function () {
    $customer = createCustomer();

    $this->artisan('user:create', [
        'email' => 'new@example.test',
        '--first-name' => 'Ada',
        '--last-name' => 'Lovelace',
        '--password' => 'secret-password',
        '--role' => 'Editor',
        '--customer' => $customer->slug,
        '--verified' => true,
    ])->assertSuccessful();

    $user = User::where('email', 'new@example.test')->firstOrFail();
    expect(Hash::check('secret-password', $user->password))->toBeTrue();
    expect($user->email_verified_at)->not->toBeNull();

    app(PermissionRegistrar::class)->setPermissionsTeamId($customer->id);
    expect($user->hasRole('Editor'))->toBeTrue();
});

it('refuses to create a user when the email already exists', function () {
    User::factory()->create(['email' => 'dup@example.test']);

    $this->artisan('user:create', ['email' => 'dup@example.test'])->assertFailed();
});

it('grants and revokes customer-scoped roles', function () {
    $customer = createCustomer();
    $user = User::factory()->create();

    $this->artisan('user:grant-role', [
        'email' => $user->email,
        'role' => 'Editor',
        '--customer' => $customer->slug,
    ])->assertSuccessful();

    app(PermissionRegistrar::class)->setPermissionsTeamId($customer->id);
    expect($user->fresh()->hasRole('Editor'))->toBeTrue();

    $this->artisan('user:grant-role', [
        'email' => $user->email,
        'role' => 'Editor',
        '--customer' => $customer->slug,
        '--revoke' => true,
    ])->assertSuccessful();

    app(PermissionRegistrar::class)->setPermissionsTeamId($customer->id);
    expect($user->fresh()->hasRole('Editor'))->toBeFalse();
});

it('resets a password', function () {
    $user = User::factory()->create();

    $this->artisan('user:reset-password', ['email' => $user->email, '--password' => 'fresh-pw-1'])->assertSuccessful();
    expect(Hash::check('fresh-pw-1', $user->fresh()->password))->toBeTrue();
});

it('exports user data as JSON', function () {
    $user = User::factory()->create(['email' => 'exportme@example.test']);

    $out = tempnam(sys_get_temp_dir(), 'user-export-');
    $this->artisan('user:export', ['email' => $user->email, '--out' => $out])->assertSuccessful();

    /** @var array<string,mixed> $decoded */
    $decoded = json_decode((string) file_get_contents($out), true);

    expect($decoded)->toHaveKeys(['profile', 'customer_roles', 'is_super_admin', 'settings', 'generated_at']);
    expect($decoded['profile']['email'])->toBe('exportme@example.test');

    unlink($out);
});

it('anonymizes a user in place', function () {
    $user = User::factory()->create(['email' => 'bye@example.test', 'first_name' => 'Alice']);

    $this->artisan('user:anonymize', ['email' => $user->email, '--force' => true])->assertSuccessful();

    $fresh = $user->fresh();
    expect($fresh->email)->not->toBe('bye@example.test');
    expect($fresh->email)->toStartWith('anonymized+');
    expect($fresh->first_name)->toBe('Redacted');
    expect($fresh->banned_at)->not->toBeNull();
});
