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
        $imagePreview = $this->isFolder() ? null : $this->getFirstMedia('image_preview');

        // For RAW/TIFF/HEIC the conversion ladder lives on `image_preview`; for
        // native images it's on `file`. `$previewMedia` is whichever one carries
        // the thumb/medium/large sizes the UI serves.
        $previewMedia = $imagePreview ?? $media;

        $isVideo = $this->isVideo();
        $isPreviewableImage = $this->isPreviewableImage();

        // RAW/TIFF/HEIC are "processing" until their generated JPEG lands.
        $imageProcessing = $this->needsImagePreview() && $imagePreview === null;
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
        // Text/code/markdown render inline (the text endpoint) rather than via
        // the Gotenberg doc-preview pipeline, so they never sit in a
        // "generating preview" state even though text/plain is a preview mime.
        $docPreviewMimes = config('files.preview_mime_types', []);
        $docPreviewProcessing = ! $this->isFolder()
            && ! $this->isTextPreviewable()
            && in_array((string) $this->mime_type, $docPreviewMimes, true)
            && $docPreview === null;

        // Images are never considered "processing" — the original URL is an
        // immediately usable fallback (see thumbnail_url below), and nothing
        // dispatches FileItemUpdated when Spatie's queued `thumb` conversion
        // finishes, so flagging images as processing would leave the shimmer
        // stuck forever.
        $previewProcessing = $videoProcessing || $docPreviewProcessing || $imageProcessing;

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
            'is_image' => $isPreviewableImage,
            'is_audio' => $this->isAudio(),
            'is_video' => $isVideo,
            'is_text' => $this->isTextPreviewable(),
            'is_markdown' => $this->isMarkdown(),
            'video_processing' => $videoProcessing,
            'video_ready' => $videoReady,
            'image_processing' => $imageProcessing,
            'preview_processing' => $previewProcessing,
            'has_metadata' => ! $this->isFolder() && $this->metadata !== null,
            'shared_to_company' => $companyLink !== null,
            'company_link_id' => $companyLink?->id,
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->fullName(),
                'avatar_thumb_url' => $owner->avatarUrl('thumb'),
            ] : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),

            'thumbnail_url' => $previewMedia && $previewMedia->hasGeneratedConversion('thumb')
                ? $previewMedia->getUrl('thumb')
                : ($this->isImage() && $media
                    ? $media->getUrl()
                    : ($videoPoster?->getUrl() ?? $docPreview?->getUrl())),
            'preview_url' => $previewMedia && $previewMedia->hasGeneratedConversion('medium')
                ? $previewMedia->getUrl('medium')
                : ($videoPoster?->getUrl() ?? $docPreview?->getUrl() ?? ($this->isImage() ? $media?->getUrl() : null)),
            // The real original — for RAW this is the undisplayable camera file,
            // served only via the download button (never as a lightbox <img>).
            'original_url' => $media?->getUrl(),
            'video_web_url' => $videoWeb ? $videoWeb->getUrl() : ($webCompatible ? $media->getUrl() : null),
            'video_poster_url' => $videoPoster?->getUrl(),
            // Conversion ladder from whichever media carries it (image_preview
            // for RAW/TIFF, file for native images).
            'available_sizes' => $previewMedia ? $this->availableSizes($previewMedia) : null,
            'has_doc_preview' => $docPreview !== null,
            // A normalized JPEG exists (RAW/TIFF/HEIC original) — the UI offers
            // a "download converted image" option alongside the original.
            'has_converted_image' => $imagePreview !== null,
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
