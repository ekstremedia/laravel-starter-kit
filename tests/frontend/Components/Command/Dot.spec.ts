import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import Dot from '@/Components/Command/Dot.vue';

describe('Command/Dot', () => {
    it('renders an aria-hidden span at the default size', () => {
        const w = mount(Dot);
        const span = w.find('span');
        expect(span.attributes('aria-hidden')).toBe('true');
        expect(span.attributes('style')).toContain('width: 6px');
        expect(span.attributes('style')).toContain('height: 6px');
    });

    it('applies the color and size props', () => {
        const w = mount(Dot, { props: { color: 'rgb(255, 0, 0)', size: 12 } });
        const style = w.find('span').attributes('style');
        expect(style).toContain('width: 12px');
        expect(style).toContain('height: 12px');
        expect(style).toContain('background: rgb(255, 0, 0)');
    });
});
