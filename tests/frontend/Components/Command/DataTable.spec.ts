import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import DataTable from '@/Components/Command/DataTable.vue';

const columns = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email' },
];
const rows = [
    { id: 1, name: 'Alice', email: 'alice@test.dev' },
    { id: 2, name: 'Bob', email: 'bob@test.dev' },
];

describe('Command/DataTable', () => {
    it('renders column headers and row values', () => {
        const w = mount(DataTable, { props: { rows, columns } });
        expect(w.text()).toContain('Name');
        expect(w.text()).toContain('Email');
        expect(w.text()).toContain('Alice');
        expect(w.text()).toContain('bob@test.dev');
    });

    it('shows the empty text when there are no rows', () => {
        const w = mount(DataTable, { props: { rows: [], columns, emptyText: 'Nothing here' } });
        expect(w.text()).toContain('Nothing here');
    });

    it('emits sort events when a sortable header is clicked', async () => {
        const w = mount(DataTable, { props: { rows, columns, sortKey: '', sortDir: 'desc' } });
        const header = w.findAll('[role="button"]').find((el) => el.text().includes('Name'));
        await header!.trigger('click');
        expect(w.emitted('update:sortKey')?.[0]).toEqual(['name']);
        expect(w.emitted('update:sortDir')?.[0]).toEqual(['asc']);
        expect(w.emitted('sort')?.[0]).toEqual([{ key: 'name', dir: 'asc' }]);
    });

    it('debounces typing and emits update:search', async () => {
        vi.useFakeTimers();
        const w = mount(DataTable, { props: { rows, columns } });
        await w.find('input').setValue('ali');
        expect(w.emitted('update:search')).toBeFalsy();
        vi.advanceTimersByTime(250);
        expect(w.emitted('update:search')?.[0]).toEqual(['ali']);
        vi.useRealTimers();
    });

    it('toggles all selection from the header checkbox', async () => {
        const w = mount(DataTable, { props: { rows, columns, selectable: true, selected: new Set() } });
        await w.find('input[type="checkbox"]').trigger('change');
        const emitted = w.emitted('update:selected')?.[0]?.[0] as Set<number>;
        expect([...emitted].sort()).toEqual([1, 2]);
    });

    it('renders a pagination summary for paginated rows', () => {
        const paginated = { data: rows, current_page: 1, last_page: 3, total: 6, per_page: 2, links: [] };
        const w = mount(DataTable, { props: { rows: paginated, columns } });
        expect(w.text()).toContain('page 1 / 3');
        expect(w.text()).toContain('rows 1–2 / 6');
    });
});
