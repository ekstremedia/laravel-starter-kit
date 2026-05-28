import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';

const m = vi.hoisted(() => ({ toggleRail: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        url: '/home',
        props: { auth: { user: { first_name: 'Ada', last_name: 'Lovelace', full_name: 'Ada Lovelace' } } },
    }),
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('@/composables/useTweaks', () => ({
    useTweaks: () => ({
        // Rail reads state.value.* (state is a ref in the real composable).
        state: { value: { railExpanded: true, showKbdHints: false } },
        toggleRail: m.toggleRail,
    }),
}));

vi.mock('@/composables/useSidebarItems', () => ({
    // The real composable returns a computed ref that the template auto-unwraps;
    // a plain array stands in fine for mounting.
    useSidebarItems: () => ({
        visible: [
            { id: 'home', href: '/home', label: 'Home', icon: 'home', match: (p: string) => p === '/home' },
            { separator: true, key: 's1' },
            { id: 'users', href: '/admin/users', label: 'Users', icon: 'users', match: (p: string) => p.startsWith('/admin/users') },
        ],
    }),
}));

import Rail from '@/Components/Command/Rail.vue';

describe('Command/Rail', () => {
    it('renders a navigation landmark', () => {
        expect(mount(Rail).find('[role="navigation"]').exists()).toBe(true);
    });

    it('renders a link per sidebar item plus logo and profile', () => {
        const hrefs = mount(Rail).findAll('a').map((a) => a.attributes('href'));
        expect(hrefs).toContain('/home');
        expect(hrefs).toContain('/admin/users');
        expect(hrefs).toContain('/profile');
    });

    it('shows item labels and the user name when expanded', () => {
        const text = mount(Rail).text();
        expect(text).toContain('Home');
        expect(text).toContain('Users');
        expect(text).toContain('Ada Lovelace');
    });

    it('toggles the rail from the collapse button', async () => {
        const w = mount(Rail);
        await w.find('button[aria-label="rail.collapse"]').trigger('click');
        expect(m.toggleRail).toHaveBeenCalled();
    });
});
