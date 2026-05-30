import { describe, it, expect, vi } from 'vitest';

// Record whether the (heavy) vue3-apexcharts module ever gets imported. The
// whole point of LazyChart is that it is NOT pulled into the bundle until a
// chart actually renders, so the factory must stay untouched on plain import.
const apexLoaded = vi.fn();
vi.mock('vue3-apexcharts', () => {
    apexLoaded();
    return { default: { name: 'ApexChartStub', render: () => null } };
});

describe('LazyChart', () => {
    it('does not import vue3-apexcharts just by importing the component', async () => {
        const mod = await import('@/Components/Command/LazyChart.vue');

        // The component exists…
        expect(mod.default).toBeTruthy();
        // …but ApexCharts has not been loaded — it lives behind defineAsyncComponent.
        expect(apexLoaded).not.toHaveBeenCalled();
    });
});
