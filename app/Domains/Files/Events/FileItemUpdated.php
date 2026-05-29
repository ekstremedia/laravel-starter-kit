<?php

declare(strict_types=1);

namespace App\Domains\Files\Events;

use App\Domains\Files\Models\FileItem;
use App\Domains\Users\Models\User;
use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast whenever a FileItem changes in a way the UI needs to know about —
 * preview conversions finishing, a rename, or a delete. The channel is the
 * owner's private channel: User → user, Workspace → workspace.{id}.files,
 * future polymorphic owners can opt in by exposing fileBroadcastChannels().
 */
class FileItemUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public FileItem $item) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $owner = $this->item->owner;

        if ($owner instanceof User) {
            return [new PrivateChannel('App.Models.User.'.$owner->getKey())];
        }

        if ($owner instanceof Workspace) {
            return [new PrivateChannel('workspace.'.$owner->getKey().'.files')];
        }

        // Custom owners can declare their own channel(s) — return empty if
        // the owner doesn't (broadcast still fires, just nowhere to listen).
        if ($owner !== null && method_exists($owner, 'fileBroadcastChannels')) {
            /** @var array<int, Channel> $channels */
            $channels = $owner->fileBroadcastChannels($this->item);

            return $channels;
        }

        return [];
    }

    public function broadcastAs(): string
    {
        return 'FileItemUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $media = $this->item->getFirstMedia('file');
        $doc = $this->item->getFirstMedia('doc_preview');
        $videoPoster = $this->item->getFirstMedia('video_preview');
        $videoWeb = $this->item->getFirstMedia('video_web');

        $isVideo = $this->item->isVideo();
        $webCompatible = $media ? $media->getCustomProperty('web_compatible', false) : false;
        $videoReady = $isVideo && ($videoWeb !== null || (bool) $webCompatible);
        $videoProcessing = $isVideo && ! $videoReady;

        $docPreviewMimes = config('files.preview_mime_types', []);
        $docPreviewProcessing = in_array((string) $this->item->mime_type, $docPreviewMimes, true) && $doc === null;
        // Images intentionally omitted — see FileItemResource for the why.
        $previewProcessing = $videoProcessing || $docPreviewProcessing;

        return [
            'id' => $this->item->id,
            'uuid' => $this->item->uuid,
            'type' => $this->item->type,
            'name' => $this->item->name,
            'mime_type' => $this->item->mime_type,
            'size' => (int) $this->item->size,
            'parent_id' => $this->item->parent_id,
            'is_image' => $this->item->isImage(),
            'is_video' => $isVideo,
            'video_processing' => $videoProcessing,
            'video_ready' => $videoReady,
            'preview_processing' => $previewProcessing,
            'has_doc_preview' => $doc !== null,
            'thumbnail_url' => $media && $media->hasGeneratedConversion('thumb')
                ? $media->getUrl('thumb')
                : ($videoPoster?->getUrl() ?? $doc?->getUrl() ?? ($this->item->isImage() ? $media?->getUrl() : null)),
            'preview_url' => $media && $media->hasGeneratedConversion('medium')
                ? $media->getUrl('medium')
                : ($videoPoster?->getUrl() ?? $doc?->getUrl() ?? ($this->item->isImage() ? $media?->getUrl() : null)),
            'original_url' => $media?->getUrl(),
            'video_web_url' => $videoWeb ? $videoWeb->getUrl() : ($webCompatible ? $media->getUrl() : null),
            'video_poster_url' => $videoPoster?->getUrl(),
        ];
    }
}
