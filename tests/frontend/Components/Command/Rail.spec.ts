import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const m = vi.hoisted(() => ({ toggleRail: vi.fn(), url: { value: '/home' } }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        url: m.url.value,
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
    // The real composable returns computed refs; Rail reads `.value` to pick the
    // mode-appropriate list, so the mock exposes ref-like { value } objects. App
    // mode and admin mode each get their own list, mirroring the two-mode rail.
    useSidebarItems: () => ({
        appVisible: {
            value: [
                { id: 'home', href: '/home', label: 'Home', icon: 'home', match: (p: string) => p === '/home' },
                { separator: true, key: 'workspace', label: 'Workspace' },
                { id: 'my-dashboard', href: '/w/acme/dashboard', label: 'Dashboard', icon: 'home', match: (p: string) => p.includes('/dashboard') },
            ],
        },
        adminVisible: {
            value: [
                { separator: true, key: 'access', label: 'Access' },
                { id: 'users', href: '/admin/users', label: 'Users', icon: 'users', match: (p: string) => p.startsWith('/admin/users') },
            ],
        },
    }),
}));

import Rail from '@/Components/Command/Rail.vue';

beforeEach(() => {
    m.url.value = '/home';
    m.toggleRail.mockClear();
});

describe('Command/Rail — app mode', () => {
    it('renders a navigation landmark', () => {
        expect(mount(Rail).find('[role="navigation"]').exists()).toBe(true);
    });

    it('renders the app items plus brand and profile, and no admin items', () => {
        const hrefs = mount(Rail).findAll('a').map((a) => a.attributes('href'));
        expect(hrefs).toContain('/home');
        expect(hrefs).toContain('/w/acme/dashboard');
        expect(hrefs).toContain('/profile');
        expect(hrefs).not.toContain('/admin/users');
    });

    it('shows item labels and the user name when expanded', () => {
        const text = mount(Rail).text();
        expect(text).toContain('Home');
        expect(text).toContain('Dashboard');
        expect(text).toContain('Ada Lovelace');
    });

    it('toggles the rail from the collapse button', async () => {
        const w = mount(Rail);
        await w.find('button[aria-label="rail.collapse"]').trigger('click');
        expect(m.toggleRail).toHaveBeenCalled();
    });
});

describe('Command/Rail — admin mode', () => {
    beforeEach(() => {
        m.url.value = '/admin/users';
    });

    it('renders the admin items and a back-to-app link, and drops the app items', () => {
        const hrefs = mount(Rail).findAll('a').map((a) => a.attributes('href'));
        expect(hrefs).toContain('/admin/users');
        // Back-to-app link points at /home.
        expect(hrefs).toContain('/home');
        // App workspace items are gone in admin mode.
        expect(hrefs).not.toContain('/w/acme/dashboard');
    });

    it('shows the Administration brand label and the back-to-app label when expanded', () => {
        const text = mount(Rail).text();
        expect(text).toContain('rail.administration');
        expect(text).toContain('rail.back_to_app');
        expect(text).toContain('Users');
    });
});

describe('Command/Rail — mobile drawer', () => {
    const stubMatchMedia = (matches: boolean) => {
        window.matchMedia = vi.fn().mockImplementation((media: string) => ({
            matches,
            media,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            addListener: vi.fn(),
            removeListener: vi.fn(),
            dispatchEvent: vi.fn(),
            onchange: null,
        }));
    };

    beforeEach(() => {
        m.url.value = '/home';
        stubMatchMedia(true); // narrow viewport
    });

    afterEach(() => {
        delete (window as unknown as { matchMedia?: unknown }).matchMedia;
    });

    it('hides the pin toggle and shows full labels in drawer mode', async () => {
        const w = mount(Rail, { props: { mobileOpen: true } });
        await w.vm.$nextTick(); // let the onMounted matchMedia sync flush
        expect(w.find('button[aria-label="rail.collapse"]').exists()).toBe(false);
        // Labels are always shown in the drawer regardless of the pinned setting.
        expect(w.text()).toContain('Home');
        expect(w.text()).toContain('Dashboard');
    });

    it('renders the backdrop only when open and emits close on tap', async () => {
        const open = mount(Rail, { props: { mobileOpen: true } });
        await open.vm.$nextTick();
        const backdrop = open.find('.cmd-rail-backdrop');
        expect(backdrop.exists()).toBe(true);
        await backdrop.trigger('click');
        expect(open.emitted('close')).toBeTruthy();

        const closed = mount(Rail, { props: { mobileOpen: false } });
        await closed.vm.$nextTick();
        expect(closed.find('.cmd-rail-backdrop').exists()).toBe(false);
    });
});
