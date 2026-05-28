import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import Skeleton from '@/Components/Command/Skeleton.vue';

describe('Command/Skeleton', () => {
    it('coerces numeric dimensions to px', () => {
        const w = mount(Skeleton, { props: { width: 120, height: 8, radius: 2 } });
        const style = w.find('span').attributes('style');
        expect(style).toContain('width: 120px');
        expect(style).toContain('height: 8px');
        expect(style).toContain('border-radius: 2px');
    });

    it('passes string dimensions through unchanged', () => {
        const w = mount(Skeleton, { props: { width: '50%' } });
        expect(w.find('span').attributes('style')).toContain('width: 50%');
    });

    it('applies the skeleton class', () => {
        expect(mount(Skeleton).find('span').classes()).toContain('cmd-skeleton');
    });
});
