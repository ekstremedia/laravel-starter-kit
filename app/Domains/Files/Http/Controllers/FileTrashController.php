<?php

declare(strict_types=1);

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Contracts\FileOwner;
use App\Domains\Files\Http\Resources\FileItemResource;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Files\Support\OwnerResolver;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FileTrashController extends Controller
{
    public function __construct(private readonly StorageUsageService $usage) {}

    public function index(Request $request): Response
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);

        $owner = $this->resolveOwner($request, $user);
        $this->authorizeOwnerAccess($user, $owner, $workspace, view: true);

        $items = FileItem::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->forOwner($owner)
            // companyLink + user are optional in FileItemResource but
            // must be eager-loaded to avoid N+1 when present. Trashed
            // items rarely carry a live companyLink (the FK cascades),
            // but eager-loading costs nothing and avoids the lazy-load
            // ban in non-production environments.
            ->with(['media', 'companyLink', 'user'])
            ->orderByDesc('deleted_at')
            ->get();

        return Inertia::render('Files/Trash', [
            'items' => FileItemResource::collection($items),
            'retention_days' => (int) config('files.trash_retention_days', 30),
        ]);
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);

        $item = FileItem::onlyTrashed()->findOrFail($id);
        Gate::forUser($user)->authorize('delete', [$item, $workspace]);

        // If the parent is also trashed (or gone), restore to root — don't
        // resurrect a row whose parent_id points at nothing.
        if ($item->parent_id !== null) {
            $parent = FileItem::withTrashed()->find($item->parent_id);
            if (! $parent || $parent->trashed()) {
                $item->parent_id = null;
            }
        }

        $deletedAt = $item->deleted_at ? Carbon::instance($item->deleted_at) : null;
        $item->restore();

        // Cascade-restore descendants that were soft-deleted as part of the
        // same trash action (same deleted_at timestamp, sub-second window).
        if ($deletedAt && $item->isFolder()) {
            $this->cascadeRestore($item, $deletedAt);
        }

        $this->usage->recomputeForOwner($item->owner ?? $user);

        return back()->with('success', __('files.restored'));
    }

    private function cascadeRestore(FileItem $folder, Carbon $deletedAt): void
    {
        $descendants = FileItem::onlyTrashed()
            ->where('parent_id', $folder->id)
            ->whereBetween('deleted_at', [$deletedAt->copy()->subSecond(), $deletedAt->copy()->addSecond()])
            ->get();

        foreach ($descendants as $d) {
            $d->restore();
            if ($d->isFolder()) {
                $this->cascadeRestore($d, $deletedAt);
            }
        }
    }

    public function forceDelete(Request $request, int $id): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);

        $item = FileItem::onlyTrashed()->findOrFail($id);
        Gate::forUser($user)->authorize('delete', [$item, $workspace]);

        $owner = $item->owner;

        // Atomic — either the whole cascade + usage refresh commits, or
        // nothing does. Otherwise a partial delete leaves orphaned children
        // plus a stale denormalized storage_used_bytes.
        DB::connection((string) config('workspaces.database.central_connection'))
            ->transaction(function () use ($item, $owner, $user): void {
                if ($item->isFolder()) {
                    $this->cascadeForceDelete($item);
                }
                $item->forceDelete();
                $this->usage->recomputeForOwner($owner ?? $user);
            });

        return back()->with('success', __('files.force_deleted'));
    }

    public function empty(Request $request): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($request, $workspace);
        abort_unless($user->can('delete files'), 403, __('files.permission_denied'));

        $owner = $this->resolveOwner($request, $user);
        $this->authorizeOwnerAccess($user, $owner, $workspace, view: false);

        DB::connection((string) config('workspaces.database.central_connection'))
            ->transaction(function () use ($workspace, $owner): void {
                // Chunk so huge trashes don't load everything into memory.
                FileItem::onlyTrashed()
                    ->where('workspace_id', $workspace->id)
                    ->forOwner($owner)
                    ->chunkById(100, function ($items): void {
                        foreach ($items as $item) {
                            // A previous iteration may have cascade-removed this
                            // row — skip if it's gone.
                            if (! FileItem::onlyTrashed()->whereKey($item->id)->exists()) {
                                continue;
                            }
                            if ($item->isFolder()) {
                                $this->cascadeForceDelete($item);
                            }
                            $item->forceDelete();
                        }
                    });

                $this->usage->recomputeForOwner($owner);
            });

        return back()->with('success', __('files.trash_emptied'));
    }

    /**
     * Force-delete every trashed descendant of a folder before the folder
     * itself — the DB cascade only fires on hard delete, and we want to
     * reach already-soft-deleted children too.
     */
    private function cascadeForceDelete(FileItem $folder): void
    {
        FileItem::onlyTrashed()
            ->where('parent_id', $folder->id)
            ->chunkById(100, function ($children): void {
                foreach ($children as $child) {
                    if ($child->isFolder()) {
                        $this->cascadeForceDelete($child);
                    }
                    $child->forceDelete();
                }
            });
    }

    private function currentTenant(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');
        if ($workspace instanceof Workspace) {
            return $workspace;
        }
        $slug = config('workspaces.default_workspace_slug');
        $fallback = $slug ? Workspace::query()->where('slug', $slug)->first() : null;
        if (! $fallback) {
            abort(404);
        }

        return $fallback;
    }

    private function assertFeatureAvailable(Request $request, Workspace $workspace): void
    {
        if (! AppSetting::current()->files_feature_enabled) {
            abort(404);
        }

        $user = $request->user();
        $settings = $user->settings()->resolved();

        if (! $workspace->files_feature_enabled) {
            abort(404);
        }

        if (! ($settings['files_enabled'] ?? false)) {
            abort(403, __('files.not_enabled'));
        }
    }

    /**
     * Refuse trash access when the caller can't read/manage the owner's
     * files — otherwise crafting `?owner_type=...&owner_id=...` would let
     * any authed user list or empty another owner's trash.
     */
    private function authorizeOwnerAccess(User $user, Model $owner, Workspace $workspace, bool $view): void
    {
        if (! $owner instanceof FileOwner) {
            // Unknown owner type can't be authorized — refuse rather than
            // silently allow.
            abort(403, __('files.permission_denied'));
        }

        $allowed = $view
            ? $owner->canViewFiles($user, $workspace)
            : $owner->canManageFiles($user, $workspace);

        abort_unless($allowed, 403, __('files.permission_denied'));
    }

    /**
     * Same logic as FileItemController::resolveOwner — defaults to the
     * current user (personal trash) but accepts owner_type/owner_id query
     * params to scope to a different owner (a tenant's company trash, a
     * building's trash in future domains, etc.).
     */
    private function resolveOwner(Request $request, User $user): Model
    {
        return OwnerResolver::fromRequest($request, $user);
    }
}
