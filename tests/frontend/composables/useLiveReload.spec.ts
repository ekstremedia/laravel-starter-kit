import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent } from 'vue';

const reload = vi.hoisted(() => vi.fn());

// Local override of the setup-file Inertia mock so router.reload is a spy and
// usePage exposes a stable auth user id for the re-bind watcher.
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { id: 1 } } } }),
    router: { reload },
}));

import { useLiveReload } from '@/composables/useLiveReload';

interface FakeEcho {
    echo: { private: ReturnType<typeof vi.fn>; leave: ReturnType<typeof vi.fn> };
    emit: (event: string, payload: unknown) => void;
}

function fakeEcho(): FakeEcho {
    const listeners: Record<string, (p: unknown) => void> = {};
    const channel = {
        listen: vi.fn((event: string, cb: (p: unknown) => void) => {
            listeners[event] = cb;
            return channel;
        }),
    };
    const echo = { private: vi.fn(() => channel), leave: vi.fn() };
    return { echo, emit: (event, payload) => listeners[event]?.(payload) };
}

function mountWith(channelName: () => string | null, opts: Parameters<typeof useLiveReload>[1]) {
    return mount(
        defineComponent({
            setup() {
                useLiveReload(channelName, opts);
                return () => null;
            },
        }),
    );
}

describe('useLiveReload', () => {
    beforeEach(() => {
        vi.useRealTimers();
        reload.mockClear();
        delete (window as unknown as { Echo?: unknown }).Echo;
    });

    it('does nothing (no error) when Echo is not configured', () => {
        mountWith(() => 'admin.resources', { resource: 'users', only: ['users'] });
        expect(reload).not.toHaveBeenCalled();
    });

    it('subscribes to the channel and debounce-reloads on a matching change', () => {
        vi.useFakeTimers();
        const { echo, emit } = fakeEcho();
        (window as unknown as { Echo?: unknown }).Echo = echo;

        mountWith(() => 'admin.resources', { resource: 'users', only: ['users', 'userStats'], debounce: 100 });
        expect(echo.private).toHaveBeenCalledWith('admin.resources');

        // A burst of events should coalesce into a single reload.
        emit('.ResourceChanged', { resource: 'users', action: 'created', id: 5 });
        emit('.ResourceChanged', { resource: 'users', action: 'deleted', id: 6 });
        expect(reload).not.toHaveBeenCalled();

        vi.advanceTimersByTime(120);
        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['users', 'userStats'] }));
    });

    it('ignores changes for a different resource', () => {
        vi.useFakeTimers();
        const { echo, emit } = fakeEcho();
        (window as unknown as { Echo?: unknown }).Echo = echo;

        mountWith(() => 'admin.resources', { resource: 'users', only: ['users'] });
        emit('.ResourceChanged', { resource: 'roles', action: 'created', id: 1 });

        vi.advanceTimersByTime(1000);
        expect(reload).not.toHaveBeenCalled();
    });

    it('does not subscribe when the channel getter returns null', () => {
        const { echo } = fakeEcho();
        (window as unknown as { Echo?: unknown }).Echo = echo;

        mountWith(() => null, { resource: 'users', only: ['users'] });
        expect(echo.private).not.toHaveBeenCalled();
    });
});
