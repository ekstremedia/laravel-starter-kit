import { onBeforeUnmount, ref, watch, type Ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { registerLiveHandler } from '@/composables/useLivePage';
import type { PageProps } from '@/types';

interface ResourcePayload {
    resource?: string;
    action?: 'created' | 'updated' | 'deleted';
    id?: number | string;
}

interface LiveListOptions<Row> {
    /** Channel to subscribe to (getter so it re-binds on workspace switch); null = none. */
    channel: () => string | null;
    /** Only react to this logical resource (the ResourceChanged `resource` field). */
    resource: string;
    /** The current rows from the page prop — re-synced on every navigation. */
    source: () => Row[];
    /** Fetch ONE row in the same shape as the list rows, or null if it's no longer visible. */
    fetchOne: (id: number | string) => Promise<Row | null>;
    /** Row identity (defaults to `row.id`). */
    getId?: (row: Row) => number | string;
    /** Cheap props (counts/stats) to refresh after any change. */
    refreshOnly?: string[];
    /**
     * Props to reload when a bulk change arrives (a ResourceChanged with no id —
     * e.g. "bulk delete"). Surgical per-row patching can't apply there, so we
     * fall back to one reload. Defaults to `refreshOnly` (counts only) — pass the
     * list prop too if you want the visible rows refreshed on bulk ops.
     */
    bulkReload?: string[];
    /** Debounce (ms) for the stats/bulk reload. */
    debounce?: number;
}

/**
 * Surgically live-update a list: instead of re-fetching the whole page on every
 * change, a single changed row fetches *only that row* and is patched in place,
 * so just that row re-renders. Counts/stats refresh cheaply. Pairs with the
 * backend `ResourceChanged` ping (see docs/realtime-and-broadcasting.md).
 *
 * Returns a reactive `rows` ref to render from. It stays in sync with the page
 * prop across normal navigations (sort/search/paginate), and applies live
 * patches between them:
 *   - updated → replace the row in place (keeps its position; only it re-renders)
 *   - created → upsert (prepend if new; replace if already present, which also
 *               dedupes the actor's own echo after their redirect)
 *   - deleted → remove the row
 *
 * Degrades gracefully: a no-op when Echo/Reverb is unavailable.
 */
export function useLiveList<Row>(opts: LiveListOptions<Row>): Ref<Row[]> {
    registerLiveHandler();
    const page = usePage<PageProps>();
    const getId = opts.getId ?? ((r: Row) => (r as { id: number | string }).id);
    const rows = ref<Row[]>([...opts.source()]) as Ref<Row[]>;

    // Re-sync when the underlying prop changes (a full/partial Inertia reload,
    // pagination, sort, search) so live patches never fight server state.
    watch(opts.source, (next) => {
        rows.value = [...next];
    });

    let bound: string | null = null;
    let reloadTimer: ReturnType<typeof setTimeout> | null = null;

    function scheduleReload(only: string[] | undefined) {
        if (!only?.length) {
            return;
        }
        if (reloadTimer) {
            clearTimeout(reloadTimer);
        }
        reloadTimer = setTimeout(() => router.reload({ only }), opts.debounce ?? 350);
    }

    function upsert(row: Row) {
        const id = getId(row);
        const i = rows.value.findIndex((r) => getId(r) === id);
        if (i >= 0) {
            rows.value.splice(i, 1, row);
        } else {
            rows.value.unshift(row);
        }
    }

    function remove(id: number | string) {
        rows.value = rows.value.filter((r) => getId(r) !== id);
    }

    async function handle(payload: ResourcePayload) {
        if (opts.resource && payload.resource !== opts.resource) {
            return;
        }

        // Bulk / unspecified change: can't patch a single row — fall back to one
        // reload (counts, and the list too if the page opted in via bulkReload).
        if (payload.id == null) {
            scheduleReload(opts.bulkReload ?? opts.refreshOnly);
            return;
        }

        if (payload.action === 'deleted') {
            remove(payload.id);
            scheduleReload(opts.refreshOnly);
            return;
        }

        // created / updated → fetch just this row and patch it in.
        const row = await opts.fetchOne(payload.id);
        if (row) {
            upsert(row);
        } else {
            // No longer visible to this viewer (filtered out / not permitted).
            remove(payload.id);
        }
        scheduleReload(opts.refreshOnly);
    }

    function unbind() {
        if (bound && typeof window !== 'undefined') {
            window.Echo?.leave(bound);
        }
        bound = null;
        if (reloadTimer) {
            clearTimeout(reloadTimer);
            reloadTimer = null;
        }
    }

    function bind() {
        unbind();
        if (typeof window === 'undefined') {
            return;
        }
        const echo = window.Echo;
        const name = opts.channel();
        if (!echo || !name) {
            return;
        }
        bound = name;
        echo.private(name).listen('.ResourceChanged', (payload: ResourcePayload) => handle(payload));
    }

    watch([opts.channel, () => page.props.auth?.user?.id], bind, { immediate: true });
    onBeforeUnmount(unbind);

    return rows;
}
