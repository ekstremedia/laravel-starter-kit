<?php

declare(strict_types=1);

use App\Domains\Files\Models\FileItem;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    Storage::fake('public');
    AppSetting::current()->update(['files_feature_enabled' => true]);

    $this->customer = createCustomer();
    $this->customer->update(['files_feature_enabled' => true]);

    $this->user = User::factory()->create();
    joinCustomer($this->user, $this->customer);
    $this->user->settings()->merge([
        'files_enabled' => true,
        'storage_quota_override' => 10_000_000,
    ]);

    $this->filesUrl = customerUrl($this->customer, '/files');
});

it('requires authentication', function () {
    $this->get($this->filesUrl)->assertRedirect('/login');
});

it('404s when the tenant feature flag is off', function () {
    $this->customer->update(['files_feature_enabled' => false]);

    $this->actingAs($this->user)->get($this->filesUrl)->assertNotFound();
});

it('403s when the user-level setting is off', function () {
    $this->user->settings()->merge(['files_enabled' => false]);

    $this->actingAs($this->user)->get($this->filesUrl)->assertForbidden();
});

it('renders the files page when feature is enabled', function () {
    $this->actingAs($this->user)
        ->get($this->filesUrl)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Files/Index'));
});

it('creates a folder', function () {
    $this->actingAs($this->user)
        ->post(customerUrl($this->customer, '/files/folder'), ['name' => 'Photos'])
        ->assertRedirect();

    $folder = FileItem::where('user_id', $this->user->id)->first();
    expect($folder)->not->toBeNull()
        ->and($folder->type)->toBe('folder')
        ->and($folder->name)->toBe('Photos');
});

it('auto-renames on duplicate folder names in the same parent', function () {
    $this->actingAs($this->user)
        ->post(customerUrl($this->customer, '/files/folder'), ['name' => 'Inbox'])
        ->assertRedirect();
    $this->actingAs($this->user)
        ->post(customerUrl($this->customer, '/files/folder'), ['name' => 'Inbox'])
        ->assertRedirect();

    expect(FileItem::where('name', 'Inbox')->count())->toBe(1)
        ->and(FileItem::where('name', 'Inbox (2)')->count())->toBe(1);
});

it('uploads a file and links it via medialibrary', function () {
    $this->actingAs($this->user)
        ->post(customerUrl($this->customer, '/files'), [
            'files' => [UploadedFile::fake()->image('cat.png', 200, 200)],
        ])
        ->assertRedirect();

    $item = FileItem::where('user_id', $this->user->id)->first();
    expect($item)->not->toBeNull()
        ->and($item->type)->toBe('file')
        ->and($item->name)->toBe('cat.png')
        ->and($item->getFirstMedia('file'))->not->toBeNull();
});

it('rejects uploads exceeding the user quota via middleware', function () {
    $this->user->settings()->merge(['storage_quota_override' => 1000]);

    // Plain POST falls through to back-redirect + validation errors (web form).
    $this->actingAs($this->user)
        ->post(customerUrl($this->customer, '/files'), [
            'files' => [UploadedFile::fake()->create('big.bin', 5)],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('files');

    expect(FileItem::count())->toBe(0);
});

it('returns JSON validation errors for JSON/XHR quota rejections', function () {
    $this->user->settings()->merge(['storage_quota_override' => 1000]);

    // JSON clients get the ValidationException rendered as 422 JSON. Inertia
    // XHRs follow the same shape (forwarded as a 303 redirect back by the
    // Inertia middleware — the UploadDialog picks them up via `onError`).
    $this->actingAs($this->user)
        ->postJson(customerUrl($this->customer, '/files'), [
            'files' => [UploadedFile::fake()->create('big.bin', 5)],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('files');

    expect(FileItem::count())->toBe(0);
});

it('renames a file', function () {
    $item = FileItem::factory()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'name' => 'old.jpg',
    ]);

    $this->actingAs($this->user)
        ->patch(customerUrl($this->customer, "/files/{$item->id}"), ['name' => 'new.jpg'])
        ->assertRedirect();

    expect($item->fresh()->name)->toBe('new.jpg');
});

it('moves a file into a folder', function () {
    $folder = FileItem::factory()->folder()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);
    $file = FileItem::factory()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->patch(customerUrl($this->customer, "/files/{$file->id}"), ['parent_id' => $folder->id])
        ->assertRedirect();

    expect($file->fresh()->parent_id)->toBe($folder->id);
});

it('refuses setting a folder as its own parent', function () {
    $folder = FileItem::factory()->folder()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->patch(customerUrl($this->customer, "/files/{$folder->id}"), ['parent_id' => $folder->id])
        ->assertStatus(422);

    expect($folder->fresh()->parent_id)->toBeNull();
});

it('refuses moving a folder into its own descendant', function () {
    $parent = FileItem::factory()->folder()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);
    $child = FileItem::factory()->folder()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'parent_id' => $parent->id,
    ]);

    $this->actingAs($this->user)
        ->patch(customerUrl($this->customer, "/files/{$parent->id}"), ['parent_id' => $child->id])
        ->assertStatus(422);

    expect($parent->fresh()->parent_id)->toBeNull();
});

it('denies access to another user\'s file', function () {
    $other = User::factory()->create();
    joinCustomer($other, $this->customer);

    $theirs = FileItem::factory()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $other->id,
    ]);

    $this->actingAs($this->user)
        ->delete(customerUrl($this->customer, "/files/{$theirs->id}"))
        ->assertForbidden();

    expect(FileItem::whereKey($theirs->id)->exists())->toBeTrue();
});

it('deletes a file', function () {
    $item = FileItem::factory()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->delete(customerUrl($this->customer, "/files/{$item->id}"))
        ->assertRedirect();

    expect(FileItem::whereKey($item->id)->exists())->toBeFalse();
});

it('scopes list results to the authenticated user', function () {
    $other = User::factory()->create();
    joinCustomer($other, $this->customer);

    FileItem::factory()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'name' => 'mine.jpg',
    ]);
    FileItem::factory()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $other->id,
        'name' => 'theirs.jpg',
    ]);

    $this->actingAs($this->user)
        ->get($this->filesUrl)
        ->assertInertia(fn ($page) => $page
            ->component('Files/Index')
            ->has('items.data', 1)
            ->where('items.data.0.name', 'mine.jpg'));
});
