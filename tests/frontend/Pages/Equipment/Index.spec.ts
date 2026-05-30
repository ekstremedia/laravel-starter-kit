import { mount } from '@vue/test-utils';
import { beforeEach, describe, it, expect, vi } from 'vitest';

// The column-toggle test writes to localStorage; reset shared browser state so
// specs are order-independent.
beforeEach(() => {
    localStorage.clear();
});

vi.mock('@/composables/useWorkspace', () => ({
    useWorkspace: () => ({ workspaceUrl: (p: string) => `/w/acme${p}` }),
}));

// Local Inertia mock with a no-op <Head> (its head manager isn't set up in unit
// tests) plus a simple <Link>; keep the real useForm.
vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual<any>('@inertiajs/vue3');
    return {
        ...actual,
        Head: { name: 'Head', render: () => null },
        Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
        usePage: () => ({ props: { auth: { user: null }, locale: 'en' } }),
        router: { get: vi.fn(), post: vi.fn(), delete: vi.fn(), patch: vi.fn(), put: vi.fn(), visit: vi.fn(), on: vi.fn(() => vi.fn()) },
    };
});

import Index from '@/Pages/Equipment/Index.vue';

const row = (over: Record<string, unknown> = {}) => ({
    id: 1, name: 'Forklift', category: 'Machine', serial: 'FL-1',
    files_count: 0, files_preview: [], cover: null, ...over,
});

const baseProps = () => ({
    equipment: {
        data: [row({ id: 1, name: 'Forklift' }), row({ id: 2, name: 'Delivery Van' })],
        current_page: 1, last_page: 1, total: 5, links: [],
    },
    can_manage: true,
    search: null,
    categories: ['Machine'],
    stats: { total: 5, with_files: 1, by_category: [{ label: 'Machine', count: 5 }] },
});

// <Head> needs Inertia's head manager (absent in unit tests); stub it.
const mountIndex = () => mount(Index, { props: baseProps(), global: { stubs: { Head: true } } });

describe('Pages/Equipment/Index', () => {
    it('renders rows and the stats strip', () => {
        const w = mountIndex();
        expect(w.text()).toContain('Forklift');
        expect(w.text()).toContain('Delivery Van');
        // stat labels render as their i18n keys (t returns the key in tests)
        expect(w.text()).toContain('equipment.stat_total');
    });

    it('toggles a column off (persisted) via the Columns menu', async () => {
        localStorage.removeItem('equipment.columns');
        const w = mountIndex();

        const columnsBtn = w.findAll('button').find((b) => b.text().includes('equipment.columns'))!;
        await columnsBtn.trigger('click');

        const menu = w.find('[role="menu"]');
        expect(menu.exists()).toBe(true);
        expect(menu.text()).toContain('equipment.serial');

        const serialToggle = menu.findAll('input[type="checkbox"]').find((c) => c.element.parentElement?.textContent?.includes('equipment.serial'))!;
        await serialToggle.setValue(false);

        // Persisted hidden set drives the visible columns.
        expect(JSON.parse(localStorage.getItem('equipment.columns') || '[]')).toContain('serial');
    });

    it('offers "select all matching" with an X-of-Y count when the page is fully selected', async () => {
        const w = mountIndex();

        // The first checkbox is the DataTable header select-all.
        const headerCheckbox = w.findAll('input[type="checkbox"]')[0];
        await headerCheckbox.setValue(true);

        // 2 selected on this page of a 5-total set → count + select-all link.
        expect(w.text()).toContain('equipment.selected_of count=2 total=5');
        expect(w.text()).toContain('equipment.select_all_matching total=5');
    });
});
