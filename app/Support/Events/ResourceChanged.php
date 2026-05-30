<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Domains\Files\Events\CompanyFilesChanged;
use App\Support\Concerns\BroadcastsResourceChanges;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A generic "a collection of records changed" signal, broadcast so any client
 * currently viewing that collection can refresh itself in real time. It is the
 * reusable generalisation of {@see CompanyFilesChanged}:
 * the payload is deliberately tiny (resource + action + id) so subscribers
 * re-fetch the listing rather than receive row data they may not be allowed to
 * see, and so the event stays cheap on the broadcast queue under load.
 *
 * Routing follows the app's central-vs-workspace split:
 *  - workspaceId set  → PrivateChannel("workspace.{id}.resources") — Equipment,
 *    EquipmentCategories, Workspace members, and any other workspace entity.
 *  - workspaceId null → PrivateChannel('admin.resources')          — central
 *    super-admin CRUD: users, roles, permissions, workspaces, modules.
 *
 * Fire it from controllers via {@see BroadcastsResourceChanges}.
 */
class ResourceChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $resource,
        public string $action,
        public int|string|null $id = null,
        public ?int $workspaceId = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            $this->workspaceId !== null
                ? new PrivateChannel('workspace.'.$this->workspaceId.'.resources')
                : new PrivateChannel('admin.resources'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ResourceChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'resource' => $this->resource,
            'action' => $this->action,
            'id' => $this->id,
        ];
    }
}
