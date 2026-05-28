import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import Kbd from '@/Components/Command/Kbd.vue';

describe('Command/Kbd', () => {
    it('renders slot content inside a <kbd>', () => {
        const w = mount(Kbd, { slots: { default: '⌘K' } });
        expect(w.find('kbd').exists()).toBe(true);
        expect(w.text()).toBe('⌘K');
    });
});
