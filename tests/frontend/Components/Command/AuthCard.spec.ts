import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import AuthCard from '@/Components/Command/AuthCard.vue';

describe('Command/AuthCard', () => {
    it('renders the title, eyebrow and subtitle', () => {
        const w = mount(AuthCard, { props: { eyebrow: 'WELCOME', title: 'Sign in', subtitle: 'Continue' } });
        expect(w.find('h1').text()).toBe('Sign in');
        expect(w.text()).toContain('WELCOME');
        expect(w.text()).toContain('Continue');
    });

    it('omits the eyebrow and subtitle paragraph when not provided', () => {
        const w = mount(AuthCard, { props: { title: 'Sign in' } });
        expect(w.text()).not.toContain('WELCOME');
        expect(w.find('p').exists()).toBe(false);
    });

    it('renders the default slot (form area)', () => {
        const w = mount(AuthCard, { props: { title: 't' }, slots: { default: '<form data-testid="f" />' } });
        expect(w.find('[data-testid="f"]').exists()).toBe(true);
    });
});
