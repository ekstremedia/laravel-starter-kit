<?php

declare(strict_types=1);

namespace App\Domains\Files\Jobs;

use App\Domains\Files\Models\CompanyFileLink;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Services\StorageUsageService;
use App\Domains\Files\Support\CompanyFilesCache;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Users\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Recursively mirror a personal folder into the company tree on a worker.
 *
 * Folder shares can touch thousands of FileItems for a power user — doing
 * that inline blocks the HTTP request, starves the php-fpm worker, and
 * makes the "share" action feel sluggish. Pushing it to a queue keeps the
 * POST /files/{id}/share-to-company endpoint fast: controller returns
 * immediately, job reconciles the tree, then CompanyFilesChanged fires so
 * every connected member reloads.
 *
 * Idempotency: the controller already bumps the cache/broadcast version
 * synchronously so users see "sharing…" state start immediately; this job
 * bumps again on completion so the UI can settle when the mirror is done.
 */
class ShareFolderToCompany implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $personalFolderId,
        public int $tenantId,
        public int $actingUserId,
        public ?int $companyParentId = null,
    ) {}

    public function handle(StorageUsageService $usage): void
    {
        $folder = FileItem::find($this->personalFolderId);
        $tenant = Tenant::find($this->tenantId);
        $actor = User::find($this->actingUserId);

        if (! $folder || ! $tenant || ! $actor) {
            return;
        }
        if (! $folder->isFolder() || $folder->scope !== FileItem::SCOPE_PERSONAL) {
            return;
        }

        DB::connection((string) config('tenancy.database.central_connection'))
            ->transaction(function () use ($folder, $tenant, $actor): void {
                $this->mirrorFolder($folder, $tenant, $actor, $this->companyParentId);
            });

        $usage->recomputeForTenant($tenant);

        // Settle the UI: bump the version once the whole tree is in place.
        // An earlier sync bump in the controller started the spinner; this
        // one completes it.
        CompanyFilesCache::bump($tenant->id, 'folder_share_complete', $this->companyParentId);
    }

    private function mirrorFolder(FileItem $personalFolder, Tenant $tenant, User $actor, ?int $companyParentId): void
    {
        $companyFolder = FileItem::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'scope' => FileItem::SCOPE_COMPANY,
                'type' => FileItem::TYPE_FOLDER,
                'parent_id' => $companyParentId,
                'name' => $personalFolder->name,
            ],
            [
                'user_id' => $actor->id,
                'owner_type' => $tenant->getMorphClass(),
                'owner_id' => $tenant->id,
                'size' => 0,
            ],
        );

        // Chunk the descendants so a folder with tens of thousands of items
        // doesn't load the full collection into worker memory at once.
        $personalFolder->children()->chunkById(200, function ($children) use ($tenant, $actor, $companyFolder): void {
            foreach ($children as $child) {
                /** @var FileItem $child */
                if ($child->isFolder()) {
                    $this->mirrorFolder($child, $tenant, $actor, $companyFolder->id);
                } else {
                    CompanyFileLink::updateOrCreate(
                        ['tenant_id' => $tenant->id, 'file_item_id' => $child->id],
                        ['company_parent_id' => $companyFolder->id, 'shared_by_user_id' => $actor->id],
                    );
                }
            }
        });
    }
}
