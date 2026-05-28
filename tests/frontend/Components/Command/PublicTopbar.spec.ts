import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// Drive auth state per-test through a module-level holder.
const state = vi.hoisted(() => ({ user: null as null | Record<string, unknown> }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: state.user }, app_settings: {} } }),
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import PublicTopbar from '@/Components/Command/PublicTopbar.vue';

describe('Command/PublicTopbar', () => {
    beforeEach(() => { state.user = null; });

    it('shows login and register links for guests', () => {
        const w = mount(PublicTopbar);
        const hrefs = w.findAll('a').map((a) => a.attributes('href'));
        expect(hrefs).toContain('/login');
        expect(hrefs).toContain('/register');
        expect(hrefs).not.toContain('/home');
    });

    it('shows a user pill with initials for authenticated visitors', () => {
        state.user = { first_name: 'Ada', last_name: 'Lovelace', full_name: 'Ada Lovelace' };
        const w = mount(PublicTopbar);
        const hrefs = w.findAll('a').map((a) => a.attributes('href'));
        expect(hrefs).toContain('/home');
        expect(hrefs).not.toContain('/login');
        expect(w.text()).toContain('AL');
        expect(w.text()).toContain('Ada Lovelace');
    });
});
