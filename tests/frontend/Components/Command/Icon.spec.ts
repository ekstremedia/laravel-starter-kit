import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import Icon from '@/Components/Command/Icon.vue';

describe('Command/Icon', () => {
    it('renders an aria-hidden svg sized to the size prop', () => {
        const w = mount(Icon, { props: { name: 'home', size: 20 } });
        const svg = w.find('svg');
        expect(svg.exists()).toBe(true);
        expect(svg.attributes('width')).toBe('20');
        expect(svg.attributes('height')).toBe('20');
        expect(svg.attributes('aria-hidden')).toBe('true');
    });

    it('defaults the size to 14', () => {
        expect(mount(Icon, { props: { name: 'check' } }).find('svg').attributes('width')).toBe('14');
    });

    it('renders different geometry per icon name', () => {
        // `home` is a single path; `users` includes circles.
        expect(mount(Icon, { props: { name: 'home' } }).findAll('circle').length).toBe(0);
        expect(mount(Icon, { props: { name: 'users' } }).findAll('circle').length).toBeGreaterThan(0);
    });
});
