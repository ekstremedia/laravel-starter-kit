<?php

declare(strict_types=1);

use App\Domains\Files\Events\CompanyFilesChanged;
use App\Domains\Files\Jobs\ShareFolderToCompany;
use App\Domains\Files\Models\CompanyFileLink;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Models\FileShare;
use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Notifications\Notifications\CompanyFileDeletedByAdminNotification;
use App\Domains\Notifications\Notifications\CompanyFileUnlinkedByAdminNotification;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    Storage::fake('public');
    AppSetting::current()->update(['files_feature_enabled' => true]);

    $this->workspace = createWorkspace();
    $this->workspace->update([
        'files_feature_enabled' => true,
        'company_files_enabled' => true,
    ]);

    $this->user = User::factory()->create();
    joinWorkspace($this->user, $this->workspace);
    $this->user->settings()->merge([
        'files_enabled' => true,
        'storage_quota_override' => -1, // explicit unlimited for the author
    ]);

    $this->otherMember = User::factory()->create();
    joinWorkspace($this->otherMember, $this->workspace);
    $this->otherMember->settings()->merge([
        'files_enabled' => true,
        'storage_quota_override' => -1,
    ]);

    $this->admin = User::factory()->create();
    grantRoleOnWorkspace($this->admin, 'Admin', $this->workspace);
    $this->admin->settings()->merge([
        'files_enabled' => true,
        'storage_quota_override' => -1,
    ]);
});

function seedMedia(FileItem $item, int $size = 1_000, string $collection = 'file'): void
{
    DB::table('media')->insert([
        'model_type' => (new FileItem)->getMorphClass(),
        'model_id' => $item->id,
        'uuid' => (string) Str::uuid(),
        'collection_name' => $collection,
        'name' => 'sample',
        'file_name' => 'sample.bin',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'conversions_disk' => 'public',
        'size' => $size,
        'manipulations' => '[]',
        'custom_properties' => '[]',
        'generated_conversions' => '[]',
        'responsive_images' => '[]',
        'order_column' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('denies access when the tenant has company files disabled', function () {
    $this->workspace->update(['company_files_enabled' => false]);

    $this->actingAs($this->user)
        ->get(workspaceUrl($this->workspace, '/files/company'))
        ->assertNotFound();
});

it('renders the company files page when enabled', function () {
    $this->actingAs($this->user)
        ->get(workspaceUrl($this->workspace, '/files/company'))
        ->assertOk();
});

it('admins can upload a native company file', function () {
    $response = $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/files/company'), [
            'files' => [UploadedFile::fake()->image('photo.jpg')],
        ]);

    $response->assertRedirect();

    $file = FileItem::where('workspace_id', $this->workspace->id)
        ->where('scope', FileItem::SCOPE_COMPANY)
        ->first();

    expect($file)->not->toBeNull();
    expect($file->user_id)->toBe($this->admin->id);
});

it('regular members cannot upload to company files (needs upload-to-company-files permission)', function () {
    // Default User role only has view+share, not upload.
    $this->actingAs($this->user)
        ->post(workspaceUrl($this->workspace, '/files/company'), [
            'files' => [UploadedFile::fake()->image('photo.jpg')],
        ])
        ->assertForbidden();
});

it('lets a member share a personal file into company files without duplicating', function () {
    $personal = FileItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scope' => FileItem::SCOPE_PERSONAL,
        'type' => FileItem::TYPE_FILE,
        'name' => 'notes.pdf',
        'size' => 2_000,
    ]);
    seedMedia($personal, 2_000);

    $this->actingAs($this->user)
        ->post(workspaceUrl($this->workspace, '/files/'.$personal->id.'/share-to-company'))
        ->assertRedirect();

    // Exactly one link, same file_item_id, no duplicate FileItem.
    expect(CompanyFileLink::count())->toBe(1);
    expect(FileItem::count())->toBe(1);

    // Other member sees it in the company listing.
    $response = $this->actingAs($this->otherMember)
        ->get(workspaceUrl($this->workspace, '/files/company'))
        ->assertOk();

    $payload = $response->viewData('page')['props']['items'] ?? null;
    expect($payload)->toBeArray();
    expect($payload)->toHaveCount(1);
    expect($payload[0]['name'] ?? null)->toBe('notes.pdf');
    expect($payload[0]['linked'] ?? null)->toBeTrue();
    expect($payload[0]['owner']['id'] ?? null)->toBe($this->user->id);
});

