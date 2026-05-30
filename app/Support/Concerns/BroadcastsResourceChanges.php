<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Support\Events\ResourceChanged;

/**
 * Controller helper for emitting {@see ResourceChanged} on create/update/delete.
 * Kept as an explicit, opt-in call (rather than a model observer) so it never
 * fires during seeding, factories, or queue jobs — broadcasting stays
 * intentional and easy to assert in tests.
 *
 * Pass a $workspaceId for workspace-scoped entities (routes to that workspace's
 * private channel); omit it for central super-admin CRUD (routes to
 * `admin.resources`).
 */
trait BroadcastsResourceChanges
{
    protected function broadcastResourceChanged(
        string $resource,
        string $action,
        int|string|null $id = null,
        ?int $workspaceId = null,
    ): void {
        $event = new ResourceChanged($resource, $action, $id, $workspaceId);
        // Don't echo back to the user who made the change — their own window
        // already reflects it via the mutation response. Other users on the
        // channel still get the live update. (No-op for non-Echo requests.)
        $event->dontBroadcastToCurrentUser();

        event($event);
    }
}
