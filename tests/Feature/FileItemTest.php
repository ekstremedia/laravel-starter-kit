<?php

declare(strict_types=1);

use App\Domains\Files\Models\FileItem;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;

it('creates a file item scoped to a tenant and user', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $user->customers()->attach($workspace);

    $folder = FileItem::factory()->folder()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $child = FileItem::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'parent_id' => $folder->id,
    ]);

    expect($folder->isFolder())->toBeTrue()
        ->and($folder->children)->toHaveCount(1)
        ->and($child->parent->is($folder))->toBeTrue()
        ->and($child->workspace->id)->toBe($workspace->id)
        ->and($child->user->id)->toBe($user->id)
        ->and($child->uuid)->toBeString();
});

it('cascade-soft-deletes descendants when a folder is trashed', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    $folder = FileItem::factory()->folder()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    FileItem::factory()->count(3)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'parent_id' => $folder->id,
    ]);

    $folder->delete();

    expect(FileItem::where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and(FileItem::withTrashed()->where('workspace_id', $workspace->id)->count())->toBe(4);
});

it('identifies image mime types', function () {
    $image = FileItem::factory()->make(['mime_type' => 'image/png']);
    $pdf = FileItem::factory()->make(['mime_type' => 'application/pdf']);

    expect($image->isImage())->toBeTrue()
        ->and($pdf->isImage())->toBeFalse();
});
