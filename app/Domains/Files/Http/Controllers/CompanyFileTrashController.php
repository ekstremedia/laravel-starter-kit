<?php

declare(strict_types=1);

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Http\Resources\CompanyFileItemResource;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Files\Support\CompanyFilesCache;
use App\Domains\Settings\Models\AppSetting;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Trash for the company-shared file area. Mirrors FileTrashController but
 * scopes to native company items (scope = company). Restore permissions:
 * the item's owner always qualifies; customer admins + super admins can
 * restore or hard-delete anyone's item. Linked personal files don't have a
 * trash here — deleting a link is immediate and the owner still has the
 * file in their own /files/trash if they ever hard-delete the original.
 */
class CompanyFileTrashController extends Controller
{
    public function __construct(private readonly StorageUsageService $usage) {}

    public function index(Request $request): Response
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($tenant, $user);

        $canManage = $this->canManageCompanyFiles($user);

        // Customer admins see every trashed company item in this tenant;
        // non-admins see only their own trashed contributions.
        $query = FileItem::onlyTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('scope', FileItem::SCOPE_COMPANY)
            ->with(['media', 'user'])
            ->orderByDesc('deleted_at');

        if (! $canManage) {
            $query->where('user_id', $user->id);
        }

        // Cap the render-time payload so a tenant with thousands of
        // trashed items doesn't push a huge JSON blob through Inertia or
        // N+1 the eager-loads. Paginating would change the Vue contract
        // (array → paginator shape); a hard limit is the safer in-place
        // safety net. The company-trash UI is a recovery surface — the
        // 100 most recent are overwhelmingly what anyone needs, and the
        // retention job purges the rest inside 30 days anyway.
        $items = $query->limit(100)->get()
            ->map(fn (FileItem $item) => new CompanyFileItemResource($item, null, $canManage))
            ->all();

        return Inertia::render('Files/Company/Trash', [
            'items' => $items,
            'retention_days' => (int) config('files.trash_retention_days', 30),
            'can_manage' => $canManage,
        ]);
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($tenant, $user);

        $item = FileItem::onlyTrashed()->findOrFail($id);
        $this->authorizeCompanyTrash($item, $user, $tenant, forManageOnly: false);

        // Restore to root when the parent is gone / still trashed — saves
        // users from orphan rows and matches the personal trash behavior.
        if ($item->parent_id !== null) {
            $parent = FileItem::withTrashed()->find($item->parent_id);
            if (! $parent || $parent->trashed()) {
                $item->parent_id = null;
            }
        }

        $deletedAt = $item->deleted_at ? Carbon::instance($item->deleted_at) : null;
        $item->restore();

        if ($deletedAt && $item->isFolder()) {
            $this->cascadeRestore($item, $deletedAt);
        }

        $this->usage->recomputeForTenant($tenant);
        CompanyFilesCache::bump($tenant->id, 'trash_restored', $item->parent_id);

        return back()->with('success', __('files.restored'));
    }

    public function forceDelete(Request $request, int $id): RedirectResponse
    {
        $tenant = $this->currentTenant($request);
        $user = $request->user();
        $this->assertFeatureAvailable($tenant, $user);

        $item = FileItem::onlyTrashed()->findOrFail($id);
        // Permanent delete is admin-only — an owner who changes their mind
        // can still empty the trash, but we don't let a single member
        // erase history irreversibly without admin consent.
        $this->authorizeCompanyTrash($item, $user, $tenant, forManageOnly: true);

        $parentIdBeforeDelete = $item->parent_id;

        DB::connection((string) config('tenancy.database.central_connection'))
            ->transaction(function () use ($item, $tenant): void {
                if ($item->isFolder()) {
                    $this->cascadeForceDelete($item);
                }
                $item->forceDelete();
                $this->usage->recomputeForTenant($tenant);
            });

        CompanyFilesCache::bump($tenant->id, 'trash_force_deleted', $parentIdBeforeDelete);

        return back()->with('success', __('files.force_deleted'));
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

    private function currentTenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('customer');
        if ($tenant instanceof Tenant) {
            return $tenant;
        }
        $slug = config('tenancy.default_customer_slug');
        $fallback = $slug ? Tenant::query()->where('slug', $slug)->first() : null;
        if (! $fallback) {
            abort(404);
        }

        return $fallback;
    }

    private function assertFeatureAvailable(Tenant $tenant, User $user): void
    {
        // Company trash follows the same rules as the main company area:
        // master kill switch at the app level, independent per-customer
        // toggle via `company_files_enabled`.
        if (! AppSetting::current()->files_feature_enabled) {
            abort(404);
        }
        if (! $tenant->company_files_enabled) {
            abort(404);
        }
        abort_unless($user->can('view company files'), 403, __('files.permission_denied'));
    }

    private function authorizeCompanyTrash(FileItem $item, User $user, Tenant $tenant, bool $forManageOnly): void
    {
        if ($item->tenant_id !== $tenant->id || $item->scope !== FileItem::SCOPE_COMPANY) {
            throw new AccessDeniedHttpException;
        }

        $isOwner = $item->user_id === $user->id;
        $canManage = $this->canManageCompanyFiles($user);

        if ($forManageOnly && ! $canManage) {
            throw new AccessDeniedHttpException;
        }
        if (! $forManageOnly && ! $isOwner && ! $canManage) {
            throw new AccessDeniedHttpException;
        }
    }

    private function canManageCompanyFiles(User $user): bool
    {
        // $tenant isn't needed — the tenancy middleware has already set the
        // PermissionRegistrar team id to the active customer, so `can()`
        // auto-scopes to that tenant's permission assignments.
        return $user->isSuperAdmin() || (bool) $user->can('manage company files');
    }
}
