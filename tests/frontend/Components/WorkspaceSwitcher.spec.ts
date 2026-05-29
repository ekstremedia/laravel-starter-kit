import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const pageProps: { props: Record<string, unknown> } = { props: {} };

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual<any>('@inertiajs/vue3');
    return {
        ...actual,
        usePage: () => pageProps,
    };
});

import WorkspaceSwitcher from '@/Components/Command/WorkspaceSwitcher.vue';

function setPage(
    workspaces: Array<{ id: number; slug: string; name: string }>,
    current: { id: number; slug: string; name: string } | null = null,
    tenancyEnabled = true,
) {
    pageProps.props = {
        workspaces: { enabled: tenancyEnabled },
        available_workspaces: workspaces,
        workspace: current,
    };
}

describe('WorkspaceSwitcher', () => {
    beforeEach(() => {
        pageProps.props = {};
    });

    it('renders nothing when workspaces is disabled', () => {
        setPage([{ id: 1, slug: 'a', name: 'A' }], null, false);
        const wrapper = mount(WorkspaceSwitcher);

        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('renders nothing when the user has no memberships', () => {
        setPage([], null, true);
        const wrapper = mount(WorkspaceSwitcher);

        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('renders a real link for a single workspace even when the user is already inside it', () => {
        // A disabled "you're already here" badge was confusing — clicks on
        // the navbar chip now always navigate somewhere.
        setPage([{ id: 1, slug: 'acme', name: 'Acme' }], { id: 1, slug: 'acme', name: 'Acme' });
        const wrapper = mount(WorkspaceSwitcher);

        expect(wrapper.find('button').exists()).toBe(false);
        const link = wrapper.get('a');
        expect(link.attributes('href')).toBe('/w/acme/dashboard');
        expect(link.text()).toContain('Acme');
    });

    it('renders a link to the sole membership when no workspace is active yet', () => {
        setPage([{ id: 1, slug: 'acme', name: 'Acme' }], null);
        const wrapper = mount(WorkspaceSwitcher);

        // A link — not a disabled "Pick a workspace" button — so the welcome
        // page actually gets the user somewhere.
        expect(wrapper.find('button').exists()).toBe(false);
        const link = wrapper.get('a');
        expect(link.attributes('href')).toBe('/w/acme/dashboard');
        expect(link.text()).toContain('Acme');
    });

    it('opens the dropdown when there are multiple workspaces', async () => {
        setPage(
            [
                { id: 1, slug: 'acme', name: 'Acme' },
                { id: 2, slug: 'widgets', name: 'Widgets' },
            ],
            { id: 1, slug: 'acme', name: 'Acme' },
        );

        const wrapper = mount(WorkspaceSwitcher);
        await wrapper.get('button').trigger('click');

        expect(wrapper.text()).toContain('Widgets');
    });

    it('links each item to the workspace dashboard', async () => {
        setPage(
            [
                { id: 1, slug: 'acme', name: 'Acme' },
                { id: 2, slug: 'widgets', name: 'Widgets' },
            ],
            { id: 1, slug: 'acme', name: 'Acme' },
        );

        const wrapper = mount(WorkspaceSwitcher);
        await wrapper.get('button').trigger('click');

        const links = wrapper.findAll('a').map((a) => a.attributes('href'));
        expect(links).toContain('/w/acme/dashboard');
        expect(links).toContain('/w/widgets/dashboard');
    });
});
