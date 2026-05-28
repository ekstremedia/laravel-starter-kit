import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import { router } from '@inertiajs/vue3';

vi.mock('@/composables/useTweaks', () => ({
    useTweaks: () => ({ setTheme: vi.fn(), setAccent: vi.fn(), setDensity: vi.fn() }),
}));
vi.mock('@/composables/useCommandToasts', () => ({
    useCommandToasts: () => ({ push: vi.fn() }),
}));

import CommandPalette from '@/Components/Command/CommandPalette.vue';

describe('Command/CommandPalette', () => {
    it('renders nothing when closed', () => {
        expect(mount(CommandPalette, { props: { open: false } }).text()).toBe('');
    });

    it('renders the search input and base navigation commands when open', () => {
        const w = mount(CommandPalette, { props: { open: true } });
        expect(w.find('input').exists()).toBe(true);
        expect(w.text()).toContain('palette.go_home');
        expect(w.text()).toContain('palette.go_profile');
    });

    it('filters commands by the query and reports the match count', async () => {
        const w = mount(CommandPalette, { props: { open: true } });
        await w.find('input').setValue('profile');
        expect(w.text()).toContain('palette.go_profile');
        expect(w.text()).not.toContain('palette.go_home');
        expect(w.text()).toContain('palette.matches n=1');
    });

    it('executes a command on click and emits close', async () => {
        const w = mount(CommandPalette, { props: { open: true } });
        await w.find('input').setValue('profile');
        const row = w.findAll('div').find((d) => d.text() === 'palette.go_profile');
        await row!.trigger('click');
        expect(router.visit).toHaveBeenCalledWith('/profile');
        expect(w.emitted('close')).toBeTruthy();
    });

    it('closes on Escape and on backdrop click', async () => {
        const esc = mount(CommandPalette, { props: { open: true } });
        await esc.find('input').trigger('keydown', { key: 'Escape' });
        expect(esc.emitted('close')).toBeTruthy();

        const backdrop = mount(CommandPalette, { props: { open: true } });
        await backdrop.find('div').trigger('click');
        expect(backdrop.emitted('close')).toBeTruthy();
    });
});
