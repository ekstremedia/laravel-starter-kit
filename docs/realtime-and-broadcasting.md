# Real-time & broadcasting

The app aims to feel *live*: when one user changes something, everyone looking at it sees the change without reloading. This is built on **Reverb** (WebSocket server) + **Laravel Echo**, and it always **degrades gracefully** — if Reverb is down, pages still work, they just stop updating live.

## Infrastructure

- Reverb runs in the container (`docker/supervisord.conf` → `reverb`, port 8080). Echo is initialised in `resources/js/bootstrap.ts` from the `VITE_REVERB_*` env vars (skipped if unset → `window.Echo` is simply absent).
- Broadcasting needs `BROADCAST_CONNECTION=reverb` **and** a running queue worker (Horizon, also supervised). Broadcast events are queued, so a stopped worker means events silently don't deliver — which the frontend treats as "no live updates", not an error.
- Channel authorization lives in `routes/channels.php`.

## The generic live-update pattern

Most "make this list live" needs are covered by one reusable mechanism — a lightweight **change ping** that tells clients to re-fetch, rather than pushing row data (which would leak to subscribers who never loaded the page). It generalises the older `CompanyFilesChanged` flow.

### Backend — broadcast a `ResourceChanged`

`App\Support\Events\ResourceChanged` (`ShouldBroadcast`, queued) carries only `{ resource, action, id }` and routes itself:

- `workspaceId` set → `PrivateChannel("workspace.{id}.resources")` — workspace entities (Equipment, members, …).
- `workspaceId` null → `PrivateChannel("admin.resources")` — central super-admin CRUD (users, roles, …).

Fire it from the controller with the `App\Support\Concerns\BroadcastsResourceChanges` trait, **after** the write:

```php
use App\Support\Concerns\BroadcastsResourceChanges;

class WidgetController extends Controller
{
    use BroadcastsResourceChanges;

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->currentTenant($request);
        $widget = Widget::create([...]);

        $this->broadcastResourceChanged('widgets', 'created', $widget->id, $workspace->id);
        // central/admin resource → omit the 4th arg:
        // $this->broadcastResourceChanged('widgets', 'created', $widget->id);

        return back()->with('success', __('widgets.created'));
    }
}
```

**Dispatch explicitly in the controller — not from a model observer.** Observers fire during seeding, factories and queue jobs, which would spam the channel and break test isolation. Explicit dispatch keeps broadcasting intentional and easy to assert.

For a new workspace channel, copy the existing authorizer in `routes/channels.php` (member-or-super-admin); central channels gate on `isSuperAdmin()`.

### Frontend — `useLiveReload`

On the index/list page, subscribe and let Inertia re-fetch just the affected props:

```ts
import { useLiveReload } from '@/composables/useLiveReload';
import { useWorkspace } from '@/composables/useWorkspace';

// Central admin page:
useLiveReload(() => 'admin.resources', { resource: 'users', only: ['users', 'userStats'] });

// Workspace page:
const { workspace } = useWorkspace();
useLiveReload(
    () => (workspace.value ? `workspace.${workspace.value.id}.resources` : null),
    { resource: 'widgets', only: ['widgets', 'stats'] },
);
```

What it does:
- Subscribes to the channel's `.ResourceChanged` event (guarded — a **no-op** when `window.Echo` is absent, so SSR and Reverb-down both just skip it).
- On a matching ping, runs a **debounced** `router.reload({ only })` (coalesces bursts like bulk deletes). The reload preserves scroll **and** local component state, so the user's search/sort/selection survive.
- Re-binds when the channel target changes (workspace switch) or the auth user changes (login/impersonation), and cleans up on unmount.
- `only` keys must match the controller's `Inertia::render(...)` prop names.
- Optional `poll: <ms>` adds a slow polling fallback **only** when Echo is unavailable (off by default — don't add background traffic unless a page needs it).

The topbar `LiveIndicator` (driven by the Pinia `realtime` store) reflects the live connection state.

## When you need more than a ping

If clients need the actual changed payload (e.g. a chat message body), write a purpose-built event like `MessageSent` / `FileItemUpdated` and listen directly with Echo. Keep payloads minimal and never broadcast data a channel subscriber shouldn't see.

## Notifications

Broadcast notifications target the user's `App.Models.User.{id}` channel. `CommandLayout` holds the single subscription (`useUserChannel`) and feeds the Pinia `notifications` store; other components (e.g. the Notifications page) react to that store rather than opening a second subscription to the same channel (which would fight the layout's on cleanup).

## Testing

- Assert dispatch, not the socket frame: `Event::fake([ResourceChanged::class])` → hit the route → `Event::assertDispatched(...)`. Also assert it does **not** fire on a rejected (validation-failed) request.
- Channel auth: resolve the registered callback via reflection and assert member/super-admin/stranger/null outcomes (see `tests/Feature/LiveUpdates/ResourcesChannelAuthTest.php`).
- Frontend: stub `window.Echo`, emit a `.ResourceChanged`, assert the debounced `router.reload` with the right `only` (see `tests/frontend/composables/useLiveReload.spec.ts`).
