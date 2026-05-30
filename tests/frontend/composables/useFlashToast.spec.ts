import { describe, it, expect, vi, beforeEach } from 'vitest';
import { reactive, defineComponent } from 'vue';
import { mount } from '@vue/test-utils';

const toastAdd = vi.hoisted(() => vi.fn());
const pageRef = vi.hoisted(() => ({ current: null as unknown }));

vi.mock('@inertiajs/vue3', () => ({ usePage: () => pageRef.current }));
vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: toastAdd }) }));
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }));

import { useFlashToast } from '@/composables/useFlashToast';

interface Flash { success?: string; error?: string }
interface PageProps { flash: Flash; [key: string]: unknown }

function host() {
    return mount(defineComponent({ setup() { useFlashToast(); return () => null; } }));
}

describe('useFlashToast', () => {
    let page: { props: PageProps };

    beforeEach(() => {
        toastAdd.mockClear();
        page = reactive({ props: { flash: {} } });
        pageRef.current = page;
    });

    it('toasts once when a success message arrives', async () => {
        const w = host();
        page.props.flash.success = 'Created';
        await w.vm.$nextTick();
        expect(toastAdd).toHaveBeenCalledTimes(1);
        expect(toastAdd).toHaveBeenCalledWith(expect.objectContaining({ severity: 'success', detail: 'Created' }));
    });

    it('does NOT re-toast when an unrelated prop changes (e.g. a live partial reload)', async () => {
        const w = host();
        page.props.flash.success = 'Created';
        await w.vm.$nextTick();
        expect(toastAdd).toHaveBeenCalledTimes(1);

        // A partial reload merges new props into a fresh page.props object while
        // the (still-present) flash is carried over by reference. The old
        // object-literal watcher re-fired here and re-showed the toast; watching
        // the primitive value must not.
        page.props = { ...page.props, stats: { total: 5 } };
        await w.vm.$nextTick();
        expect(toastAdd).toHaveBeenCalledTimes(1);
    });

    it('toasts again only when the message actually changes', async () => {
        const w = host();
        page.props.flash.success = 'First';
        await w.vm.$nextTick();
        page.props.flash = { success: 'Second' };
        await w.vm.$nextTick();
        expect(toastAdd).toHaveBeenCalledTimes(2);
    });
});
