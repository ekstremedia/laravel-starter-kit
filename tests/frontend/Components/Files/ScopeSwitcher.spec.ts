import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';

const customerState = vi.hoisted(() => ({
    value: { files_feature_enabled: true, company_files_enabled: true } as Record<string, boolean>,
}));

vi.mock('@/composables/useCustomer', () => ({
    useCustomer: () => ({
        customerUrl: (p: string) => `/c/acme${p}`,
        customer: customerState,
    }),
}));

import ScopeSwitcher from '@/Components/Files/ScopeSwitcher.vue';

describe('Files/ScopeSwitcher', () => {
    it('shows both tabs when shared is enabled and viewable', () => {
        customerState.value = { files_feature_enabled: true, company_files_enabled: true };
        const w = mount(ScopeSwitcher, { props: { active: 'private', permissions: { canViewShared: true } } });
        const tabs = w.findAll('[role="tab"]');
        expect(tabs.length).toBe(2);
        expect(tabs[0].attributes('aria-selected')).toBe('true');
    });

    it('hides the shared tab without view permission', () => {
        customerState.value = { files_feature_enabled: true, company_files_enabled: true };
        const w = mount(ScopeSwitcher, { props: { active: 'private', permissions: { canViewShared: false } } });
        expect(w.findAll('[role="tab"]').length).toBe(1);
    });

    it('hides the shared tab when company files are disabled', () => {
        customerState.value = { files_feature_enabled: true, company_files_enabled: false };
        const w = mount(ScopeSwitcher, { props: { active: 'private', permissions: { canViewShared: true } } });
        expect(w.findAll('[role="tab"]').length).toBe(1);
    });

    it('marks the shared tab active when scope is shared', () => {
        customerState.value = { files_feature_enabled: true, company_files_enabled: true };
        const w = mount(ScopeSwitcher, { props: { active: 'shared', permissions: { canViewShared: true } } });
        expect(w.findAll('[role="tab"]')[1].attributes('aria-selected')).toBe('true');
    });
});
