<?php

declare(strict_types=1);

use App\Domains\Chat\Models\Message;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Notifications\Notifications\ApproachingStorageLimitNotification;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

function seedMediaRow(string $modelType, int $modelId, int $size, string $mime = 'image/jpeg', string $collection = 'file'): void
{
    DB::table('media')->insert([
        // Store the polymorphic morph alias (see the morph map in
        // AppServiceProvider), matching what Eloquent writes and what
        // StorageUsageService queries — not the raw FQCN.
        'model_type' => (new $modelType)->getMorphClass(),
        'model_id' => $modelId,
        'uuid' => (string) Str::uuid(),
        'collection_name' => $collection,
        'name' => 'sample',
        'file_name' => 'sample.bin',
        'mime_type' => $mime,
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

beforeEach(function () {
    $this->service = app(StorageUsageService::class);
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->create();
    $this->user->customers()->attach($this->tenant);
    // The production default now seeds a 5 GB global personal quota,
    // but these tests assume a blank slate (or configure their own
    // override). Reset the app default so "unlimited" really means
    // unlimited here.
    AppSetting::current()->update(['default_personal_storage_bytes' => null]);
});

it('billable total only counts the FileItem `file` collection', function () {
    $item = FileItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);
    // Billable: the user's original upload.
    seedMediaRow(FileItem::class, $item->id, 1_000, 'image/jpeg', 'file');
    // Not billable — generated previews on the same FileItem.
    seedMediaRow(FileItem::class, $item->id, 500_000, 'image/png', 'doc_preview');
    seedMediaRow(FileItem::class, $item->id, 500_000, 'image/jpeg', 'video_preview');
    seedMediaRow(FileItem::class, $item->id, 20_000_000, 'video/mp4', 'video_web');

    expect($this->service->usedBytesForUserInTenant($this->user, $this->tenant))->toBe(1_000);
});

it('billable total excludes chat attachments and user avatars', function () {
    $item = FileItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);
    seedMediaRow(FileItem::class, $item->id, 1_000, 'image/jpeg', 'file');

    // Chat attachment (different model, always ignored).
    $conversation = DB::table('conversations')->insertGetId([
        'is_group' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $message = DB::table('messages')->insertGetId([
        'conversation_id' => $conversation,
        'user_id' => $this->user->id,
        'body' => '',
        'is_encrypted' => false,
        'type' => 'text',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    seedMediaRow(Message::class, $message, 5_000_000, 'image/png', 'attachments');

    // Avatar (User model, always ignored).
    seedMediaRow(User::class, $this->user->id, 500, 'image/webp', 'avatar');

    expect($this->service->usedBytesForUserInTenant($this->user, $this->tenant))->toBe(1_000);
});

it('scopes billable bytes per-tenant — files in one tenant do not count in another', function () {
    $other = Tenant::factory()->create();
    $this->user->customers()->attach($other);

    $hereItem = FileItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);
    $thereItem = FileItem::factory()->create([
        'tenant_id' => $other->id,
        'user_id' => $this->user->id,
    ]);
    seedMediaRow(FileItem::class, $hereItem->id, 1_234, 'image/jpeg', 'file');
    seedMediaRow(FileItem::class, $thereItem->id, 9_999, 'image/jpeg', 'file');

    expect($this->service->usedBytesForUserInTenant($this->user, $this->tenant))->toBe(1_234)
        ->and($this->service->usedBytesForUserInTenant($this->user, $other))->toBe(9_999)
        ->and($this->service->usedBytesForUser($this->user))->toBe(11_233);
});

it('categorizes billable bytes by mime type', function () {
    $item = FileItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);
    seedMediaRow(FileItem::class, $item->id, 1_000, 'image/jpeg', 'file');
    seedMediaRow(FileItem::class, $item->id, 2_000, 'video/mp4', 'file');
    seedMediaRow(FileItem::class, $item->id, 3_000, 'application/pdf', 'file');

    $breakdown = $this->service->breakdownByTypeForUser($this->user);

    expect($breakdown['image'])->toBe(1_000)
        ->and($breakdown['video'])->toBe(2_000)
        ->and($breakdown['pdf'])->toBe(3_000);
});

it('computes per-tenant remaining bytes under a quota', function () {
    $this->user->settings()->merge(['storage_quota_override' => 10_000]);
    $item = FileItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);
    seedMediaRow(FileItem::class, $item->id, 6_000, 'image/jpeg', 'file');

    expect($this->service->remainingBytesInTenant($this->user->fresh(), $this->tenant))->toBe(4_000)
        ->and($this->service->percentUsedInTenant($this->user->fresh(), $this->tenant))->toBe(60.0);
});

it('returns null remaining when unlimited', function () {
    expect($this->service->remainingBytesInTenant($this->user, $this->tenant))->toBeNull();
});

it('fires a notification when crossing the 80% threshold in a tenant', function () {
    Notification::fake();

    $this->user->settings()->merge(['storage_quota_override' => 10_000]);

    $item = FileItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);
    seedMediaRow(FileItem::class, $item->id, 8_100, 'image/jpeg', 'file');

    $this->service->checkAndNotifyThresholds($this->user->fresh(), $this->tenant);

    Notification::assertSentTo($this->user, ApproachingStorageLimitNotification::class,
        fn ($n) => $n->thresholdPercent === 80 && $n->tenantId === $this->tenant->id);
});

it('does not re-fire the same threshold twice in the same tenant', function () {
    Notification::fake();

    $this->user->settings()->merge(['storage_quota_override' => 10_000]);

    $item = FileItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);
    seedMediaRow(FileItem::class, $item->id, 8_100, 'image/jpeg', 'file');

    $this->service->checkAndNotifyThresholds($this->user->fresh(), $this->tenant);
    $this->service->checkAndNotifyThresholds($this->user->fresh(), $this->tenant);

    Notification::assertSentToTimes($this->user, ApproachingStorageLimitNotification::class, 1);
});

