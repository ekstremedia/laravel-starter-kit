<?php

declare(strict_types=1);

use App\Domains\Equipment\Models\Equipment;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->workspace = createWorkspace();
    $this->user = makeSuperAdmin(User::factory()->create());
    Equipment::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);
});

it('renders the equipment dashboard widget enabled by default', function () {
    $this->actingAs($this->user)
        ->get(workspaceUrl($this->workspace, '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('widgets', 1)
            ->where('widgets.0.key', 'equipment')
            ->where('widgets.0.enabled', true)
            ->where('widgets.0.data.total', 2));
});

it('hides a widget the user has opted out of (and skips its data)', function () {
    $this->user->settings()->merge(['dashboard_hidden_widgets' => ['equipment']]);

    $this->actingAs($this->user)
        ->get(workspaceUrl($this->workspace, '/dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('widgets.0.enabled', false)
            ->where('widgets.0.data', null));
});

it('persists the hidden-widgets preference via the settings endpoint', function () {
    $this->actingAs($this->user)
        ->patch('/settings', ['dashboard_hidden_widgets' => ['equipment']])
        ->assertOk(); // non-Inertia request → JSON 200 (Inertia requests get back(303))

    expect($this->user->settings()->resolved()['dashboard_hidden_widgets'])->toBe(['equipment']);
});
