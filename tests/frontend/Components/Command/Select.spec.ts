import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import Select from '@/Components/Command/Select.vue';

const options = [
    { value: 'a', label: 'Alpha' },
    { value: 'b', label: 'Beta' },
];

describe('Command/Select', () => {
    it('renders the label and all options', () => {
        const w = mount(Select, { props: { modelValue: 'a', options, label: 'Letter' } });
        expect(w.find('label').text()).toBe('Letter');
        expect(w.findAll('option').map((o) => o.text())).toEqual(['Alpha', 'Beta']);
    });

    it('renders a disabled placeholder when provided', () => {
        const w = mount(Select, { props: { modelValue: '', options, placeholder: 'Pick one' } });
        const first = w.findAll('option')[0];
        expect(first.text()).toBe('Pick one');
        expect(first.attributes('disabled')).toBeDefined();
    });

    it('emits the selected value', async () => {
        const w = mount(Select, { props: { modelValue: 'a', options } });
        await w.find('select').setValue('b');
        expect(w.emitted('update:modelValue')?.[0]).toEqual(['b']);
    });

    it('coerces to a number when options are numeric', async () => {
        const numeric = [{ value: 1, label: 'One' }, { value: 2, label: 'Two' }];
        const w = mount(Select, { props: { modelValue: 1, options: numeric } });
        await w.find('select').setValue('2');
        expect(w.emitted('update:modelValue')?.[0]).toEqual([2]);
    });

    it('shows the error and marks the select invalid', () => {
        const w = mount(Select, { props: { modelValue: 'a', options, error: 'Bad' } });
        expect(w.text()).toContain('Bad');
        expect(w.find('select').attributes('aria-invalid')).toBe('true');
    });
});
