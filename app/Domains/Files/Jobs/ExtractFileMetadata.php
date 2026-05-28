<?php

declare(strict_types=1);

namespace App\Domains\Files\Jobs;

use App\Domains\Files\Events\FileItemUpdated;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Services\FileMetadataExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Extract and persist normalized file metadata (EXIF/GPS/dimensions/codec…)
 * for a FileItem. Dispatched at upload for files that don't already get a
 * preview job (plain browser-displayable images, audio, text) — the preview
 * jobs (GenerateImagePreview/Video/Document) extract metadata inline so we
 * don't double-process.
 *
 * Idempotent: re-running simply re-extracts and overwrites. Broadcasts
 * FileItemUpdated so the Details action lights up once metadata lands.
 */
class ExtractFileMetadata implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public int $fileItemId) {}

    public function handle(FileMetadataExtractor $extractor): void
    {
        $item = FileItem::with('media')->find($this->fileItemId);
        if (! $item || $item->isFolder()) {
            return;
        }

        $media = $item->getFirstMedia('file');
        if (! $media) {
            return;
        }

        $path = $media->getPath();
        if (! is_file($path)) {
            return;
        }

        $metadata = $extractor->extract($path);
        if ($metadata === []) {
            return; // exiftool absent or nothing useful — leave column null
        }

        $item->update(['metadata' => $metadata]);

        if ($fresh = $item->fresh(['media'])) {
            event(new FileItemUpdated($fresh));
        }
    }
}
