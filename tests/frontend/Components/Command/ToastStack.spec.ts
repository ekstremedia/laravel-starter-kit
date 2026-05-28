import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';

// Feed the component a fixed set of toasts via the composable it reads from.
vi.mock('@/composables/useCommandToasts', () => ({
    useCommandToasts: () => ({
        toasts: [
            { id: 1, msg: 'Saved', tone: 'success' },
            { id: 2, msg: 'Oops', tone: 'danger' },
        ],
    }),
}));

import ToastStack from '@/Components/Command/ToastStack.vue';

describe('Command/ToastStack', () => {
    it('renders a pill per toast with its message', () => {
        const w = mount(ToastStack);
        expect(w.text()).toContain('Saved');
        expect(w.text()).toContain('Oops');
    });

    it('renders one leading dot per toast', () => {
        const w = mount(ToastStack);
        expect(w.findAll('span[aria-hidden="true"]').length).toBe(2);
    });
});
