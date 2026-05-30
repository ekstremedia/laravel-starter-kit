<?php

declare(strict_types=1);

namespace App\Domains\Files\Jobs;

use App\Domains\Files\Events\FileItemUpdated;
use App\Domains\Files\Models\FileItem;
use App\Domains\Files\Support\CompanyFilesCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Render a first-page preview image for non-image files (PDFs, Office docs).
 *
 * Pipeline: FileItem's original file → Gotenberg (for Office → PDF) → Imagick
 * (PDF → PNG) → stored as a custom media conversion named `doc_preview`.
 *
 * Idempotent: re-running is safe. If the FileItem has no media or Gotenberg
 * is unreachable, the job logs and exits without throwing so the queue
 * doesn't spin on the same failure.
 */
class GenerateDocumentPreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $fileItemId) {}

    public function handle(HttpFactory $http): void
    {
        $item = FileItem::with('media')->find($this->fileItemId);
        if (! $item || $item->isFolder()) {
            return;
        }

        $media = $item->getFirstMedia('file');
        if (! $media) {
            return;
        }

        $mime = (string) $item->mime_type;
        $allowed = config('files.preview_mime_types', []);
        if (! in_array($mime, $allowed, true)) {
            return;
        }

        $original = $media->getPath();
        if (! is_file($original)) {
            return;
        }

        // PDFs skip Gotenberg — we already have the PDF to rasterize.
        $pdfPath = $mime === 'application/pdf'
            ? $original
            : $this->convertToPdfViaGotenberg($http, $original, $item->name);

        if (! $pdfPath) {
            return;
        }

        $pngPath = sys_get_temp_dir().'/fileitem-preview-'.$item->id.'-'.uniqid('', true).'.png';

        try {
            $this->rasterizeFirstPage($pdfPath, $pngPath);
        } catch (\Throwable $e) {
            Log::warning('Document preview rasterize failed.', [
                'file_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            if ($pdfPath !== $original) {
                @unlink($pdfPath);
            }

            return;
        }

        if ($pdfPath !== $original) {
            @unlink($pdfPath);
        }

        if (! is_file($pngPath)) {
            return;
        }

        // Attach as a separate `doc_preview` media collection so it doesn't
        // clobber the original.
        $item->clearMediaCollection('doc_preview');
        $item->addMedia($pngPath)
            ->usingName($item->name.' preview')
            ->toMediaCollection('doc_preview');

        // Broadcast so the file grid can swap in the preview live.
        // fresh() can return null if the item was force-deleted while the
        // job ran — skip rather than throwing a TypeError that makes the
        // queue retry a best-effort preview.
        if ($fresh = $item->fresh(['media'])) {
            event(new FileItemUpdated($fresh));
        }

        // Refresh the version-cached company listing so the shared grid swaps
        // in the doc preview (it listens for CompanyFilesChanged, not
        // FileItemUpdated).
        if ($item->scope === FileItem::SCOPE_COMPANY) {
            CompanyFilesCache::bump((int) $item->workspace_id, 'preview_ready', $item->parent_id);
        }
    }

    private function convertToPdfViaGotenberg(HttpFactory $http, string $path, string $filename): ?string
    {
        $url = rtrim((string) config('files.gotenberg_url'), '/').'/forms/libreoffice/convert';

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            Log::warning('Unable to open file for Gotenberg upload.', ['path' => $path]);

            return null;
        }

        try {
            try {
                $response = $http->timeout(60)
                    ->attach('files', $handle, $filename)
                    ->post($url);
            } catch (\Throwable $e) {
                Log::warning('Gotenberg unreachable.', ['error' => $e->getMessage()]);

                return null;
            }

            if (! $response->ok()) {
                Log::warning('Gotenberg returned non-200.', ['status' => $response->status()]);

                return null;
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $tmp = sys_get_temp_dir().'/gotenberg-'.uniqid('', true).'.pdf';
        file_put_contents($tmp, $response->body());

        return $tmp;
    }

    private function rasterizeFirstPage(string $pdfPath, string $outputPng): void
    {
        // Prefer Imagick; most dev containers already have it installed
        // because Spatie Medialibrary lists it as a supported image driver.
        if (extension_loaded('imagick')) {
            $imagick = new \Imagick;
            $imagick->setResolution(150, 150);
            $imagick->readImage($pdfPath.'[0]');
            $imagick->setImageFormat('png');
            $imagick->setImageBackgroundColor('white');
            $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $imagick->thumbnailImage(1280, 0);
            $imagick->writeImage($outputPng);
            $imagick->clear();

            return;
        }

        // Fallback: shell out to pdftoppm if available.
        $cmd = sprintf(
            'pdftoppm -png -f 1 -l 1 -r 150 %s %s 2>&1',
            escapeshellarg($pdfPath),
            escapeshellarg(str_replace('.png', '', $outputPng)),
        );
        exec($cmd, $_, $status);

        // pdftoppm writes `<prefix>-1.png`. Rename back to what caller expects.
        $prefix = str_replace('.png', '', $outputPng);
        $actual = $prefix.'-1.png';
        if (is_file($actual)) {
            rename($actual, $outputPng);
        }

        if ($status !== 0) {
            throw new \RuntimeException('pdftoppm failed with status '.$status);
        }
    }
}
