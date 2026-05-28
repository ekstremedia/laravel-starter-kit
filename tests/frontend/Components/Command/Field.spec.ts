import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import Field from '@/Components/Command/Field.vue';

describe('Command/Field', () => {
    it('renders the label bound to the input id', () => {
        const w = mount(Field, { props: { modelValue: '', label: 'Email', id: 'email' } });
        expect(w.find('label').text()).toBe('Email');
        expect(w.find('label').attributes('for')).toBe('email');
        expect(w.find('input').attributes('id')).toBe('email');
    });

    it('reflects the modelValue', () => {
        const w = mount(Field, { props: { modelValue: 'hello' } });
        expect((w.find('input').element as HTMLInputElement).value).toBe('hello');
    });

    it('emits update:modelValue on input', async () => {
        const w = mount(Field, { props: { modelValue: '' } });
        await w.find('input').setValue('typed');
        expect(w.emitted('update:modelValue')?.[0]).toEqual(['typed']);
    });

    it('coerces to a number for type=number', async () => {
        const w = mount(Field, { props: { modelValue: 0, type: 'number' } });
        await w.find('input').setValue('42');
        expect(w.emitted('update:modelValue')?.[0]).toEqual([42]);
    });

    it('shows the error and marks the input invalid', () => {
        const w = mount(Field, { props: { modelValue: '', error: 'Required' } });
        expect(w.text()).toContain('Required');
        expect(w.find('input').attributes('aria-invalid')).toBe('true');
    });
});
