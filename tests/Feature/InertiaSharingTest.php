<?php

use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    Storage::fake('public');
    $this->customer = createCustomer();
    $this->dashboardUrl = customerUrl($this->customer, '/dashboard');
});

it('shares avatar urls as null when no photo uploaded', function () {
    $user = User::factory()->create();
    joinCustomer($user, $this->customer);

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
    joinCustomer($user, $this->customer);

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

it('shares customer-scoped roles and permissions for authenticated users', function () {
    $user = User::factory()->create();
    grantRoleOnCustomer($user, 'Editor', $this->customer);

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
    joinCustomer($user, $this->customer);

    $this->actingAs($user)
        ->get($this->dashboardUrl)
        ->assertInertia(fn ($page) => $page
            ->has('user_settings.locale')
            ->has('user_settings.dark_mode')
        );
});

it('shares the active customer while inside /c/<slug>', function () {
    $user = User::factory()->create();
    joinCustomer($user, $this->customer);

    $this->actingAs($user)
        ->get($this->dashboardUrl)
        ->assertInertia(fn ($page) => $page
            ->where('customer.slug', $this->customer->slug)
            ->where('customer.name', $this->customer->name)
        );
});

it('shares null customer on central routes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('customer', null));
});

it('resolves current_customer to the user membership on a central route', function () {
    $user = User::factory()->create();
    joinCustomer($user, $this->customer, 'User');

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page
            ->where('current_customer.slug', $this->customer->slug)
            ->where('current_customer.is_admin', false)
            // The 'User' role carries 'view company files'.
            ->where('current_customer.can_view_company_files', true)
        );
});

it('marks current_customer.is_admin true for a workspace admin on a central route', function () {
    $user = User::factory()->create();
    grantRoleOnCustomer($user, 'Admin', $this->customer);

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page
            ->where('current_customer.slug', $this->customer->slug)
            ->where('current_customer.is_admin', true)
            ->where('current_customer.can_view_company_files', true)
        );
});

it('shares the active customer as current_customer inside /c/<slug>', function () {
    $user = User::factory()->create();
    joinCustomer($user, $this->customer, 'User');

    $this->actingAs($user)
        ->get($this->dashboardUrl)
        ->assertInertia(fn ($page) => $page
            ->where('current_customer.slug', $this->customer->slug)
            ->where('current_customer.is_admin', false)
        );
});

it('prefers the last-visited customer when the user has several', function () {
    $other = createCustomer('globex', 'Globex');
    $user = User::factory()->create();
    joinCustomer($user, $this->customer, 'User');
    joinCustomer($user, $other, 'User');
    $user->settings()->merge(['last_customer_slug' => $other->slug]);

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('current_customer.slug', $other->slug));
});

it('shares null current_customer for a user with no membership', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(fn ($page) => $page->where('current_customer', null));
});
