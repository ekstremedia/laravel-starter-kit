<?php

declare(strict_types=1);

namespace App\Domains\Files\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever the company-shared file tree for a tenant changes: upload,
 * folder create, rename/move, delete, share/unshare. Every member connected
 * to the tenant's private files channel receives the event and reloads its
 * Inertia props so two people editing simultaneously stay in sync without
 * having to hit reload.
 *
 * The payload intentionally carries only a version + reason; clients
 * re-fetch the listing when triggered. That keeps the event small enough
 * for a broadcast queue worker under peak load, and avoids leaking file
 * names / owner info across users that didn't load the page (who are still
 * subscribed to the channel).
 */
class CompanyFilesChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $tenantId,
        public string $reason,
        public int $version,
        public ?int $folderId = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('customer.'.$this->tenantId.'.files')];
    }

    public function broadcastAs(): string
    {
        return 'CompanyFilesChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'reason' => $this->reason,
            'version' => $this->version,
            'folder_id' => $this->folderId,
        ];
    }
}
