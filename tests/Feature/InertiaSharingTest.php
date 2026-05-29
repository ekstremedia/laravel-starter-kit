<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    Storage::fake('public');
    $this->workspace = createWorkspace();
    $this->dashboardUrl = workspaceUrl($this->workspace, '/dashboard');
});

it('shares avatar urls as null when no photo uploaded', function () {
    $user = User::factory()->create();
    joinWorkspace($user, $this->workspace);

    $this->actingAs($user)
        ->get($this->dashboardUrl)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.avatar_url', null)
            ->where('auth.user.avatar_thumb_url', null)
        );
});

it('shares avatar urls after upload', function () {
    $user = User::factory()->create();
    joinWorkspace($user, $this->workspace);

    $user->addMedia(UploadedFile::fake()->image('a.png', 400, 400))
        ->toMediaCollection('avatar');

    $this->actingAs($user)
        ->get($this->dashboardUrl)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.avatar_url', fn ($v) => is_string($v) && str_contains($v, 'avatar'))
            ->where('auth.user.avatar_thumb_url', fn ($v) => is_string($v))
        );
});

it('shares workspace-scoped roles and permissions for authenticated users', function () {
    $user = User::factory()->create();
    grantRoleOnWorkspace($user, 'Editor', $this->workspace);

    $this->actingAs($user)
        ->get($this->dashboardUrl)
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.roles', ['Editor'])
            ->has('auth.user.permissions')
            ->where('auth.user.is_super_admin', false)
        );
});

it('shares null auth.user for guests', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user', null));
});

it('shares user settings for authenticated users', function () {
    $user = User::factory()->create();
    joinWorkspace($user, $this->workspace);

    $this->actingAs($user)
        ->get($this->dashboardUrl)
        ->assertInertia(fn ($page) => $page
            ->has('user_settings.locale')
            ->has('user_settings.dark_mode')
        );
});

it('shares the active workspace while inside /w/<slug>', function () {
    $user = User::factory()->create();
    joinWorkspace($user, $this->workspace);

    $this->actingAs($user)
        ->get($this->dashboardUrl)
        ->assertInertia(fn ($page) => $page
            ->where('workspace.slug', $this->workspace->slug)
            ->where('workspace.name', $this->workspace->name)
        );
});

it('shares null workspace on central routes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('workspace', null));
});

it('resolves current_workspace to the user membership on a central route', function () {
    $user = User::factory()->create();
    joinWorkspace($user, $this->workspace, 'User');

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page
            ->where('current_workspace.slug', $this->workspace->slug)
            ->where('current_workspace.is_admin', false)
            // The 'User' role carries 'view company files'.
            ->where('current_workspace.can_view_company_files', true)
        );
});

it('marks current_workspace.is_admin true for a workspace admin on a central route', function () {
    $user = User::factory()->create();
    grantRoleOnWorkspace($user, 'Admin', $this->workspace);

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page
            ->where('current_workspace.slug', $this->workspace->slug)
            ->where('current_workspace.is_admin', true)
            ->where('current_workspace.can_view_company_files', true)
        );
});

it('shares the active workspace as current_workspace inside /w/<slug>', function () {
    $user = User::factory()->create();
    joinWorkspace($user, $this->workspace, 'User');

    $this->actingAs($user)
        ->get($this->dashboardUrl)
        ->assertInertia(fn ($page) => $page
            ->where('current_workspace.slug', $this->workspace->slug)
            ->where('current_workspace.is_admin', false)
        );
});

it('prefers the last-visited workspace when the user has several', function () {
    $other = createWorkspace('globex', 'Globex');
    $user = User::factory()->create();
    joinWorkspace($user, $this->workspace, 'User');
    joinWorkspace($user, $other, 'User');
    $user->settings()->merge(['last_workspace_slug' => $other->slug]);

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('current_workspace.slug', $other->slug));
});

it('shares null current_workspace for a user with no membership', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('current_workspace', null));
});
