<?php

declare(strict_types=1);

use App\Domains\Files\Console\PurgeTrashedFileItems;
use App\Domains\Files\Models\FileItem;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    AppSetting::current()->update(['files_feature_enabled' => true]);

    $this->customer = createCustomer();
    $this->customer->update(['files_feature_enabled' => true]);

    $this->user = User::factory()->create();
    joinCustomer($this->user, $this->customer);
    $this->user->settings()->merge(['files_enabled' => true, 'storage_quota_override' => -1]);
});

it('soft-deletes a file and lists it in the trash page', function () {
    $item = FileItem::factory()->create([
        'tenant_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'name' => 'doomed.jpg',
    ]);

    $this->actingAs($this->user)
        ->delete(customerUrl($this->customer, "/files/{$item->id}"))
        ->assertRedirect();

    expect(FileItem::withTrashed()->whereKey($item->id)->first()->trashed())->toBeTrue();

    $this->actingAs($this->user)
        ->get(customerUrl($this->customer, '/files/trash'))
        ->assertInertia(fn ($page) => $page
            ->component('Files/Trash')
            ->has('items.data', 1)
            ->where('items.data.0.name', 'doomed.jpg'));
});

it('restores a trashed item', function () {
    $item = FileItem::factory()->create([
        'tenant_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);
    $item->delete();

    $this->actingAs($this->user)
        ->post(customerUrl($this->customer, "/files/trash/{$item->id}/restore"))
        ->assertRedirect();

    expect(FileItem::find($item->id))->not->toBeNull()
        ->and(FileItem::find($item->id)->trashed())->toBeFalse();
});

it('restores to root when the parent is also trashed', function () {
    $parent = FileItem::factory()->folder()->create([
        'tenant_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);
    $child = FileItem::factory()->create([
        'tenant_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'parent_id' => $parent->id,
    ]);
    $child->delete();
    $parent->delete();

    $this->actingAs($this->user)
        ->post(customerUrl($this->customer, "/files/trash/{$child->id}/restore"))
        ->assertRedirect();

    expect(FileItem::find($child->id)->parent_id)->toBeNull();
});

it('force-deletes an item from the trash', function () {
    $item = FileItem::factory()->create([
        'tenant_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);
    $item->delete();

    $this->actingAs($this->user)
        ->delete(customerUrl($this->customer, "/files/trash/{$item->id}"))
        ->assertRedirect();

    expect(FileItem::withTrashed()->whereKey($item->id)->exists())->toBeFalse();
});

it('empties the trash', function () {
    FileItem::factory()->count(3)->create([
        'tenant_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ])->each->delete();

    $this->actingAs($this->user)
        ->delete(customerUrl($this->customer, '/files/trash'))
        ->assertRedirect();

    expect(FileItem::withTrashed()->count())->toBe(0);
});

it('purges items older than 30 days via the scheduled command', function () {
    $recent = FileItem::factory()->create([
        'tenant_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);
    $recent->delete();

    $old = FileItem::factory()->create([
        'tenant_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);
    $old->delete();
    // Backdate deletion.
    FileItem::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(31)]);

    $this->artisan(PurgeTrashedFileItems::class);

    expect(FileItem::withTrashed()->whereKey($old->id)->exists())->toBeFalse()
        ->and(FileItem::withTrashed()->whereKey($recent->id)->exists())->toBeTrue();
});
