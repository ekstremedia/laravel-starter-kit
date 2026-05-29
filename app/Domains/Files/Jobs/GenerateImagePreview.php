<?php

declare(strict_types=1);

namespace App\Domains\Files\Jobs;

use App\Domains\Files\Events\FileItemUpdated;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Services\FileMetadataExtractor;
use App\Domains\Files\Support\CompanyFilesCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Render a browser-displayable JPEG for image types the browser can't show
 * directly — camera RAW (.ARW/.CR2/.NEF/.DNG…), TIFF, HEIC/HEIF — and store it
 * in the `image_preview` media collection (which carries the thumb/medium/
 * large/xlarge conversion ladder). After this runs the file behaves like a
 * normal image in the grid and lightbox; the original RAW is still downloadable.
 *
 * Strategy:
 *   - RAW: extract the embedded full-res JPEG with exiftool (-JpgFromRaw, then
 *     -PreviewImage). Embedded JPEGs are fast and correctly colour-processed by
 *     the camera. Fall back to ImageMagick's RAW delegate if no embed exists.
 *   - TIFF/HEIC: rasterize with Imagick.
 *
 * Idempotent (skips if image_preview already present) and broadcasts
 * FileItemUpdated so the grid swaps the spinner for the real thumbnail.
 */
class GenerateImagePreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    private const MAX_DIMENSION = 4096;

    public function __construct(public int $fileItemId) {}

    public function handle(FileMetadataExtractor $metadata): void
    {
        $item = FileItem::with('media')->find($this->fileItemId);
        if (! $item || ! $item->needsImagePreview()) {
            return;
        }

        $media = $item->getFirstMedia('file');
        if (! $media) {
            return;
        }

        $sourcePath = $media->getPath();
        if (! is_file($sourcePath)) {
            return;
        }

        // Extract metadata first (cheap, and useful even if preview fails).
        $meta = $metadata->extract($sourcePath);
        if ($meta !== []) {
            $item->update(['metadata' => $meta]);
        }

        if ($item->getFirstMedia('image_preview')) {
            return; // idempotent — preview already generated
        }

        $jpeg = sys_get_temp_dir().'/image_preview_'.$item->id.'_'.uniqid('', true).'.jpg';

        try {
            $ok = $this->renderJpeg($item, $sourcePath, $jpeg);
        } catch (\Throwable $e) {
            Log::warning('Image preview generation failed', [
                'file_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            @unlink($jpeg);

            return;
        }

        if (! $ok || ! is_file($jpeg) || filesize($jpeg) === 0) {
            @unlink($jpeg);

            return;
        }

        $item->addMedia($jpeg)
            ->usingName($item->name.' preview')
            ->toMediaCollection('image_preview');

        if ($fresh = $item->fresh(['media'])) {
            event(new FileItemUpdated($fresh));
        }

        // Refresh the version-cached company listing so the shared grid swaps
        // in the generated JPEG (it listens for CompanyFilesChanged, not
        // FileItemUpdated).
        if ($item->scope === FileItem::SCOPE_COMPANY) {
            CompanyFilesCache::bump((int) $item->workspace_id, 'preview_ready', $item->parent_id);
        }
    }

    private function renderJpeg(FileItem $item, string $source, string $out): bool
    {
        $ext = $item->extension();
        $rawExts = (array) config('files.raw_extensions', []);

        if (in_array($ext, $rawExts, true)) {
            // Try the embedded JPEGs first — best quality, camera-processed.
            if ($this->extractEmbeddedJpeg($source, $out, '-JpgFromRaw')) {
                return true;
            }
            if ($this->extractEmbeddedJpeg($source, $out, '-PreviewImage')) {
                return true;
            }

            // No embedded preview — decode the RAW with ImageMagick's delegate.
            return $this->rasterizeWithImagick($source, $out);
        }

        // TIFF / HEIC / HEIF
        return $this->rasterizeWithImagick($source, $out);
    }

    /**
     * Pull an embedded JPEG out of a RAW file via exiftool to $out.
     * Returns false when exiftool is missing or the tag isn't present.
     */
    private function extractEmbeddedJpeg(string $source, string $out, string $tag): bool
    {
        $binary = (string) config('files.exiftool_binary', 'exiftool');

        try {
            $process = new Process([$binary, '-b', $tag, $source]);
            $process->setTimeout(120);
            $process->run();
        } catch (\Throwable $e) {
            Log::debug('exiftool unavailable for RAW preview', ['error' => $e->getMessage()]);

            return false;
        }

        if (! $process->isSuccessful()) {
            return false;
        }

        $data = $process->getOutput();
        if ($data === '' || strlen($data) < 1024) {
            return false; // no/empty embed
        }

        return file_put_contents($out, $data) !== false;
    }

    private function rasterizeWithImagick(string $source, string $out): bool
    {
        if (! extension_loaded('imagick')) {
            Log::warning('Imagick unavailable — cannot rasterize image preview', ['source' => $source]);

            return false;
        }

        $imagick = new \Imagick;
        // RAW/TIFF can be multi-page; read the first frame only.
        $imagick->readImage($source.'[0]');
        $imagick->setImageFormat('jpeg');
        $imagick->setImageBackgroundColor('white');
        $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        // Cap the longest side so we don't store a needlessly huge base image;
        // the conversion ladder downsizes further from here.
        $imagick->thumbnailImage(self::MAX_DIMENSION, self::MAX_DIMENSION, true);
        $imagick->setImageCompressionQuality(90);
        $written = $imagick->writeImage($out);
        $imagick->clear();

        return $written;
    }
}
