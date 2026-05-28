<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

it('renders the overview dashboard with live metrics', function () {
    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Overview')
            ->has('metrics.users.total')
            ->has('metrics.users.trend_30d')
            ->has('metrics.storage.used_bytes')
            ->has('metrics.queue.pending')
            ->has('metrics.queue.failed')
            ->has('metrics.activity.trend_30d')
            ->has('metrics.recent_activity')
            ->has('metrics.generated_at')
        );
});

it('exposes metrics as JSON for live polling', function () {
    $this->actingAs($this->admin)
        ->getJson('/admin/overview/metrics')
        ->assertOk()
        ->assertJsonStructure([
            'generated_at',
            'users' => ['total', 'unverified', 'new_last_7d', 'trend_30d'],
            'storage' => ['used_bytes'],
            'queue' => ['pending', 'failed'],
            'backups' => ['count'],
            'activity' => ['total', 'trend_30d'],
            'recent_activity',
        ]);
});

it('returns one point per day in the 30-day trend series', function () {
    $response = $this->actingAs($this->admin)->getJson('/admin/overview/metrics');

    $series = $response->json('users.trend_30d');

    expect($series)->toBeArray()
        ->and(count($series))->toBeGreaterThanOrEqual(30)
        ->and($series[0])->toHaveKeys(['date', 'count']);
});

it('forbids non-admins from the overview and metrics endpoints', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
    $this->actingAs($user)->get('/admin/overview/metrics')->assertForbidden();
});
