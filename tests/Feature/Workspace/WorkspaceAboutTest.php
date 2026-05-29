<?php

declare(strict_types=1);

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->workspace = createWorkspace();
});

it('lets a member view the workspace about page', function () {
    $member = User::factory()->create();
    grantRoleOnWorkspace($member, 'User', $this->workspace);

    $this->actingAs($member)
        ->get(workspaceUrl($this->workspace, '/about'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Workspace/About/Show')
            ->where('profile.slug', $this->workspace->slug)
            ->where('can_edit', false)
            ->has('members')
        );
});

it('forbids non-members from the about page', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(workspaceUrl($this->workspace, '/about'))
        ->assertForbidden();
});

it('exposes can_edit=true for workspace Admins', function () {
    $admin = User::factory()->create();
    grantRoleOnWorkspace($admin, 'Admin', $this->workspace);

    $this->actingAs($admin)
        ->get(workspaceUrl($this->workspace, '/about'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can_edit', true));
});

it('forbids non-Admin members from the edit page', function () {
    $editor = User::factory()->create();
    grantRoleOnWorkspace($editor, 'Editor', $this->workspace);

    $this->actingAs($editor)
        ->get(workspaceUrl($this->workspace, '/about/edit'))
        ->assertForbidden();
});

it('lets a workspace Admin update the workspace profile', function () {
    $admin = User::factory()->create();
    grantRoleOnWorkspace($admin, 'Admin', $this->workspace);

    $this->actingAs($admin)
        ->put(workspaceUrl($this->workspace, '/about'), [
            'name' => 'New Name LLC',
            'headline' => '  We make widgets  ',
            'about' => "Founded in 2020.\n\nMaking widgets since.",
            'location' => 'Oslo',
            'website' => 'https://example.com',
        ])
        ->assertRedirect(workspaceUrl($this->workspace, '/about'));

    $fresh = $this->workspace->fresh();
    expect($fresh->name)->toBe('New Name LLC');
    expect($fresh->headline)->toBe('We make widgets');
    expect($fresh->about)->toBe("Founded in 2020.\n\nMaking widgets since.");
    expect($fresh->location)->toBe('Oslo');
    expect($fresh->website)->toBe('https://example.com');
});

it('rejects a bad website URL', function () {
    $admin = User::factory()->create();
    grantRoleOnWorkspace($admin, 'Admin', $this->workspace);

    $this->actingAs($admin)
        ->put(workspaceUrl($this->workspace, '/about'), [
            'name' => $this->workspace->name,
            'website' => 'javascript:alert(1)',
        ])
        ->assertSessionHasErrors('website');
});

it('forbids non-Admin members from updating', function () {
    $editor = User::factory()->create();
    grantRoleOnWorkspace($editor, 'Editor', $this->workspace);

    $this->actingAs($editor)
        ->put(workspaceUrl($this->workspace, '/about'), [
            'name' => 'hacker',
        ])
        ->assertForbidden();
});
