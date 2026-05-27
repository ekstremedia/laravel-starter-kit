<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

it('logs role creation with permissions', function () {
    $this->actingAs($this->admin)->post('/admin/roles', [
        'name' => 'Curator',
        'permissions' => ['view dashboard', 'manage profile'],
    ])->assertRedirect();

    $log = Activity::where('log_name', 'role')->where('event', 'created')->latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->description)->toContain('Curator')
        ->and($log->properties['permissions'])->toEqualCanonicalizing(['view dashboard', 'manage profile'])
        ->and($log->causer_id)->toBe($this->admin->id);
});

it('logs role update with added and removed permissions', function () {
    $role = Role::findByName('Editor');

    $this->actingAs($this->admin)->put("/admin/roles/{$role->id}", [
        'name' => 'Editor',
        'permissions' => ['view dashboard'],
    ])->assertRedirect();

    $log = Activity::where('log_name', 'role')->where('event', 'updated')->latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->properties)->toHaveKey('permissions_added')
        ->and($log->properties)->toHaveKey('permissions_removed');
});

it('logs role deletion', function () {
    $role = Role::create(['name' => 'Temp']);

    $this->actingAs($this->admin)->delete("/admin/roles/{$role->id}")->assertRedirect();

    $log = Activity::where('log_name', 'role')->where('event', 'deleted')->latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->description)->toContain('Temp');
});

it('logs permission creation and deletion', function () {
    $this->actingAs($this->admin)->post('/admin/permissions', ['name' => 'frobnicate'])
        ->assertRedirect();

    $created = Activity::where('log_name', 'permission')->where('event', 'created')->latest()->first();
    expect($created)->not->toBeNull();

    $permission = Permission::where('name', 'frobnicate')->first();
    $this->actingAs($this->admin)->delete("/admin/permissions/{$permission->id}")
        ->assertRedirect();

    $deleted = Activity::where('log_name', 'permission')->where('event', 'deleted')->latest()->first();
    expect($deleted)->not->toBeNull()
        ->and($deleted->properties['name'])->toBe('frobnicate');
});

it('logs admin user creation and password changes', function () {
    $this->actingAs($this->admin)->post('/admin/users', [
        'first_name' => 'New',
        'last_name' => 'Guy',
        'email' => 'newguy@example.test',
        'password' => 'Password#1',
        'password_confirmation' => 'Password#1',
    ])->assertRedirect();

    $created = Activity::where('log_name', 'user')->where('event', 'created')->latest()->first();
    expect($created)->not->toBeNull();

    $user = User::where('email', 'newguy@example.test')->first();
    $this->actingAs($this->admin)->put("/admin/users/{$user->id}", [
        'first_name' => 'New',
        'last_name' => 'Guy',
        'email' => 'newguy@example.test',
        'password' => 'DifferentPassword#2',
        'password_confirmation' => 'DifferentPassword#2',
    ])->assertRedirect();

    $updated = Activity::where('log_name', 'user')->where('event', 'admin_updated')->latest()->first();
    expect($updated)->not->toBeNull()
        ->and($updated->properties['password_changed'])->toBeTrue();
});

it('logs mail settings updates', function () {
    $this->actingAs($this->admin)->patch('/admin/mail', [
        'mailer' => 'smtp',
        'host' => 'h',
        'port' => 25,
        'encryption' => null,
        'username' => 'u',
        'password' => 'secret',
        'from_address' => 'a@b.test',
        'from_name' => 'T',
        'enabled' => true,
    ])->assertRedirect();

    $log = Activity::where('log_name', 'mail_settings')->where('event', 'updated')->latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->properties['password_changed'])->toBeTrue();
});

it('logs backup actions', function () {
    // Don't actually run backup:run / backup:clean in CI — they'd touch the
    // real disk and can be slow. Queue::fake swallows the dispatched jobs.
    Queue::fake();

    $this->actingAs($this->admin)->post('/admin/backups/run')->assertRedirect();

    $run = Activity::where('log_name', 'backup')->where('event', 'run')->latest()->first();
    expect($run)->not->toBeNull();

    $this->actingAs($this->admin)->post('/admin/backups/clean')->assertRedirect();

    $clean = Activity::where('log_name', 'backup')->where('event', 'clean')->latest()->first();
    expect($clean)->not->toBeNull();
});
