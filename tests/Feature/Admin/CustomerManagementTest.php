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

    $this->actingAs($user)->get('/admin/customers')->assertForbidden();
});

it('redirects guests from the landlord index to login', function () {
    $this->get('/admin/customers')->assertRedirect('/login');
});

// ---------- CRUD ----------

it('lists customers on the landlord index', function () {
    createCustomer('acme', 'Acme Corp');
    createCustomer('globex', 'Globex');

    $this->actingAs($this->admin)
        ->get('/admin/customers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Customers/Index')
            ->where('customers.total', 2)
        );
});

it('renders the create form', function () {
    $this->actingAs($this->admin)
        ->get('/admin/customers/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Customers/Create'));
});

it('creates a customer with an explicit slug', function () {
    $this->actingAs($this->admin)
        ->post('/admin/customers', [
            'name' => 'Acme Corp',
            'slug' => 'acme',
        ])
        ->assertSessionHasNoErrors();

    $customer = Workspace::query()->where('slug', 'acme')->firstOrFail();

    expect($customer->name)->toBe('Acme Corp')
        ->and($customer->status)->toBe('active');
});

it('auto-generates a slug from the name when none is provided', function () {
    $this->actingAs($this->admin)
        ->post('/admin/customers', ['name' => 'Hello World Inc'])
        ->assertSessionHasNoErrors();

    expect(Workspace::query()->where('slug', 'hello-world-inc')->exists())->toBeTrue();
});

it('rejects slugs that contain uppercase or invalid characters', function () {
    $this->actingAs($this->admin)
        ->post('/admin/customers', ['name' => 'Acme', 'slug' => 'Acme Corp!'])
        ->assertSessionHasErrors('slug');

    expect(Workspace::query()->count())->toBe(0);
});

it('rejects duplicate slugs', function () {
    createCustomer('acme');

    $this->actingAs($this->admin)
        ->post('/admin/customers', ['name' => 'Another Acme', 'slug' => 'acme'])
        ->assertSessionHasErrors('slug');
});

it('rejects a name that cannot produce a valid auto-slug', function () {
    // `Str::slug('★★★')` → '' — the re-validation must block this before the
    // row lands in the DB with an empty slug (which would break /c/<slug> URLs).
    $this->actingAs($this->admin)
        ->post('/admin/customers', ['name' => '★★★'])
        ->assertSessionHasErrors('slug');

    expect(Workspace::query()->count())->toBe(0);
});

it('renders the edit form with the customer payload', function () {
    $customer = createCustomer('acme', 'Acme Corp');

    $this->actingAs($this->admin)
        ->get("/admin/customers/{$customer->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Customers/Edit')
            ->where('customer.slug', 'acme')
            ->where('customer.name', 'Acme Corp')
            ->where('customer.status', 'active')
        );
});

it('updates a customer name and status', function () {
    $customer = createCustomer('acme');

    $this->actingAs($this->admin)
        ->put("/admin/customers/{$customer->id}", [
            'name' => 'Acme (renamed)',
            'status' => 'suspended',
        ])
        ->assertSessionHasNoErrors();

    $customer->refresh();
    expect($customer->name)->toBe('Acme (renamed)')
        ->and($customer->status)->toBe('suspended');
});

it('validates update input', function () {
    $customer = createCustomer('acme');

    $this->actingAs($this->admin)
        ->put("/admin/customers/{$customer->id}", ['name' => '', 'status' => 'bogus'])
        ->assertSessionHasErrors(['name', 'status']);
});

it('deletes a customer', function () {
    $customer = createCustomer('acme');

    $this->actingAs($this->admin)
        ->delete("/admin/customers/{$customer->id}")
        ->assertRedirect('/admin/customers');

    expect(Workspace::query()->where('slug', 'acme')->exists())->toBeFalse();
});

it('cascades workspace_user pivot rows when a customer is deleted', function () {
    $customer = createCustomer('acme');
    $member = User::factory()->create();
    joinCustomer($member, $customer);

    expect($customer->users()->count())->toBe(1);

    $this->actingAs($this->admin)
        ->delete("/admin/customers/{$customer->id}")
        ->assertRedirect();

    // User survives; pivot is gone.
    expect(User::query()->whereKey($member->id)->exists())->toBeTrue()
        ->and(DB::table('workspace_user')->where('user_id', $member->id)->count())->toBe(0);
});

// ---------- Membership ----------

it('attaches an existing user to a customer by email', function () {
    $customer = createCustomer('acme');
    $user = User::factory()->create(['email' => 'new.member@example.test']);

    $this->actingAs($this->admin)
        ->post("/admin/customers/{$customer->id}/members", ['email' => 'new.member@example.test', 'roles' => ['User']])
        ->assertSessionHasNoErrors();

    expect($user->belongsToCustomer($customer))->toBeTrue();
});

it('is idempotent when attaching the same user twice', function () {
    $customer = createCustomer('acme');
    $user = User::factory()->create(['email' => 'repeat@example.test']);

    $this->actingAs($this->admin)
        ->post("/admin/customers/{$customer->id}/members", ['email' => 'repeat@example.test', 'roles' => ['User']])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post("/admin/customers/{$customer->id}/members", ['email' => 'repeat@example.test', 'roles' => ['User']])
        ->assertSessionHasNoErrors();

    expect($customer->users()->whereKey($user->id)->count())->toBe(1);
});

it('rejects attaching an unknown email', function () {
    $customer = createCustomer('acme');

    $this->actingAs($this->admin)
        ->post("/admin/customers/{$customer->id}/members", ['email' => 'ghost@example.test'])
        ->assertSessionHasErrors('email');

    expect($customer->users()->count())->toBe(0);
});

it('detaches a member from a customer', function () {
    $customer = createCustomer('acme');
    $user = User::factory()->create();
    joinCustomer($user, $customer);

    $this->actingAs($this->admin)
        ->delete("/admin/customers/{$customer->id}/members/{$user->id}")
        ->assertSessionHasNoErrors();

    expect($user->belongsToCustomer($customer))->toBeFalse()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
});
