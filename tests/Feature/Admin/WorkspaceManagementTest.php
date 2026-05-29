<?php

use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

// ---------- Access control ----------

it('forbids non-admins from the landlord index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin/workspaces')->assertForbidden();
});

it('redirects guests from the landlord index to login', function () {
    $this->get('/admin/workspaces')->assertRedirect('/login');
});

// ---------- CRUD ----------

it('lists workspaces on the landlord index', function () {
    createWorkspace('acme', 'Acme Corp');
    createWorkspace('globex', 'Globex');

    $this->actingAs($this->admin)
        ->get('/admin/workspaces')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Workspaces/Index')
            ->where('workspaces.total', 2)
        );
});

it('renders the create form', function () {
    $this->actingAs($this->admin)
        ->get('/admin/workspaces/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Workspaces/Create'));
});

it('creates a workspace with an explicit slug', function () {
    $this->actingAs($this->admin)
        ->post('/admin/workspaces', [
            'name' => 'Acme Corp',
            'slug' => 'acme',
        ])
        ->assertSessionHasNoErrors();

    $workspace = Workspace::query()->where('slug', 'acme')->firstOrFail();

    expect($workspace->name)->toBe('Acme Corp')
        ->and($workspace->status)->toBe('active');
});

it('auto-generates a slug from the name when none is provided', function () {
    $this->actingAs($this->admin)
        ->post('/admin/workspaces', ['name' => 'Hello World Inc'])
        ->assertSessionHasNoErrors();

    expect(Workspace::query()->where('slug', 'hello-world-inc')->exists())->toBeTrue();
});

it('rejects slugs that contain uppercase or invalid characters', function () {
    $this->actingAs($this->admin)
        ->post('/admin/workspaces', ['name' => 'Acme', 'slug' => 'Acme Corp!'])
        ->assertSessionHasErrors('slug');

    expect(Workspace::query()->count())->toBe(0);
});

it('rejects duplicate slugs', function () {
    createWorkspace('acme');

    $this->actingAs($this->admin)
        ->post('/admin/workspaces', ['name' => 'Another Acme', 'slug' => 'acme'])
        ->assertSessionHasErrors('slug');
});

it('rejects a name that cannot produce a valid auto-slug', function () {
    // `Str::slug('★★★')` → '' — the re-validation must block this before the
    // row lands in the DB with an empty slug (which would break /w/<slug> URLs).
    $this->actingAs($this->admin)
        ->post('/admin/workspaces', ['name' => '★★★'])
        ->assertSessionHasErrors('slug');

    expect(Workspace::query()->count())->toBe(0);
});

it('renders the edit form with the workspace payload', function () {
    $workspace = createWorkspace('acme', 'Acme Corp');

    $this->actingAs($this->admin)
        ->get("/admin/workspaces/{$workspace->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Workspaces/Edit')
            ->where('workspace.slug', 'acme')
            ->where('workspace.name', 'Acme Corp')
            ->where('workspace.status', 'active')
        );
});

it('updates a workspace name and status', function () {
    $workspace = createWorkspace('acme');

    $this->actingAs($this->admin)
        ->put("/admin/workspaces/{$workspace->id}", [
            'name' => 'Acme (renamed)',
            'status' => 'suspended',
        ])
        ->assertSessionHasNoErrors();

    $workspace->refresh();
    expect($workspace->name)->toBe('Acme (renamed)')
        ->and($workspace->status)->toBe('suspended');
});

it('validates update input', function () {
    $workspace = createWorkspace('acme');

    $this->actingAs($this->admin)
        ->put("/admin/workspaces/{$workspace->id}", ['name' => '', 'status' => 'bogus'])
        ->assertSessionHasErrors(['name', 'status']);
});

it('deletes a workspace', function () {
    $workspace = createWorkspace('acme');

    $this->actingAs($this->admin)
        ->delete("/admin/workspaces/{$workspace->id}")
        ->assertRedirect('/admin/workspaces');

    expect(Workspace::query()->where('slug', 'acme')->exists())->toBeFalse();
});

it('cascades workspace_user pivot rows when a workspace is deleted', function () {
    $workspace = createWorkspace('acme');
    $member = User::factory()->create();
    joinWorkspace($member, $workspace);

    expect($workspace->users()->count())->toBe(1);

    $this->actingAs($this->admin)
        ->delete("/admin/workspaces/{$workspace->id}")
        ->assertRedirect();

    // User survives; pivot is gone.
    expect(User::query()->whereKey($member->id)->exists())->toBeTrue()
        ->and(DB::table('workspace_user')->where('user_id', $member->id)->count())->toBe(0);
});

// ---------- Membership ----------

it('attaches an existing user to a workspace by email', function () {
    $workspace = createWorkspace('acme');
    $user = User::factory()->create(['email' => 'new.member@example.test']);

    $this->actingAs($this->admin)
        ->post("/admin/workspaces/{$workspace->id}/members", ['email' => 'new.member@example.test', 'roles' => ['User']])
        ->assertSessionHasNoErrors();

    expect($user->belongsToWorkspace($workspace))->toBeTrue();
});

it('is idempotent when attaching the same user twice', function () {
    $workspace = createWorkspace('acme');
    $user = User::factory()->create(['email' => 'repeat@example.test']);

    $this->actingAs($this->admin)
        ->post("/admin/workspaces/{$workspace->id}/members", ['email' => 'repeat@example.test', 'roles' => ['User']])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post("/admin/workspaces/{$workspace->id}/members", ['email' => 'repeat@example.test', 'roles' => ['User']])
        ->assertSessionHasNoErrors();

    expect($workspace->users()->whereKey($user->id)->count())->toBe(1);
});

it('rejects attaching an unknown email', function () {
    $workspace = createWorkspace('acme');

    $this->actingAs($this->admin)
        ->post("/admin/workspaces/{$workspace->id}/members", ['email' => 'ghost@example.test'])
        ->assertSessionHasErrors('email');

    expect($workspace->users()->count())->toBe(0);
});

it('detaches a member from a workspace', function () {
    $workspace = createWorkspace('acme');
    $user = User::factory()->create();
    joinWorkspace($user, $workspace);

    $this->actingAs($this->admin)
        ->delete("/admin/workspaces/{$workspace->id}/members/{$user->id}")
        ->assertSessionHasNoErrors();

    expect($user->belongsToWorkspace($workspace))->toBeFalse()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
});
