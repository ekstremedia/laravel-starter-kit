import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';

const m = vi.hoisted(() => ({ post: vi.fn(), setTheme: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        url: '/admin/users',
        props: { auth: { user: { first_name: 'Ada', last_name: 'Lovelace', full_name: 'Ada Lovelace', email: 'ada@test.dev' } }, workspace: null },
    }),
    router: { post: m.post },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('@/composables/useTweaks', () => ({
    useTweaks: () => ({ state: { value: { theme: 'dark' } }, setTheme: m.setTheme }),
}));

import Topbar from '@/Components/Command/Topbar.vue';

const mountTopbar = (onOpenPalette = vi.fn()) =>
    mount(Topbar, {
        props: { onOpenPalette },
        // Child widgets have their own specs; stub them to isolate the topbar.
        global: { stubs: { WorkspaceSwitcher: true, NotificationBell: true } },
    });

describe('Command/Topbar', () => {
    it('renders breadcrumbs for the current route', () => {
        expect(mountTopbar().text()).toContain('topbar.crumbs.admin_users');
    });

    it('invokes onOpenPalette from the command trigger', async () => {
        const onOpen = vi.fn();
        const w = mountTopbar(onOpen);
        await w.find('button[aria-label="topbar.search_prompt"]').trigger('click');
        expect(onOpen).toHaveBeenCalled();
    });

    it('opens the user menu and logs out', async () => {
        const w = mountTopbar();
        expect(w.text()).not.toContain('topbar.menu.logout');

        await w.findAll('button').find((b) => b.text().includes('Ada Lovelace'))!.trigger('click');
        expect(w.text()).toContain('topbar.menu.logout');

        await w.findAll('button').find((b) => b.text().includes('topbar.menu.logout'))!.trigger('click');
        expect(m.post).toHaveBeenCalledWith('/logout');
    });

    it('toggles the theme from the user menu', async () => {
        const w = mountTopbar();
        await w.findAll('button').find((b) => b.text().includes('Ada Lovelace'))!.trigger('click');
        await w.findAll('button').find((b) => b.text().includes('topbar.menu.theme_light'))!.trigger('click');
        expect(m.setTheme).toHaveBeenCalledWith('light');
    });
});
