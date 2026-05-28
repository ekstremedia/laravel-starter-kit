import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { nextTick } from 'vue';
import Counter from '@/Components/Command/Counter.vue';

describe('Command/Counter', () => {
    beforeEach(() => {
        // Drive the rAF-based tween synchronously: start at t=0 and fire the
        // frame well past the duration so the eased value lands on target.
        vi.stubGlobal('performance', { now: () => 0 });
        vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => { cb(10_000); return 1; });
        vi.stubGlobal('cancelAnimationFrame', () => {});
    });
    afterEach(() => { vi.unstubAllGlobals(); });

    it('animates up to the target value', async () => {
        const w = mount(Counter, { props: { to: 42 } });
        await nextTick();
        expect(w.text()).toBe('42');
    });

    it('formats with the requested decimals', async () => {
        const w = mount(Counter, { props: { to: 3.5, decimals: 1 } });
        await nextTick();
        expect(w.text()).toBe('3.5');
    });

    it('renders 0 for a non-finite target', async () => {
        const w = mount(Counter, { props: { to: Infinity } });
        await nextTick();
        expect(w.text()).toBe('0');
    });
});
