<?php

declare(strict_types=1);

namespace App\Domains\Files\Http\Resources;

use App\Domains\Files\Models\CompanyFileLink;
use App\Domains\Files\Models\FileItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps FileItemResource with the fields a company listing needs: the owner's
 * name (shown as a chip), a `linked` flag distinguishing personal-linked
 * files from native company items, and the admin's acting-user's ability to
 * manage the row (delete, unshare).
 *
 * @property-read FileItem $resource
 */
class CompanyFileItemResource extends JsonResource
{
    /**
     * Resources are shipped as list items inside an Inertia prop array —
     * the default "data" wrap would turn each entry into `{data: {...}}`
     * and our Vue page expects flat objects. Setting wrap=null here is
     * local to this class; FileItemResource keeps its default wrapping for
     * callers that rely on it.
     */
    public static $wrap = null;

    public function __construct(FileItem $resource, private readonly ?CompanyFileLink $link = null, private readonly bool $canManage = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FileItem $item */
        $item = $this->resource;

        $base = (new FileItemResource($item))->toArray($request);

        $owner = $item->user;
        $linked = $this->link !== null;
        $sharedBy = $linked ? $this->link->sharedBy : null;

        // `user_id` is NOT NULL on file_items so `$owner` is always set at
        // the DB level; the phpdoc reflects that. `sharedBy` on the link
        // is nullable because shared_by_user_id is nullOnDelete.
        return array_merge($base, [
            'scope' => $item->scope,
            'linked' => $linked,
            'link_id' => $this->link?->id,
            'company_parent_id' => $linked ? $this->link->company_parent_id : $item->parent_id,
            'owner' => [
                'id' => $owner->id,
                'name' => $owner->fullName(),
                'avatar_thumb_url' => $owner->avatarUrl('thumb'),
            ],
            'shared_by' => $sharedBy && $sharedBy->id !== $owner->id ? [
                'id' => $sharedBy->id,
                'name' => $sharedBy->fullName(),
            ] : null,
            'shared_at' => $linked ? $this->link->created_at->toIso8601String() : null,
            // Whether the acting user can delete/unshare this row (owner or
            // admin). The Vue layer uses this to decide whether to render the
            // kebab-menu entries.
            'can_manage' => $this->canManage
                || $owner->id === $request->user()?->id,
        ]);
    }
}
