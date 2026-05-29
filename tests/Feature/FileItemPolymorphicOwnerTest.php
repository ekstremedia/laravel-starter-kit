<?php

declare(strict_types=1);

use App\Domains\Files\Events\FileItemUpdated;
use App\Domains\Files\Models\FileItem;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cover the polymorphic-owner contract end-to-end: a User-owned tree, a
 * Workspace-owned tree, query/scoping, and broadcast routing. Targets the
 * regressions most likely as new owner types (Building, Customer) are added.
 */
beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->customer = createCustomer();
    $this->user = User::factory()->create();
    joinCustomer($this->user, $this->customer);
});

it('defaults a factory-created file to user ownership', function () {
    $item = FileItem::factory()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);

    expect($item->owner_type)->toBe((new User)->getMorphClass())
        ->and($item->owner_id)->toBe($this->user->id)
        ->and($item->owner)->toBeInstanceOf(User::class)
        ->and($item->owner->is($this->user))->toBeTrue();
});

it('makes a tenant the owner via ->ownedBy()', function () {
    $item = FileItem::factory()
        ->ownedBy($this->customer)
        ->create([
            'workspace_id' => $this->customer->id,
            'user_id' => $this->user->id,
        ]);

    expect($item->owner_type)->toBe((new Workspace)->getMorphClass())
        ->and($item->owner_id)->toBe($this->customer->id)
        ->and($item->scope)->toBe(FileItem::SCOPE_COMPANY)
        ->and($item->owner->is($this->customer))->toBeTrue();
});

it('scopes queries by owner using forOwner()', function () {
    $other = User::factory()->create();
    joinCustomer($other, $this->customer);

    FileItem::factory()->count(2)->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);
    FileItem::factory()->count(3)->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $other->id,
    ]);

    expect(FileItem::query()->forOwner($this->user)->count())->toBe(2)
        ->and(FileItem::query()->forOwner($other)->count())->toBe(3)
        ->and(FileItem::query()->forOwner($this->customer)->count())->toBe(0);
});

it('routes broadcast channels by owner type', function () {
    $userItem = FileItem::factory()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);

    $companyItem = FileItem::factory()
        ->ownedBy($this->customer)
        ->create([
            'workspace_id' => $this->customer->id,
            'user_id' => $this->user->id,
        ]);

    $userEvent = new FileItemUpdated($userItem->fresh(['owner']));
    $companyEvent = new FileItemUpdated($companyItem->fresh(['owner']));

    expect($userEvent->broadcastOn()[0]->name)
        ->toBe('private-App.Models.User.'.$this->user->id)
        ->and($companyEvent->broadcastOn()[0]->name)
        ->toBe('private-customer.'.$this->customer->id.'.files');
});

it('lets a user manage their own files via the policy', function () {
    // Match the request lifecycle: middleware sets the team scope to the
    // active customer before permission checks resolve.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->customer->id);

    $item = FileItem::factory()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);

    expect(Gate::forUser($this->user)->check('view', [$item, $this->customer]))->toBeTrue()
        ->and(Gate::forUser($this->user)->check('update', [$item, $this->customer]))->toBeTrue();
});

it('blocks a non-owner user from managing another users file', function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->customer->id);

    $stranger = User::factory()->create();
    joinCustomer($stranger, $this->customer);

    $item = FileItem::factory()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);

    expect(Gate::forUser($stranger)->check('view', [$item, $this->customer]))->toBeFalse()
        ->and(Gate::forUser($stranger)->check('delete', [$item, $this->customer]))->toBeFalse();
});

it('lets the manage-all-files permission override owner checks', function () {
    $stranger = User::factory()->create();
    joinCustomer($stranger, $this->customer, 'Admin');

    // Admin role from the seeder already includes `manage all files`.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->customer->id);

    $item = FileItem::factory()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);

    expect(Gate::forUser($stranger)->check('delete', [$item, $this->customer]))->toBeTrue();
});