it('owner can unshare their own linked file', function () {
    $personal = FileItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scope' => FileItem::SCOPE_PERSONAL,
        'type' => FileItem::TYPE_FILE,
    ]);
    seedMedia($personal);

    $this->actingAs($this->user)
        ->post(workspaceUrl($this->workspace, '/files/'.$personal->id.'/share-to-company'));
    expect(CompanyFileLink::count())->toBe(1);

    $this->actingAs($this->user)
        ->delete(workspaceUrl($this->workspace, '/files/'.$personal->id.'/share-to-company'))
        ->assertRedirect();

    expect(CompanyFileLink::count())->toBe(0);
    // Personal file survives.
    expect(FileItem::find($personal->id))->not->toBeNull();
});

it('non-owner non-admin cannot delete a native company file', function () {
    $file = FileItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->admin->id,
        'scope' => FileItem::SCOPE_COMPANY,
        'type' => FileItem::TYPE_FILE,
    ]);

    // otherMember did NOT upload this file and is not admin.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);

    $this->actingAs($this->otherMember)
        ->delete(workspaceUrl($this->workspace, '/files/company/'.$file->id))
        ->assertForbidden();

    expect(FileItem::withTrashed()->find($file->id)?->trashed())->toBeFalse();
});

it('workspace admin can delete any company file and optionally notify owner', function () {
    Notification::fake();

    $file = FileItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scope' => FileItem::SCOPE_COMPANY,
        'type' => FileItem::TYPE_FILE,
    ]);
    seedMedia($file, 3_000);

    $this->actingAs($this->admin)
        ->delete(
            workspaceUrl($this->workspace, '/files/company/'.$file->id),
            ['notify_in_app' => true, 'notify_email' => true],
        )
        ->assertRedirect();

    expect(FileItem::withTrashed()->find($file->id)?->trashed())->toBeTrue();
    Notification::assertSentTo($this->user, CompanyFileDeletedByAdminNotification::class);
});

it('admin unlinking a shared file keeps the owner\'s personal copy intact', function () {
    Notification::fake();

    $personal = FileItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scope' => FileItem::SCOPE_PERSONAL,
        'type' => FileItem::TYPE_FILE,
    ]);
    seedMedia($personal);

    $this->actingAs($this->user)
        ->post(workspaceUrl($this->workspace, '/files/'.$personal->id.'/share-to-company'));

    $link = CompanyFileLink::sole();

    $this->actingAs($this->admin)
        ->delete(
            workspaceUrl($this->workspace, '/files/company/links/'.$link->id),
            ['notify_in_app' => true, 'notify_email' => false],
        )
        ->assertRedirect();

    expect(CompanyFileLink::count())->toBe(0);
    // Personal file row still present.
    expect(FileItem::find($personal->id))->not->toBeNull();
    Notification::assertSentTo($this->user, CompanyFileUnlinkedByAdminNotification::class);
});

it('broadcasts CompanyFilesChanged on every mutation', function () {
    Event::fake([CompanyFilesChanged::class]);

    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/files/company/folder'), ['name' => 'Reports']);

    Event::assertDispatched(CompanyFilesChanged::class, fn ($e) => $e->workspaceId === $this->workspace->id);
});

it('queues ShareFolderToCompany when a personal folder is shared', function () {
    Bus::fake();

    $folder = FileItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scope' => FileItem::SCOPE_PERSONAL,
        'type' => FileItem::TYPE_FOLDER,
        'name' => 'Q4',
    ]);

    $this->actingAs($this->user)
        ->post(workspaceUrl($this->workspace, '/files/'.$folder->id.'/share-to-company'))
        ->assertRedirect();

    Bus::assertDispatched(ShareFolderToCompany::class,
        fn ($job) => $job->personalFolderId === $folder->id && $job->workspaceId === $this->workspace->id);
});

