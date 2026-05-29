<?php

declare(strict_types=1);

use App\Domains\Files\Models\FileItem;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/**
 * Users in this project get file permissions via their workspace-scoped
 * `User` role. Revoking role-granted permissions directly on a user is a
 * no-op — we strip the role within the workspace's team context and
 * (optionally) grant back a curated subset, then forget the registrar's
 * permission cache so the next HTTP request sees the new state.
 */
function grantOnly(User $user, Workspace $workspace, array $keep): void
{
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($workspace->id);

    $user->syncRoles([]);
    foreach ($keep as $perm) {
        $user->givePermissionTo($perm);
    }
    $registrar->forgetCachedPermissions();
}

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    Storage::fake('public');
    AppSetting::current()->update(['files_feature_enabled' => true]);

    $this->workspace = createWorkspace();
    $this->workspace->update(['files_feature_enabled' => true]);

    $this->user = User::factory()->create();
    joinWorkspace($this->user, $this->workspace);
    $this->user->settings()->merge([
        'files_enabled' => true,
        'storage_quota_override' => 10_000_000,
    ]);

    $this->filesUrl = workspaceUrl($this->workspace, '/files');
});

it('seeded User role has all file permissions', function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);
    expect($this->user->can('upload files'))->toBeTrue();
    expect($this->user->can('create folders'))->toBeTrue();
    expect($this->user->can('rename files'))->toBeTrue();
    expect($this->user->can('delete files'))->toBeTrue();
    expect($this->user->can('share files'))->toBeTrue();
});

it('rejects upload when user lacks the upload permission', function () {
    grantOnly($this->user, $this->workspace, ['create folders', 'rename files', 'delete files', 'share files']);

    $this->actingAs($this->user)
        ->post($this->filesUrl, ['files' => [UploadedFile::fake()->create('a.txt', 10)]])
        ->assertForbidden();
});

it('rejects folder create when user lacks the create-folders permission', function () {
    grantOnly($this->user, $this->workspace, ['upload files', 'rename files', 'delete files', 'share files']);

    $this->actingAs($this->user)
        ->post(workspaceUrl($this->workspace, '/files/folder'), ['name' => 'dir'])
        ->assertForbidden();
});

it('rejects rename when user lacks the rename-files permission', function () {
    $file = FileItem::factory()->folder()->create([
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
    ]);
    grantOnly($this->user, $this->workspace, ['upload files', 'create folders', 'delete files', 'share files']);

    $this->actingAs($this->user)
        ->patch(workspaceUrl($this->workspace, "/files/{$file->id}"), ['name' => 'new'])
        ->assertForbidden();
});

it('rejects delete when user lacks the delete-files permission', function () {
    $file = FileItem::factory()->folder()->create([
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
    ]);
    grantOnly($this->user, $this->workspace, ['upload files', 'create folders', 'rename files', 'share files']);

    $this->actingAs($this->user)
        ->delete(workspaceUrl($this->workspace, "/files/{$file->id}"))
        ->assertForbidden();
});

it('rejects share when user lacks the share-files permission', function () {
    $file = FileItem::factory()->folder()->create([
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
    ]);
    grantOnly($this->user, $this->workspace, ['upload files', 'create folders', 'rename files', 'delete files']);

    $this->actingAs($this->user)
        ->postJson(workspaceUrl($this->workspace, "/files/{$file->id}/shares"), ['expires_in_hours' => 1])
        ->assertForbidden();
});

it('rejects trash force-delete when user lacks the delete-files permission', function () {
    $file = FileItem::factory()->folder()->create([
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
    ]);
    $file->delete();
    grantOnly($this->user, $this->workspace, ['upload files', 'create folders', 'rename files', 'share files']);

    $this->actingAs($this->user)
        ->delete(workspaceUrl($this->workspace, "/files/trash/{$file->id}"))
        ->assertForbidden();
});
