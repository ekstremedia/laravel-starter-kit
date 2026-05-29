<?php

declare(strict_types=1);

use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Models\FileShare;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    AppSetting::current()->update(['files_feature_enabled' => true, 'max_share_days' => 7]);

    $this->customer = createCustomer();
    $this->customer->update(['files_feature_enabled' => true]);

    $this->user = User::factory()->create();
    joinCustomer($this->user, $this->customer);
    $this->user->settings()->merge(['files_enabled' => true]);

    $this->file = FileItem::factory()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'type' => 'file',
        'name' => 'doc.txt',
        'mime_type' => 'text/plain',
    ]);
});

it('creates a share link with expiry', function () {
    $this->actingAs($this->user)
        ->postJson(customerUrl($this->customer, "/files/{$this->file->id}/shares"), [
            'expires_in_hours' => 24,
        ])
        ->assertOk()
        ->assertJsonStructure(['share' => ['token', 'expires_at'], 'url']);

    expect(FileShare::count())->toBe(1);
});

it('caps share duration at admin-configured max', function () {
    AppSetting::current()->update(['max_share_days' => 2]);

    $this->actingAs($this->user)
        ->postJson(customerUrl($this->customer, "/files/{$this->file->id}/shares"), [
            'expires_in_hours' => 100,
        ])
        ->assertUnprocessable();
});

it('protects password-gated shares and unlocks with correct password', function () {
    $share = FileShare::create([
        'token' => 'test-token-123456',
        'file_item_id' => $this->file->id,
        'created_by' => $this->user->id,
        'expires_at' => now()->addHours(24),
        'password_hash' => Hash::make('hunter2'),
    ]);

    $this->get('/share/'.$share->token)
        ->assertInertia(fn ($page) => $page->component('Share/Password'));

    $this->post('/share/'.$share->token.'/unlock', ['password' => 'wrong'])
        ->assertSessionHasErrors('password');

    $this->post('/share/'.$share->token.'/unlock', ['password' => 'hunter2'])
        ->assertRedirect('/share/'.$share->token);
});

it('returns 410 Gone for expired shares', function () {
    $share = FileShare::create([
        'token' => 'expired-token-abc',
        'file_item_id' => $this->file->id,
        'created_by' => $this->user->id,
        'expires_at' => now()->subHour(),
    ]);

    $this->get('/share/'.$share->token)->assertStatus(410);
});

it('refuses access to files outside the shared folder', function () {
    $folder = FileItem::factory()->folder()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
    ]);
    $outsider = FileItem::factory()->create([
        'workspace_id' => $this->customer->id,
        'user_id' => $this->user->id,
        'name' => 'outsider.jpg',
    ]);
    $share = FileShare::create([
        'token' => 'folder-share-token',
        'file_item_id' => $folder->id,
        'created_by' => $this->user->id,
        'expires_at' => now()->addHours(24),
    ]);

    $this->get("/share/{$share->token}/files/{$outsider->id}/download")->assertForbidden();
});

it('owners can revoke a share', function () {
    $share = FileShare::create([
        'token' => 'revokable-token',
        'file_item_id' => $this->file->id,
        'created_by' => $this->user->id,
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($this->user)
        ->delete(customerUrl($this->customer, "/files/shares/{$share->id}"))
        ->assertRedirect();

    expect(FileShare::whereKey($share->id)->exists())->toBeFalse();
});
