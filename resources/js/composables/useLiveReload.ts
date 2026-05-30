import { onBeforeUnmount, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { registerLiveHandler } from '@/composables/useLivePage';
import type { PageProps } from '@/types';

interface ResourcePayload {
    resource?: string;
    action?: string;
    id?: number | string;
}

interface LiveReloadOptions {
    /**
     * Only react to this logical resource (the `resource` field of the
     * ResourceChanged payload). Omit to react to every change on the channel.
     */
    resource?: string;
    /** Inertia partial-reload `only` keys to refetch, e.g. ['users', 'userStats']. */
    only: string[];
    /** Debounce window (ms) to coalesce bursts like bulk deletes. Default 400. */
    debounce?: number;
    /**
     * Optional degraded fallback: when Echo isn't available, poll the server
     * every N ms instead. Off by default — don't add background traffic unless
     * the page opts in.
     */
    poll?: number;
    /** Runs after each reload finishes (e.g. to prune a stale selection). */
    onReload?: () => void;
}

/**
 * Subscribe to a private "{...}.resources" channel and do a debounced Inertia
 * partial reload whenever a {@see App\Support\Events\ResourceChanged} ping
 * arrives, so an index/list page stays live as other users mutate it.
 *
 * Degrades gracefully: if `window.Echo` is absent (Reverb down, WebSockets not
 * configured, or SSR) it simply does nothing — the page keeps working, just
 * without live updates — unless an explicit `poll` fallback is provided.
 *
 * `channelName` is a getter so the subscription re-binds when the target
 * changes (e.g. switching workspace); it may return null to subscribe to
 * nothing. Re-binds on auth-user change too (login / impersonation), mirroring
 * {@see useUserChannel}.
 */
export function useLiveReload(channelName: () => string | null, opts: LiveReloadOptions) {
    registerLiveHandler();
    const page = usePage<PageProps>();
    let boundChannel: string | null = null;
    let debounceTimer: ReturnType<typeof setTimeout> | null = null;
    let pollTimer: ReturnType<typeof setInterval> | null = null;

    function reload() {
        // router.reload() preserves scroll + local component state by default,
        // so an admin's search/sort/selection survives a live refresh.
        router.reload({
            only: opts.only,
            onFinish: opts.onReload,
        });
    }

    function scheduleReload() {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(reload, opts.debounce ?? 400);
    }

    function handle(payload: ResourcePayload) {
        if (opts.resource && payload?.resource !== opts.resource) {
            return;
        }
        scheduleReload();
    }

    function unbind() {
        if (boundChannel && typeof window !== 'undefined') {
            window.Echo?.leave(boundChannel);
        }
        boundChannel = null;
        if (debounceTimer) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function bind() {
        unbind();
        if (typeof window === 'undefined') {
            return;
        }

        const name = channelName();
        if (!name) {
            return;
        }

        const echo = window.Echo;
        if (echo) {
            boundChannel = name;
            echo.private(name).listen('.ResourceChanged', (payload: ResourcePayload) => handle(payload));
        } else if (opts.poll) {
            // No WebSocket available — slow poll so the page still catches up.
            pollTimer = setInterval(reload, opts.poll);
        }
    }

    watch([channelName, () => page.props.auth?.user?.id], bind, { immediate: true });

    onBeforeUnmount(unbind);
}
