<?php

declare(strict_types=1);

namespace App\Domains\Files\Jobs;

use App\Domains\Files\Events\FileItemUpdated;
use App\Domains\Files\Models\FileItem;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Media\Video;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Two-stage video prep:
 *   1. Generate a poster frame (~5% into the video) as the grid thumbnail →
 *      media collection `video_preview`.
 *   2. If the source isn't already web-friendly H.264 MP4 ≤1080p, transcode
 *      it with ffmpeg and store the result in media collection `video_web`.
 *
 * Either step may be skipped (already web-ready files reuse the original for
 * playback, just the poster is new). Broadcasts `FileItemUpdated` on the
 * owner's private channel after each stage so the grid can swap the thumb
 * and the Play button lights up the moment the MP4 is ready.
 */
class GenerateVideoPreview implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 2;

    private const MAX_WIDTH = 1920;

    private const MAX_HEIGHT = 1080;

    private const WEB_CODECS = ['h264', 'avc1'];

    public function __construct(public int $fileItemId) {}

    public function handle(): void
    {
        $item = FileItem::with('media')->find($this->fileItemId);
        if (! $item || ! $this->isVideo($item)) {
            return;
        }

        $media = $item->getFirstMedia('file');
        if (! $media) {
            return;
        }

        $videoPath = $media->getPath();
        if (! is_file($videoPath)) {
            return;
        }

        try {
            $this->generatePoster($item, $videoPath);
        } catch (\Throwable $e) {
            Log::warning('Video poster generation failed', [
                'file_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Broadcast poster-ready so the grid swaps the generic video icon
        // for the poster image immediately, before the MP4 transcode runs.
        // Guard against force-deletes mid-job (would throw at the event).
        if ($fresh = $item->fresh(['media'])) {
            event(new FileItemUpdated($fresh));
        }

        try {
            $this->generateWebMp4($item, $videoPath);
        } catch (\Throwable $e) {
            Log::warning('Video web-mp4 generation failed', [
                'file_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($fresh = $item->fresh(['media'])) {
            event(new FileItemUpdated($fresh));
        }
    }

    private function isVideo(FileItem $item): bool
    {
        return $item->mime_type !== null && str_starts_with($item->mime_type, 'video/');
    }

    private function generatePoster(FileItem $item, string $videoPath): void
    {
        if ($item->getFirstMedia('video_preview')) {
            return; // idempotent — already generated
        }

        $tmp = sys_get_temp_dir().'/video_poster_'.$item->id.'_'.uniqid('', true).'.jpg';

        $ffmpeg = FFMpeg::create([
            'ffmpeg.binaries' => config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'),
            'ffprobe.binaries' => config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'),
            'timeout' => 120,
        ]);

        // Grab a frame 5% into the clip — avoids fade-in black frames while
        // staying close enough to the start for fast seeks.
        $duration = $this->probeDuration($videoPath);
        $at = max(1.0, min($duration * 0.05, 10.0));

        $video = $ffmpeg->open($videoPath);
        /** @var Video $video */
        // FFMpeg's TimeCode::fromSeconds accepts fractional seconds — pass
        // the float so we don't round off sub-second precision on short clips.
        $video->frame(TimeCode::fromSeconds((float) $at))->save($tmp);

        if (! is_file($tmp)) {
            return;
        }

        $item->addMedia($tmp)
            ->usingName($item->name.' poster')
            ->toMediaCollection('video_preview');
    }

    private function generateWebMp4(FileItem $item, string $videoPath): void
    {
        [$width, $height, $codec, $audioCodec] = $this->probeVideoStream($videoPath);

        // Already-web-ready sources skip transcoding — they stream straight
        // from the original file via the download route. We still set a flag
        // so the UI stops showing the processing spinner.
        if ($this->isWebCompatible($codec, $audioCodec, $width, $height, $videoPath)) {
            $media = $item->getFirstMedia('file');
            if ($media) {
                $media->setCustomProperty('web_compatible', true);
                $media->save();
            }

            return;
        }

        if ($item->getFirstMedia('video_web')) {
            return;
        }

        $ffmpegBin = (string) config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg');
        $out = sys_get_temp_dir().'/video_web_'.$item->id.'_'.uniqid('', true).'.mp4';
        $scale = $this->scaleFilter($width, $height);

        $cmd = sprintf(
            '%s -hide_banner -loglevel error -nostdin -y -i %s -vf %s -c:v libx264 -preset medium -crf 23 -profile:v high -level 4.1 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart %s 2>&1',
            escapeshellarg($ffmpegBin),
            escapeshellarg($videoPath),
            escapeshellarg($scale),
            escapeshellarg($out),
        );

        exec($cmd, $output, $status);

        if ($status !== 0 || ! is_file($out) || filesize($out) === 0) {
            @unlink($out);
            Log::warning('ffmpeg transcode failed', [
                'file_item_id' => $item->id,
                'status' => $status,
                'tail' => array_slice($output, -5),
            ]);

            return;
        }

        $item->addMedia($out)
            ->usingName($item->name.' (web)')
            ->toMediaCollection('video_web');
    }

    private function probeDuration(string $path): float
    {
        try {
            $probe = FFProbe::create([
                'ffmpeg.binaries' => config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'),
                'ffprobe.binaries' => config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'),
            ]);

            return (float) $probe->format($path)->get('duration', 0);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * @return array{0:int,1:int,2:string,3:?string} [width, height, videoCodec, audioCodec]
     */
    private function probeVideoStream(string $path): array
    {
        $probe = FFProbe::create([
            'ffmpeg.binaries' => config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'),
            'ffprobe.binaries' => config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'),
        ]);

        $stream = $probe->streams($path)->videos()->first();
        if (! $stream) {
            return [0, 0, '', null];
        }

        $audioCodecRaw = $probe->streams($path)->audios()->first()?->get('codec_name');
        $audioCodec = $audioCodecRaw !== null ? strtolower((string) $audioCodecRaw) : null;

        return [
            (int) $stream->get('width', 0),
            (int) $stream->get('height', 0),
            strtolower((string) $stream->get('codec_name', '')),
            $audioCodec,
        ];
    }

    private function isWebCompatible(string $codec, ?string $audioCodec, int $width, int $height, string $path): bool
    {
        if (! in_array($codec, self::WEB_CODECS, true)) {
            return false;
        }
        if ($width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
            return false;
        }
        if ($audioCodec !== null && $audioCodec !== '' && ! in_array($audioCodec, ['aac', 'mp3'], true)) {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['mp4', 'm4v'], true);
    }

    private function scaleFilter(int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            return 'scale=trunc(iw/2)*2:trunc(ih/2)*2';
        }
        if ($width <= self::MAX_WIDTH && $height <= self::MAX_HEIGHT) {
            return 'scale=trunc(iw/2)*2:trunc(ih/2)*2';
        }

        return sprintf(
            "scale='min(%d,iw)':'min(%d,ih)':force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2",
            self::MAX_WIDTH,
            self::MAX_HEIGHT,
        );
    }
}
