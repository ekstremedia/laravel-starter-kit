<?php

declare(strict_types=1);

namespace App\Domains\Files\Http\Resources;

use App\Domains\Files\Models\FileItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @mixin FileItem
 */
class FileItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = $this->isFolder() ? null : $this->getFirstMedia('file');
        $docPreview = $this->isFolder() ? null : $this->getFirstMedia('doc_preview');
        $videoPoster = $this->isFolder() ? null : $this->getFirstMedia('video_preview');
        $videoWeb = $this->isFolder() ? null : $this->getFirstMedia('video_web');

        $isVideo = $this->isVideo();
        // For videos we consider processing complete once we either have a
        // transcoded web MP4, or the source was already web-compatible
        // (flagged on the original media row by the job).
        $webCompatible = $media ? $media->getCustomProperty('web_compatible', false) : false;
        $videoReady = $isVideo && ($videoWeb !== null || (bool) $webCompatible);
        $videoProcessing = $isVideo && ! $videoReady;

        // Doc preview is "processing" when the file's mime is in the preview
        // allowlist but the doc_preview media row hasn't arrived yet. The
        // queued GenerateDocumentPreview job broadcasts FileItemUpdated when
        // it finishes, which flips this off in the UI.
        $docPreviewMimes = config('files.preview_mime_types', []);
        $docPreviewProcessing = ! $this->isFolder()
            && in_array((string) $this->mime_type, $docPreviewMimes, true)
            && $docPreview === null;

        // Images are never considered "processing" — the original URL is an
        // immediately usable fallback (see thumbnail_url below), and nothing
        // dispatches FileItemUpdated when Spatie's queued `thumb` conversion
        // finishes, so flagging images as processing would leave the shimmer
        // stuck forever.
        $previewProcessing = $videoProcessing || $docPreviewProcessing;

        // `shared_to_company` drives the "shared" badge + unshare action in
        // My Files. Callers MUST eager-load `companyLink` (and `user` if
        // the owner chip is needed) before handing the FileItem to this
        // resource — a missing relation is treated as "not loaded" and the
        // flags default off, rather than issuing per-item lookups that
        // would N+1 across a listing.
        $companyLink = $this->relationLoaded('companyLink')
            ? $this->getRelation('companyLink')
            : null;

        $owner = $this->relationLoaded('user') ? $this->getRelation('user') : null;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $this->type,
            'scope' => $this->scope,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'size' => (int) $this->size,
            'parent_id' => $this->parent_id,
            'is_image' => $this->isImage(),
            'is_video' => $isVideo,
            'video_processing' => $videoProcessing,
            'video_ready' => $videoReady,
            'preview_processing' => $previewProcessing,
            'shared_to_company' => $companyLink !== null,
            'company_link_id' => $companyLink?->id,
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->fullName(),
                'avatar_thumb_url' => $owner->avatarUrl('thumb'),
            ] : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),

            'thumbnail_url' => $media && $media->hasGeneratedConversion('thumb')
                ? $media->getUrl('thumb')
                : ($this->isImage() && $media
                    ? $media->getUrl()
                    : ($videoPoster?->getUrl() ?? $docPreview?->getUrl())),
            'preview_url' => $media && $media->hasGeneratedConversion('medium')
                ? $media->getUrl('medium')
                : ($videoPoster?->getUrl() ?? $docPreview?->getUrl() ?? $media?->getUrl()),
            'original_url' => $media?->getUrl(),
            'video_web_url' => $videoWeb ? $videoWeb->getUrl() : ($webCompatible ? $media->getUrl() : null),
            'video_poster_url' => $videoPoster?->getUrl(),
            'available_sizes' => $media ? $this->availableSizes($media) : null,
            'has_doc_preview' => $docPreview !== null,
        ];
    }

    /**
     * @return array<string, array{url: string, width: int, height: int}>
     */
    private function availableSizes(Media $media): array
    {
        $sizes = [];
        foreach (FileItem::IMAGE_SIZES as $name => $cfg) {
            if ($media->hasGeneratedConversion($name)) {
                $sizes[$name] = [
                    'url' => $media->getUrl($name),
                    'width' => $cfg['width'],
                    'height' => $cfg['height'],
                ];
            }
        }

        return $sizes;
    }
}
