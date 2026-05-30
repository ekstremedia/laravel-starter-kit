import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, ref, type Ref } from 'vue';

const reload = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { id: 1 } } } }),
    router: { reload },
}));

import { useLiveList } from '@/composables/useLiveList';

interface Row { id: number; name: string }

function fakeEcho() {
    const listeners: Record<string, (p: unknown) => void> = {};
    const channel = {
        listen: vi.fn((event: string, cb: (p: unknown) => void) => {
            listeners[event] = cb;
            return channel;
        }),
    };
    const echo = { private: vi.fn(() => channel), leave: vi.fn() };
    return { echo, emit: (event: string, payload: unknown) => listeners[event]?.(payload) };
}

function mountList(source: Ref<Row[]>, fetchOne: (id: number | string) => Promise<Row | null>) {
    let rows!: Ref<Row[]>;
    const wrapper = mount(
        defineComponent({
            setup() {
                rows = useLiveList<Row>({
                    channel: () => 'admin.resources',
                    resource: 'users',
                    source: () => source.value,
                    fetchOne,
                    refreshOnly: ['userStats'],
                    bulkReload: ['users', 'userStats'],
                    debounce: 10,
                });
                return () => null;
            },
        }),
    );
    return { wrapper, rows: () => rows };
}

describe('useLiveList', () => {
    let echoCtl: ReturnType<typeof fakeEcho>;

    beforeEach(() => {
        reload.mockClear();
        echoCtl = fakeEcho();
        (window as unknown as { Echo?: unknown }).Echo = echoCtl.echo;
    });

    it('replaces only the changed row in place on "updated" (no full reload)', async () => {
        const source = ref<Row[]>([{ id: 1, name: 'A' }, { id: 2, name: 'B' }, { id: 3, name: 'C' }]);
        const fetchOne = vi.fn(async (id: number | string) => ({ id: Number(id), name: 'B-updated' }));
        const { rows } = mountList(source, fetchOne);

        echoCtl.emit('.ResourceChanged', { resource: 'users', action: 'updated', id: 2 });
        await flushPromises();

        expect(fetchOne).toHaveBeenCalledWith(2);
        // Row 2 replaced in place; positions preserved; rows 1 and 3 untouched.
        expect(rows().value.map((r) => r.name)).toEqual(['A', 'B-updated', 'C']);
    });

    it('upserts (prepends) a created row, deduping the actor echo', async () => {
        const source = ref<Row[]>([{ id: 1, name: 'A' }]);
        const fetchOne = vi.fn(async (id: number | string) => ({ id: Number(id), name: `row-${id}` }));
        const { rows } = mountList(source, fetchOne);

        echoCtl.emit('.ResourceChanged', { resource: 'users', action: 'created', id: 9 });
        await flushPromises();
        expect(rows().value[0]).toEqual({ id: 9, name: 'row-9' });
        expect(rows().value).toHaveLength(2);

        // Same id again (e.g. the creator's own echo) → replaced, not duplicated.
        echoCtl.emit('.ResourceChanged', { resource: 'users', action: 'created', id: 9 });
        await flushPromises();
        expect(rows().value).toHaveLength(2);
    });

    it('removes a deleted row without fetching', async () => {
        const source = ref<Row[]>([{ id: 1, name: 'A' }, { id: 2, name: 'B' }]);
        const fetchOne = vi.fn();
        const { rows } = mountList(source, fetchOne);

        echoCtl.emit('.ResourceChanged', { resource: 'users', action: 'deleted', id: 1 });
        await flushPromises();

        expect(fetchOne).not.toHaveBeenCalled();
        expect(rows().value.map((r) => r.id)).toEqual([2]);
    });

    it('drops a row whose fetchOne returns null (no longer visible)', async () => {
        const source = ref<Row[]>([{ id: 1, name: 'A' }, { id: 2, name: 'B' }]);
        const fetchOne = vi.fn(async () => null);
        const { rows } = mountList(source, fetchOne);

        echoCtl.emit('.ResourceChanged', { resource: 'users', action: 'updated', id: 2 });
        await flushPromises();

        expect(rows().value.map((r) => r.id)).toEqual([1]);
    });

    it('falls back to a single reload for a bulk (no-id) change', async () => {
        vi.useFakeTimers();
        const source = ref<Row[]>([{ id: 1, name: 'A' }]);
        const { } = mountList(source, vi.fn());

        echoCtl.emit('.ResourceChanged', { resource: 'users', action: 'deleted' });
        vi.advanceTimersByTime(20);
        expect(reload).toHaveBeenCalledWith({ only: ['users', 'userStats'] });
        vi.useRealTimers();
    });

    it('ignores changes for other resources', async () => {
        const source = ref<Row[]>([{ id: 1, name: 'A' }]);
        const fetchOne = vi.fn();
        const { rows } = mountList(source, fetchOne);

        echoCtl.emit('.ResourceChanged', { resource: 'roles', action: 'updated', id: 1 });
        await flushPromises();
        expect(fetchOne).not.toHaveBeenCalled();
        expect(rows().value).toHaveLength(1);
    });

    it('re-syncs from the source on navigation', async () => {
        const source = ref<Row[]>([{ id: 1, name: 'A' }]);
        const { rows } = mountList(source, vi.fn());

        source.value = [{ id: 5, name: 'fresh' }];
        await flushPromises();
        expect(rows().value).toEqual([{ id: 5, name: 'fresh' }]);
    });
});
