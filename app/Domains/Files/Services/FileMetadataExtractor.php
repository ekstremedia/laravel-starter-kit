<?php

declare(strict_types=1);

namespace App\Domains\Files\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Reads file metadata with exiftool and normalizes it to a small, stable shape
 * the frontend can rely on — independent of exiftool's sprawling tag names.
 *
 * exiftool handles images, RAW, video, audio and PDF in one binary, including
 * GPS, so it's the single tool behind both the RAW preview pipeline and the
 * Details panel. If the binary is absent (e.g. CI without the package) we
 * return [] and log once rather than throwing — callers treat "no metadata"
 * as a non-fatal outcome. This absence path is also the test seam.
 *
 * @phpstan-type NormalizedMetadata array{
 *     dimensions?: array{width:int,height:int},
 *     camera?: array{make?:string,model?:string},
 *     lens?: string,
 *     iso?: int,
 *     exposure?: string,
 *     aperture?: float,
 *     focal_length?: float,
 *     gps?: array{lat:float,lng:float},
 *     captured_at?: string,
 *     duration?: float,
 *     video_codec?: string,
 *     audio_codec?: string,
 *     page_count?: int,
 *     file_type?: string,
 * }
 */
class FileMetadataExtractor
{
    /**
     * Run exiftool over the file and return normalized metadata. Returns [] on
     * any failure (binary missing, unreadable file, malformed JSON).
     *
     * @return array<string, mixed>
     */
    public function extract(string $absolutePath): array
    {
        if (! is_file($absolutePath)) {
            return [];
        }

        $raw = $this->runExiftool($absolutePath);
        if ($raw === null) {
            return [];
        }

        return $this->normalize($raw);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runExiftool(string $path): ?array
    {
        $binary = (string) config('files.exiftool_binary', 'exiftool');

        try {
            // -n  → numeric values (signed-decimal GPS, raw aperture/exposure)
            // -json → machine-readable; -G0 keeps group prefixes off the keys
            // -api largefilesupport=1 → don't choke on >4 GB media
            $process = new Process([
                $binary, '-json', '-n', '-api', 'largefilesupport=1', $path,
            ]);
            $process->setTimeout(30);
            $process->run();
        } catch (\Throwable $e) {
            // Binary not found / not executable — log once at debug and move on.
            Log::debug('exiftool unavailable for metadata extraction', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $process->isSuccessful()) {
            Log::debug('exiftool exited non-zero', [
                'path' => $path,
                'stderr' => $process->getErrorOutput(),
            ]);

            return null;
        }

        $decoded = json_decode($process->getOutput(), true);
        if (! is_array($decoded) || ! isset($decoded[0]) || ! is_array($decoded[0])) {
            return null;
        }

        return $decoded[0];
    }

    /**
     * Pull a curated allowlist out of exiftool's full tag dump.
     *
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function normalize(array $t): array
    {
        $out = [];

        $width = $this->int($t['ImageWidth'] ?? $t['ExifImageWidth'] ?? null);
        $height = $this->int($t['ImageHeight'] ?? $t['ExifImageHeight'] ?? null);
        if ($width && $height) {
            $out['dimensions'] = ['width' => $width, 'height' => $height];
        }

        $make = $this->str($t['Make'] ?? null);
        $model = $this->str($t['Model'] ?? $t['CameraModelName'] ?? null);
        if ($make || $model) {
            $out['camera'] = array_filter(['make' => $make, 'model' => $model]);
        }

        if ($lens = $this->str($t['LensModel'] ?? $t['LensID'] ?? $t['Lens'] ?? null)) {
            $out['lens'] = $lens;
        }

        if ($iso = $this->int($t['ISO'] ?? null)) {
            $out['iso'] = $iso;
        }

        // ExposureTime arrives as a float second (0.005). Present as "1/200 s".
        $exposure = $t['ExposureTime'] ?? $t['ShutterSpeedValue'] ?? null;
        if (is_numeric($exposure) && (float) $exposure > 0) {
            $out['exposure'] = $this->formatExposure((float) $exposure);
        }

        if (isset($t['FNumber']) && is_numeric($t['FNumber'])) {
            $out['aperture'] = round((float) $t['FNumber'], 1);
        } elseif (isset($t['ApertureValue']) && is_numeric($t['ApertureValue'])) {
            $out['aperture'] = round((float) $t['ApertureValue'], 1);
        }

        if (isset($t['FocalLength']) && is_numeric($t['FocalLength'])) {
            $out['focal_length'] = round((float) $t['FocalLength'], 1);
        }

        $lat = $t['GPSLatitude'] ?? null;
        $lng = $t['GPSLongitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lng) && ((float) $lat !== 0.0 || (float) $lng !== 0.0)) {
            $out['gps'] = ['lat' => round((float) $lat, 6), 'lng' => round((float) $lng, 6)];
        }

        if ($captured = $this->str($t['DateTimeOriginal'] ?? $t['CreateDate'] ?? $t['MediaCreateDate'] ?? null)) {
            $out['captured_at'] = $captured;
        }

        if (isset($t['Duration']) && is_numeric($t['Duration'])) {
            $out['duration'] = round((float) $t['Duration'], 2);
        }

        if ($vcodec = $this->str($t['CompressorID'] ?? $t['VideoCodec'] ?? null)) {
            $out['video_codec'] = $vcodec;
        }
        if ($acodec = $this->str($t['AudioFormat'] ?? $t['AudioCodec'] ?? null)) {
            $out['audio_codec'] = $acodec;
        }

        if ($pages = $this->int($t['PageCount'] ?? $t['Pages'] ?? null)) {
            $out['page_count'] = $pages;
        }

        if ($type = $this->str($t['FileType'] ?? null)) {
            $out['file_type'] = $type;
        }

        return $out;
    }

    private function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    private function str(mixed $v): ?string
    {
        if (! is_string($v) && ! is_numeric($v)) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    /**
     * Format a shutter speed in seconds as a photographer-friendly string:
     * 0.005 → "1/200 s", 2.0 → "2 s".
     */
    private function formatExposure(float $seconds): string
    {
        if ($seconds >= 1.0) {
            return rtrim(rtrim(number_format($seconds, 1, '.', ''), '0'), '.').' s';
        }

        return '1/'.(int) round(1 / $seconds).' s';
    }
}
