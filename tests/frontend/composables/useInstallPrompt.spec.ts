import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { useInstallPrompt } from '@/composables/useInstallPrompt';

function host() {
    return mount(
        defineComponent({
            setup() {
                return useInstallPrompt();
            },
            template: '<div />',
        }),
    );
}

describe('useInstallPrompt', () => {
    it('captures beforeinstallprompt and drives the install flow', async () => {
        const w = host();
        expect(w.vm.canInstall).toBe(false);

        const ev = new Event('beforeinstallprompt') as Event & {
            prompt: () => Promise<void>;
            userChoice: Promise<{ outcome: string }>;
        };
        ev.prompt = vi.fn().mockResolvedValue(undefined);
        ev.userChoice = Promise.resolve({ outcome: 'accepted' });
        const prevent = vi.spyOn(ev, 'preventDefault');

        window.dispatchEvent(ev);
        await w.vm.$nextTick();

        expect(prevent).toHaveBeenCalled();
        expect(w.vm.canInstall).toBe(true);

        const outcome = await w.vm.promptInstall();
        expect(ev.prompt).toHaveBeenCalled();
        expect(outcome).toBe('accepted');
        expect(w.vm.canInstall).toBe(false);
    });

    it('reports "unavailable" when no install event was captured', async () => {
        const w = host();
        expect(await w.vm.promptInstall()).toBe('unavailable');
    });
});
