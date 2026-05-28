<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

it('renders the combined system + health page', function () {
    $this->actingAs($this->admin)
        ->get('/admin/system')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/System')
            ->has('php.version')
            ->has('php.memory_limit')
            ->has('system.hostname')
            ->has('laravel.version')
            ->has('laravel.environment')
            ->has('drivers.broadcast')
            ->has('drivers.queue')
            ->has('drivers.cache')
            ->has('cache_status.config')
            ->has('extensions')
            ->has('health.queue.driver')
            ->has('health.broadcast.driver')
            ->has('health.redis')
        );
});

it('redirects /admin/health to /admin/system for backcompat', function () {
    $this->actingAs($this->admin)
        ->get('/admin/health')
        ->assertRedirect('/admin/system');
});
