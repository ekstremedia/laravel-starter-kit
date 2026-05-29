<?php

declare(strict_types=1);

use App\Domains\Assets\Models\Asset;
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

    $this->workspace = createWorkspace();
    $this->workspace->update(['files_feature_enabled' => true]);

    $this->admin = makeSuperAdmin(User::factory()->create());
});

it('requires authentication', function () {
    $this->get(workspaceUrl($this->workspace, '/assets'))->assertRedirect('/login');
});

it('lists and creates assets', function () {
    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, '/assets'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Assets/Index'));

    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/assets'), [
            'name' => 'Forklift #3',
            'category' => 'Vehicle',
            'serial' => 'FL-00042',
        ])
        ->assertRedirect();

    expect(Asset::where('workspace_id', $this->workspace->id)->where('name', 'Forklift #3')->exists())->toBeTrue();
});

it('shows an asset with its document area', function () {
    $asset = Asset::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->get(workspaceUrl($this->workspace, "/assets/{$asset->id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Assets/Show')
            ->where('asset.name', $asset->name)
            ->where('owner.type', 'asset')
            ->where('owner.id', $asset->id));
});

it('blocks non-members', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(workspaceUrl($this->workspace, '/assets'))
        ->assertForbidden();
});

it('uploads a document owned by the asset', function () {
    $asset = Asset::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/entity-files'), [
            'owner_type' => 'asset',
            'owner_id' => $asset->id,
            'files' => [UploadedFile::fake()->image('photo.png', 100, 100)],
        ])
        ->assertRedirect();

    $item = FileItem::query()->forOwner($asset)->first();
    expect($item)->not->toBeNull()
        ->and($item->owner_type)->toBe('asset')
        ->and($item->owner_id)->toBe($asset->id)
        ->and($item->type)->toBe(FileItem::TYPE_FILE);
});

it('creates, renames and deletes a folder on the asset', function () {
    $asset = Asset::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/entity-files/folder'), [
            'owner_type' => 'asset',
            'owner_id' => $asset->id,
            'name' => 'Manuals',
        ])->assertRedirect();

    $folder = FileItem::query()->forOwner($asset)->where('type', FileItem::TYPE_FOLDER)->firstOrFail();
    expect($folder->name)->toBe('Manuals');

    $this->actingAs($this->admin)
        ->patch(workspaceUrl($this->workspace, "/entity-files/{$folder->id}"), ['name' => 'Documentation'])
        ->assertRedirect();
    expect($folder->fresh()->name)->toBe('Documentation');

    $this->actingAs($this->admin)
        ->delete(workspaceUrl($this->workspace, "/entity-files/{$folder->id}"))
        ->assertRedirect();
    expect(FileItem::find($folder->id))->toBeNull();
});

it('enforces the asset storage quota on upload', function () {
    $asset = Asset::factory()->create([
        'workspace_id' => $this->workspace->id,
        'file_quota_bytes' => 10, // 10 bytes — anything real exceeds it.
    ]);

    $this->actingAs($this->admin)
        ->postJson(workspaceUrl($this->workspace, '/entity-files'), [
            'owner_type' => 'asset',
            'owner_id' => $asset->id,
            'files' => [UploadedFile::fake()->create('big.bin', 50)], // 50 KB
        ])
        ->assertStatus(422);

    expect(FileItem::query()->forOwner($asset)->exists())->toBeFalse();
});

it('updates and deletes an asset', function () {
    $asset = Asset::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->admin)
        ->put(workspaceUrl($this->workspace, "/assets/{$asset->id}"), [
            'name' => 'Renamed',
            'file_quota_bytes' => -1,
        ])->assertRedirect();
    expect($asset->fresh()->name)->toBe('Renamed')
        ->and($asset->fresh()->file_quota_bytes)->toBe(-1);

    $this->actingAs($this->admin)
        ->delete(workspaceUrl($this->workspace, "/assets/{$asset->id}"))
        ->assertRedirect();
    expect(Asset::find($asset->id))->toBeNull();
});