it('recursively mirrors a shared folder into the company tree when the job runs', function () {
    $folder = FileItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scope' => FileItem::SCOPE_PERSONAL,
        'type' => FileItem::TYPE_FOLDER,
        'name' => 'Q4',
    ]);
    $child = FileItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scope' => FileItem::SCOPE_PERSONAL,
        'type' => FileItem::TYPE_FILE,
        'parent_id' => $folder->id,
        'name' => 'report.pdf',
    ]);
    seedMedia($child, 500);

    // Running the job directly mirrors the tree (queue.sync in tests).
    (new ShareFolderToCompany($folder->id, $this->workspace->id, $this->user->id, null))
        ->handle(app(StorageUsageService::class));

    // A native company folder named "Q4" exists at company root.
    $mirroredFolder = FileItem::where('workspace_id', $this->workspace->id)
        ->where('scope', FileItem::SCOPE_COMPANY)
        ->where('type', FileItem::TYPE_FOLDER)
        ->where('name', 'Q4')
        ->first();
    expect($mirroredFolder)->not->toBeNull();

    // And the child file is linked into it.
    expect(CompanyFileLink::where('file_item_id', $child->id)->first()?->company_parent_id)
        ->toBe($mirroredFolder->id);
});

it('shows admin/owner trashed items and restores them', function () {
    $file = FileItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scope' => FileItem::SCOPE_COMPANY,
        'type' => FileItem::TYPE_FILE,
    ]);
    // Put it in trash (owner deleting their own contribution).
    $this->actingAs($this->user)
        ->delete(workspaceUrl($this->workspace, '/files/company/'.$file->id))
        ->assertRedirect();
    expect($file->fresh()->trashed())->toBeTrue();

    // Owner can restore it.
    $this->actingAs($this->user)
        ->post(workspaceUrl($this->workspace, '/files/company/trash/'.$file->id.'/restore'))
        ->assertRedirect();
    expect(FileItem::find($file->id))->not->toBeNull();
    expect(FileItem::find($file->id)->trashed())->toBeFalse();
});

it('owner cannot force-delete from company trash — admin only', function () {
    $file = FileItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scope' => FileItem::SCOPE_COMPANY,
        'type' => FileItem::TYPE_FILE,
    ]);
    $this->actingAs($this->user)
        ->delete(workspaceUrl($this->workspace, '/files/company/'.$file->id));

    $this->actingAs($this->user)
        ->delete(workspaceUrl($this->workspace, '/files/company/trash/'.$file->id))
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->delete(workspaceUrl($this->workspace, '/files/company/trash/'.$file->id))
        ->assertRedirect();
    expect(FileItem::withTrashed()->find($file->id))->toBeNull();
});

it('admin can create a public share link for a native company file', function () {
    $file = FileItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->admin->id,
        'scope' => FileItem::SCOPE_COMPANY,
        'type' => FileItem::TYPE_FILE,
    ]);

    $this->actingAs($this->admin)
        ->postJson(workspaceUrl($this->workspace, '/files/'.$file->id.'/shares'), [
            'expires_in_hours' => 24,
        ])
        ->assertOk();

    expect(FileShare::where('file_item_id', $file->id)->count())->toBe(1);
});

it('non-owner non-admin cannot share a native company file publicly', function () {
    $file = FileItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->admin->id,
        'scope' => FileItem::SCOPE_COMPANY,
        'type' => FileItem::TYPE_FILE,
    ]);

    $this->actingAs($this->otherMember)
        ->postJson(workspaceUrl($this->workspace, '/files/'.$file->id.'/shares'), [
            'expires_in_hours' => 24,
        ])
        ->assertForbidden();

    expect(FileShare::count())->toBe(0);
});

it('tenant storage quota blocks a company upload once full', function () {
    $this->workspace->update(['storage_quota_bytes' => 100]); // 100 B cap

    // Pre-fill the bucket by creating a native company file with seeded media.
    $pre = FileItem::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->admin->id,
        'scope' => FileItem::SCOPE_COMPANY,
        'type' => FileItem::TYPE_FILE,
    ]);
    seedMedia($pre, 90);

    // Now try to upload a ~1 KB file — fake()->create sizes are KB — which
    // is more than the remaining 10 B under the 100 B tenant quota, so the
    // middleware should reject it.
    $this->actingAs($this->admin)
        ->post(workspaceUrl($this->workspace, '/files/company'), [
            'files' => [UploadedFile::fake()->create('too-big.bin', 1)],
        ])
        ->assertRedirect();

    // 100 B quota only holds the seeded 90 B — nothing new should have landed.
    expect(FileItem::where('scope', FileItem::SCOPE_COMPANY)->count())->toBe(1);
});
