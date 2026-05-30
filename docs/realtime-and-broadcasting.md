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

### Frontend — `useLiveList` (surgical, preferred for lists)

A change ping should update **only the changed row**, not re-fetch the whole list. `useLiveList` does exactly that: it keeps a local reactive copy of the list and, on a ping, fetches **just that one row** from a lightweight endpoint and patches it in place. Two ingredients:

**1. A single-row endpoint** — a tiny authorized JSON action returning one row in the *exact* index list-shape (reuse the index's row-shaper so they never drift):

```php
// Route: GET /admin/widgets/{widget}/live-row  (same auth as index)
public function liveRow(Widget $widget): JsonResponse
{
    $widget->loadCount('parts');         // mirror index()'s withCount/with/appends
    return response()->json($this->widgetRow($widget)); // $widgetRow is shared with index()
}
```

**2. `useLiveList` on the page** — render from the returned `rows` ref instead of the prop:

```ts
import { useLiveList } from '@/composables/useLiveList';
import { fetchJson } from '@/utils/fetchJson';

const rows = useLiveList<WidgetRow>({
    channel: () => 'admin.resources',          // or `workspace.${id}.resources`
    resource: 'widgets',
    source: () => props.widgets.data,          // re-synced on every navigation
    fetchOne: (id) => fetchJson<WidgetRow>(`/admin/widgets/${id}/live-row`),
    refreshOnly: ['widgetStats'],              // cheap counts refresh (partial reload)
    bulkReload: ['widgets', 'widgetStats'],    // bulk (no-id) ping → one reload
});
// For a paginated table, feed the table { ...props.widgets, data: rows.value }.
```

Behaviour (see `useLiveList.ts`):
- `updated` → fetch the row, **replace it in place** (position preserved; only that row re-renders).
- `created` → fetch + upsert (prepend; replace if already present, which dedupes the actor's own echo).
- `deleted` → remove by id (no fetch).
- A **bulk** ping (no `id`) falls back to a single reload of `bulkReload` (per-row would be N fetches).
- Counts refresh via a debounced partial reload of `refreshOnly` (a tiny payload — never the list).
- Guarded: a **no-op** when `window.Echo` is absent (SSR / Reverb down). Re-binds on workspace/auth change. Re-syncs `rows` from `source` on every navigation, so sort/search/paginate stay authoritative.

The single-row endpoint must reuse the index's row-shaper and authorize identically (404 when the record is out of scope / not visible — `useLiveList` then drops the stale row).

### Frontend — `useLiveReload` (simpler, whole-prop reload)

`useLiveReload(channel, { resource, only })` is the lighter alternative: on a ping it just does a debounced `router.reload({ only })`. Fine for a tiny fixed list or a stats-only surface, but for any list that can grow, prefer `useLiveList` so clients don't receive the whole page on every change.

The topbar `LiveIndicator` (driven by the Pinia `realtime` store) reflects the live connection state.

## When you need more than a ping

If clients need the actual changed payload (e.g. a chat message body), write a purpose-built event like `MessageSent` / `FileItemUpdated` and listen directly with Echo. Keep payloads minimal and never broadcast data a channel subscriber shouldn't see.

## Notifications

Broadcast notifications target the user's `App.Models.User.{id}` channel. `CommandLayout` holds the single subscription (`useUserChannel`) and feeds the Pinia `notifications` store; other components (e.g. the Notifications page) react to that store rather than opening a second subscription to the same channel (which would fight the layout's on cleanup).

## Testing

- Assert dispatch, not the socket frame: `Event::fake([ResourceChanged::class])` → hit the route → `Event::assertDispatched(...)`. Also assert it does **not** fire on a rejected (validation-failed) request.
- Channel auth: resolve the registered callback via reflection and assert member/super-admin/stranger/null outcomes (see `tests/Feature/LiveUpdates/ResourcesChannelAuthTest.php`).
- Frontend: stub `window.Echo`, emit a `.ResourceChanged`, assert the debounced `router.reload` with the right `only` (see `tests/frontend/composables/useLiveReload.spec.ts`).
