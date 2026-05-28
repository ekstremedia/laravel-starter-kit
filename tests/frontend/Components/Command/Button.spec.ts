import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import Button from '@/Components/Command/Button.vue';

describe('Command/Button', () => {
    it('renders default slot content as a button', () => {
        const w = mount(Button, { slots: { default: 'Save' } });
        expect(w.text()).toContain('Save');
        expect(w.find('button').attributes('type')).toBe('button');
    });

    it('applies the variant class', () => {
        const w = mount(Button, { props: { variant: 'danger' }, slots: { default: 'Delete' } });
        expect(w.find('button').classes()).toContain('cmd-btn-danger');
    });

    it('honors the type prop', () => {
        const w = mount(Button, { props: { type: 'submit' } });
        expect(w.find('button').attributes('type')).toBe('submit');
    });

    it('is disabled when disabled or loading', () => {
        expect(mount(Button, { props: { disabled: true } }).find('button').attributes('disabled')).toBeDefined();
        expect(mount(Button, { props: { loading: true } }).find('button').attributes('disabled')).toBeDefined();
    });

    it('hides the icon slot and shows a spinner while loading', () => {
        const idle = mount(Button, { props: { loading: false }, slots: { icon: '<i data-testid="ic" />' } });
        expect(idle.find('[data-testid="ic"]').exists()).toBe(true);

        const busy = mount(Button, { props: { loading: true }, slots: { icon: '<i data-testid="ic" />' } });
        expect(busy.find('[data-testid="ic"]').exists()).toBe(false);
        expect(busy.find('span[aria-hidden="true"]').exists()).toBe(true);
    });

    it('stretches to full width when fullWidth is set', () => {
        const w = mount(Button, { props: { fullWidth: true } });
        expect(w.find('button').attributes('style')).toContain('width: 100%');
    });
});