it('fires independent threshold alerts per tenant', function () {
    Notification::fake();

    $other = Tenant::factory()->create();
    $this->user->customers()->attach($other);
    $this->user->settings()->merge(['storage_quota_override' => 10_000]);

    $a = FileItem::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);
    $b = FileItem::factory()->create(['tenant_id' => $other->id, 'user_id' => $this->user->id]);
    seedMediaRow(FileItem::class, $a->id, 8_100, 'image/jpeg', 'file');
    seedMediaRow(FileItem::class, $b->id, 8_100, 'image/jpeg', 'file');

    $this->service->checkAndNotifyThresholds($this->user->fresh(), $this->tenant);
    $this->service->checkAndNotifyThresholds($this->user->fresh(), $other);

    // Two distinct notifications, one per tenant.
    Notification::assertSentToTimes($this->user, ApproachingStorageLimitNotification::class, 2);
});

it('recomputes the denormalized storage_used_bytes as billable across all tenants', function () {
    $other = Tenant::factory()->create();
    $this->user->customers()->attach($other);

    $a = FileItem::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);
    $b = FileItem::factory()->create(['tenant_id' => $other->id, 'user_id' => $this->user->id]);
    seedMediaRow(FileItem::class, $a->id, 10_000, 'image/jpeg', 'file');
    seedMediaRow(FileItem::class, $b->id, 2_345, 'image/jpeg', 'file');
    // Previews on one of them — should NOT inflate the billable column.
    seedMediaRow(FileItem::class, $a->id, 999_999, 'image/png', 'doc_preview');

    $bytes = $this->service->recomputeForUser($this->user);

    expect($bytes)->toBe(12_345)
        ->and($this->user->fresh()->storage_used_bytes)->toBe(12_345);
});

it('system totals still include every media row including previews and chat', function () {
    $item = FileItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);
    seedMediaRow(FileItem::class, $item->id, 1_000, 'image/jpeg', 'file');
    seedMediaRow(FileItem::class, $item->id, 2_000, 'image/png', 'doc_preview');
    seedMediaRow(User::class, $this->user->id, 500, 'image/webp', 'avatar');

    expect($this->service->systemTotalBytes())->toBe(3_500);

    $byCollection = $this->service->systemBreakdownByCollection();
    expect($byCollection['billable'])->toBe(1_000)
        ->and($byCollection['doc_preview'])->toBe(2_000)
        ->and($byCollection['avatar'])->toBe(500);
});
