import { onBeforeUnmount, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import type { PageProps } from '@/types';

/**
 * Count of page-level live handlers (useLiveList / useLiveReload) currently
 * mounted. While > 0 the active page manages its own live updates, so the
 * generic fallback below stays out of the way — preserving the surgical,
 * patch-one-row behaviour on the big list pages.
 */
const activeHandlers = ref(0);

/** Called by useLiveList / useLiveReload so the page opts out of the fallback. */
export function registerLiveHandler(): void {
    activeHandlers.value++;
    onBeforeUnmount(() => {
        activeHandlers.value = Math.max(0, activeHandlers.value - 1);
    });
}

interface EchoLike {
    private(name: string): { listen(event: string, cb: (payload: unknown) => void): void };
    leave(name: string): void;
}

/**
 * App-level safety net so we never silently "miss a place": any authenticated
 * page that does NOT wire its own useLiveList/useLiveReload still reflects
 * changes. On any `ResourceChanged` on the page's channel — the active workspace
 * channel on /w routes, or `admin.resources` for a super admin on a central
 * route — the current page is reloaded (debounced, preserving scroll + local
 * state, so unsaved form input survives). Suppressed when the page manages its
 * own live updates (registerLiveHandler). Graceful no-op when Echo is down.
 *
 * Call once from the persistent authenticated layout.
 */
export function useLivePageFallback(): void {
    const page = usePage<PageProps>();
    const bound = new Set<string>();
    let timer: ReturnType<typeof setTimeout> | null = null;

    function reload() {
        if (activeHandlers.value > 0) {
            return; // the page handles its own updates
        }
        if (timer) {
            clearTimeout(timer);
        }
        // router.reload preserves scroll + local state by default, so unsaved
        // form input on the reloaded page survives.
        timer = setTimeout(() => router.reload(), 400);
    }

    function channels(): string[] {
        // One channel per page context: a /w route listens to its workspace; a
        // central super-admin route listens to admin.resources.
        const workspaceId = page.props.workspace?.id;
        if (workspaceId) {
            return [`workspace.${workspaceId}.resources`];
        }
        if (page.props.auth?.user?.is_super_admin) {
            return ['admin.resources'];
        }
        return [];
    }

    function echo(): EchoLike | undefined {
        if (typeof window === 'undefined') {
            return undefined;
        }
        return (window as unknown as { Echo?: EchoLike }).Echo;
    }

    function unbind() {
        const e = echo();
        bound.forEach((name) => e?.leave(name));
        bound.clear();
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function bind() {
        unbind();
        const e = echo();
        if (!e) {
            return;
        }
        for (const name of channels()) {
            e.private(name).listen('.ResourceChanged', () => reload());
            bound.add(name);
        }
    }

    watch(
        [() => page.props.workspace?.id, () => page.props.auth?.user?.id],
        bind,
        { immediate: true },
    );
    onBeforeUnmount(unbind);
}
