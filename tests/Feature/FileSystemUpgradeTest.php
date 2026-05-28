<?php

declare(strict_types=1);

use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Services\FileMetadataExtractor;
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
        'storage_quota_override' => 50_000_000,
    ]);
});

// ── A: max upload setting ───────────────────────────────────────────

it('rejects an upload larger than the configured max_upload_bytes', function () {
    AppSetting::current()->update(['max_upload_bytes' => 1024]); // 1 KB cap

    $this->actingAs($this->user)
        ->post(customerUrl($this->customer, '/files'), [
            'files' => [UploadedFile::fake()->create('big.bin', 5)], // 5 KB
        ])
        ->assertSessionHasErrors('files.0');

    expect(FileItem::where('user_id', $this->user->id)->count())->toBe(0);
});

it('accepts an upload within the configured max_upload_bytes', function () {
    AppSetting::current()->update(['max_upload_bytes' => 10 * 1024 * 1024]);

    $this->actingAs($this->user)
        ->post(customerUrl($this->customer, '/files'), [
            'files' => [UploadedFile::fake()->create('small.bin', 4)],
        ])
        ->assertRedirect();

    expect(FileItem::where('user_id', $this->user->id)->count())->toBe(1);
});

it('exposes the PHP upload ceiling on the admin settings page', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin)
        ->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AppSettings')
            ->where('php_upload_ceiling_bytes', fn ($v) => is_int($v) && $v > 0)
            ->has('settings.max_upload_bytes'));
});

// ── D: metadata + details endpoint ──────────────────────────────────

it('returns details with on-demand metadata extraction for legacy files', function () {
    // Bind a fake extractor so the test doesn't depend on exiftool being present.
    $this->mock(FileMetadataExtractor::class, function ($mock) {
        $mock->shouldReceive('extract')->andReturn([
            'dimensions' => ['width' => 100, 'height' => 80],
            'gps' => ['lat' => 60.39, 'lng' => 5.32],
        ]);
    });

    $file = uploadFile($this->user, $this->customer, UploadedFile::fake()->image('p.jpg', 100, 80));
    $file->update(['metadata' => null]); // simulate a pre-feature row

    $res = $this->actingAs($this->user)
        ->getJson(customerUrl($this->customer, "/files/{$file->id}/details"))
        ->assertOk()
        ->assertJsonPath('metadata.gps.lat', 60.39)
        ->assertJsonPath('metadata.dimensions.width', 100);

    // Persisted for next time.
    expect($file->fresh()->metadata)->not->toBeNull();
});

it('denies details for another user\'s file', function () {
    $other = User::factory()->create();
    joinCustomer($other, $this->customer);
    $file = uploadFile($other, $this->customer, UploadedFile::fake()->image('secret.jpg'));

    $this->actingAs($this->user)
        ->getJson(customerUrl($this->customer, "/files/{$file->id}/details"))
        ->assertForbidden();
});

// ── C: RAW/TIFF detection + resource flags ──────────────────────────

it('flags RAW files as needing an image preview and as images', function () {
    $file = uploadFile($this->user, $this->customer, UploadedFile::fake()->create('shot.arw', 20));

    expect($file->needsImagePreview())->toBeTrue()
        ->and($file->isPreviewableImage())->toBeTrue();
});

it('treats ordinary documents as neither image nor text-previewable images', function () {
    $file = uploadFile($this->user, $this->customer, UploadedFile::fake()->create('a.bin', 5));

    expect($file->needsImagePreview())->toBeFalse();
});

// ── E: text preview ─────────────────────────────────────────────────

it('streams text file contents for inline preview', function () {
    $file = uploadFile($this->user, $this->customer, UploadedFile::fake()->createWithContent('notes.txt', "hello world\nline two"));

    $this->actingAs($this->user)
        ->getJson(customerUrl($this->customer, "/files/{$file->id}/text"))
        ->assertOk()
        ->assertJsonPath('content', "hello world\nline two")
        ->assertJsonPath('is_markdown', false);
});

it('marks markdown files for formatted rendering', function () {
    $file = uploadFile($this->user, $this->customer, UploadedFile::fake()->createWithContent('readme.md', '# Title'));

    $this->actingAs($this->user)
        ->getJson(customerUrl($this->customer, "/files/{$file->id}/text"))
        ->assertOk()
        ->assertJsonPath('is_markdown', true);
});

// ── E: bulk actions ─────────────────────────────────────────────────

it('bulk-deletes selected files', function () {
    $a = uploadFile($this->user, $this->customer, UploadedFile::fake()->image('a.jpg'));
    $b = uploadFile($this->user, $this->customer, UploadedFile::fake()->image('b.jpg'));

    $this->actingAs($this->user)
        ->post(customerUrl($this->customer, '/files/bulk/delete'), ['ids' => [$a->id, $b->id]])
        ->assertRedirect();

    expect(FileItem::whereIn('id', [$a->id, $b->id])->count())->toBe(0)
        ->and(FileItem::onlyTrashed()->whereIn('id', [$a->id, $b->id])->count())->toBe(2);
});

it('bulk-moves selected files into a folder', function () {
    $folder = FileItem::create([
        'tenant_id' => $this->customer->id, 'user_id' => $this->user->id,
        'owner_type' => $this->user->getMorphClass(), 'owner_id' => $this->user->id,
        'type' => 'folder', 'scope' => 'personal', 'name' => 'Dest',
    ]);
    $a = uploadFile($this->user, $this->customer, UploadedFile::fake()->image('a.jpg'));

    $this->actingAs($this->user)
        ->post(customerUrl($this->customer, '/files/bulk/move'), ['ids' => [$a->id], 'parent_id' => $folder->id])
        ->assertRedirect();

    expect($a->fresh()->parent_id)->toBe($folder->id);
});

it('bulk-zips selected files', function () {
    $a = uploadFile($this->user, $this->customer, UploadedFile::fake()->image('a.jpg'));
    $b = uploadFile($this->user, $this->customer, UploadedFile::fake()->image('b.jpg'));

    $res = $this->actingAs($this->user)
        ->get(customerUrl($this->customer, "/files/bulk/zip?ids={$a->id},{$b->id}"));

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('zip');
});

it('denies bulk-zip when a file belongs to another user', function () {
    $other = User::factory()->create();
    joinCustomer($other, $this->customer);
    $mine = uploadFile($this->user, $this->customer, UploadedFile::fake()->image('mine.jpg'));
    $theirs = uploadFile($other, $this->customer, UploadedFile::fake()->image('theirs.jpg'));

    $this->actingAs($this->user)
        ->get(customerUrl($this->customer, "/files/bulk/zip?ids={$mine->id},{$theirs->id}"))
        ->assertForbidden();
});

/**
 * Upload a file as the given user and return the created FileItem.
 */
function uploadFile(User $user, $customer, UploadedFile $file): FileItem
{
    test()->actingAs($user)
        ->post(customerUrl($customer, '/files'), ['files' => [$file]])
        ->assertRedirect();

    return FileItem::where('user_id', $user->id)->where('name', $file->getClientOriginalName())->firstOrFail();
}
