import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import Toggle from '@/Components/Command/Toggle.vue';

describe('Command/Toggle', () => {
    it('reflects modelValue via aria-checked', () => {
        expect(mount(Toggle, { props: { modelValue: true } }).find('[role="switch"]').attributes('aria-checked')).toBe('true');
        expect(mount(Toggle, { props: { modelValue: false } }).find('[role="switch"]').attributes('aria-checked')).toBe('false');
    });

    it('emits the toggled value on click', async () => {
        const w = mount(Toggle, { props: { modelValue: false } });
        await w.find('button').trigger('click');
        expect(w.emitted('update:modelValue')?.[0]).toEqual([true]);
    });

    it('does not emit when disabled', async () => {
        const w = mount(Toggle, { props: { modelValue: false, disabled: true } });
        await w.find('button').trigger('click');
        expect(w.emitted('update:modelValue')).toBeUndefined();
    });

    it('exposes the label as aria-label', () => {
        const w = mount(Toggle, { props: { modelValue: false, label: 'Enabled' } });
        expect(w.find('button').attributes('aria-label')).toBe('Enabled');
    });
});
