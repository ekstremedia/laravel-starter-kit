import { mount } from '@vue/test-utils';
import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import LiveIndicator from '@/Components/Command/LiveIndicator.vue';
import { useRealtimeStore } from '@/stores/realtime';

describe('Command/LiveIndicator', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('renders nothing until the realtime store is bound to a connection', () => {
        const w = mount(LiveIndicator);
        expect(w.find('[role="status"]').exists()).toBe(false);
    });

    it('shows a status dot once bound, coloured by connection state', async () => {
        const w = mount(LiveIndicator);
        const store = useRealtimeStore();

        store.bound = true;
        store.setStatus('connected');
        await w.vm.$nextTick();

        const status = w.find('[role="status"]');
        expect(status.exists()).toBe(true);
        expect(status.attributes('aria-label')).toBe('topbar.live.connected');
    });
});
