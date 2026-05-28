import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';

const m = vi.hoisted(() => ({
    setTheme: vi.fn(),
    setAccent: vi.fn(),
    setDensity: vi.fn(),
    setShowKbdHints: vi.fn(),
    setLocale: vi.fn(),
}));

vi.mock('@/composables/useTweaks', () => ({
    useTweaks: () => ({
        state: { theme: 'dark', accent: 'cobalt', density: 'comfortable', showKbdHints: true },
        setTheme: m.setTheme,
        setAccent: m.setAccent,
        setDensity: m.setDensity,
        setShowKbdHints: m.setShowKbdHints,
    }),
}));
vi.mock('@/composables/useLocale', () => ({
    useLocale: () => ({
        currentLocale: 'en',
        setLocale: m.setLocale,
        locales: [
            { code: 'en', name: 'English', flag: '🇬🇧' },
            { code: 'no', name: 'Norsk', flag: '🇳🇴' },
        ],
    }),
}));

import TweaksPanel from '@/Components/Command/TweaksPanel.vue';

describe('Command/TweaksPanel', () => {
    it('renders nothing when closed', () => {
        const w = mount(TweaksPanel, { props: { open: false } });
        expect(w.text()).toBe('');
    });

    it('emits close from the × button', async () => {
        const w = mount(TweaksPanel, { props: { open: true } });
        await w.find('button[aria-label="common.close"]').trigger('click');
        expect(w.emitted('close')).toBeTruthy();
    });

    it('changes theme, accent, density and locale', async () => {
        const w = mount(TweaksPanel, { props: { open: true } });

        await w.findAll('button').find((b) => b.text() === 'tweaks.theme_hc')!.trigger('click');
        expect(m.setTheme).toHaveBeenCalledWith('hc');

        await w.find('button[title="Emerald"]').trigger('click');
        expect(m.setAccent).toHaveBeenCalledWith('emerald');

        await w.findAll('button').find((b) => b.text() === 'tweaks.density_compact')!.trigger('click');
        expect(m.setDensity).toHaveBeenCalledWith('compact');

        await w.findAll('button').find((b) => b.text().includes('Norsk'))!.trigger('click');
        expect(m.setLocale).toHaveBeenCalledWith('no');
    });

    it('toggles keyboard hints to the opposite of current state', async () => {
        const w = mount(TweaksPanel, { props: { open: true } });
        // showKbdHints is true → aria-label resolves to common.hide.
        await w.find('button[aria-label="common.hide"]').trigger('click');
        expect(m.setShowKbdHints).toHaveBeenCalledWith(false);
    });
});
