import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent } from 'vue';

const reload = vi.hoisted(() => vi.fn());
const pageProps = vi.hoisted(() => ({
    current: { workspace: { id: 7 }, auth: { user: { id: 1, is_super_admin: false } } } as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps.current }),
    router: { reload },
}));

import { useLivePageFallback, registerLiveHandler } from '@/composables/useLivePage';

function fakeEcho() {
    const listeners: Record<string, (p: unknown) => void> = {};
    const channel = {
        listen: vi.fn((event: string, cb: (p: unknown) => void) => {
            listeners[event] = cb;
        }),
    };
    const echo = { private: vi.fn(() => channel), leave: vi.fn() };
    return { echo, emit: (event: string, payload: unknown) => listeners[event]?.(payload) };
}

function host(setup: () => void) {
    return mount(defineComponent({ setup() { setup(); return () => null; } }));
}

let wrapper: VueWrapper | null = null;

describe('useLivePageFallback', () => {
    beforeEach(() => {
        reload.mockClear();
        pageProps.current = { workspace: { id: 7 }, auth: { user: { id: 1, is_super_admin: false } } };
        delete (window as unknown as { Echo?: unknown }).Echo;
    });
    afterEach(() => {
        wrapper?.unmount();
        wrapper = null;
        vi.useRealTimers();
    });

    it('subscribes to the workspace channel and reloads on a change when no handler is active', () => {
        vi.useFakeTimers();
        const { echo, emit } = fakeEcho();
        (window as unknown as { Echo?: unknown }).Echo = echo;

        wrapper = host(() => useLivePageFallback());
        expect(echo.private).toHaveBeenCalledWith('workspace.7.resources');

        emit('.ResourceChanged', { resource: 'anything' });
        vi.advanceTimersByTime(500);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('does NOT reload when the page registers its own live handler', () => {
        vi.useFakeTimers();
        const { echo, emit } = fakeEcho();
        (window as unknown as { Echo?: unknown }).Echo = echo;

        wrapper = host(() => {
            registerLiveHandler(); // a page using useLiveList/useLiveReload
            useLivePageFallback();
        });

        emit('.ResourceChanged', { resource: 'anything' });
        vi.advanceTimersByTime(500);
        expect(reload).not.toHaveBeenCalled();
    });

    it('subscribes to admin.resources for a super admin on a central route', () => {
        pageProps.current = { workspace: null, auth: { user: { id: 1, is_super_admin: true } } };
        const { echo } = fakeEcho();
        (window as unknown as { Echo?: unknown }).Echo = echo;

        wrapper = host(() => useLivePageFallback());
        expect(echo.private).toHaveBeenCalledWith('admin.resources');
    });

    it('is a no-op when Echo is unavailable', () => {
        wrapper = host(() => useLivePageFallback());
        expect(reload).not.toHaveBeenCalled();
    });
});
