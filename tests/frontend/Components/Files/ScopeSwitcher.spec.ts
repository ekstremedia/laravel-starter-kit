import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';

const workspaceState = vi.hoisted(() => ({
    value: { files_feature_enabled: true, company_files_enabled: true } as Record<string, boolean>,
}));
const tenancyState = vi.hoisted(() => ({ enabled: true }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { workspaces: { enabled: tenancyState.enabled } } }),
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('@/composables/useWorkspace', () => ({
    useWorkspace: () => ({
        workspaceUrl: (p: string) => `/w/acme${p}`,
        workspace: workspaceState,
    }),
}));

import ScopeSwitcher from '@/Components/Files/ScopeSwitcher.vue';

describe('Files/ScopeSwitcher', () => {
    it('shows both tabs when shared is enabled and viewable', () => {
        tenancyState.enabled = true;
        workspaceState.value = { files_feature_enabled: true, company_files_enabled: true };
        const w = mount(ScopeSwitcher, { props: { active: 'private', permissions: { canViewShared: true } } });
        const tabs = w.findAll('[role="tab"]');
        expect(tabs.length).toBe(2);
        expect(tabs[0].attributes('aria-selected')).toBe('true');
    });

    it('drops the entire switcher without view permission', () => {
        tenancyState.enabled = true;
        workspaceState.value = { files_feature_enabled: true, company_files_enabled: true };
        const w = mount(ScopeSwitcher, { props: { active: 'private', permissions: { canViewShared: false } } });
        expect(w.findAll('[role="tab"]').length).toBe(0);
    });

    it('drops the entire switcher when company files are disabled', () => {
        tenancyState.enabled = true;
        workspaceState.value = { files_feature_enabled: true, company_files_enabled: false };
        const w = mount(ScopeSwitcher, { props: { active: 'private', permissions: { canViewShared: true } } });
        expect(w.findAll('[role="tab"]').length).toBe(0);
    });

    it('drops the entire switcher when workspaces is disabled', () => {
        tenancyState.enabled = false;
        workspaceState.value = { files_feature_enabled: true, company_files_enabled: true };
        const w = mount(ScopeSwitcher, { props: { active: 'private', permissions: { canViewShared: true } } });
        expect(w.findAll('[role="tab"]').length).toBe(0);
    });

    it('marks the shared tab active when scope is shared', () => {
        tenancyState.enabled = true;
        workspaceState.value = { files_feature_enabled: true, company_files_enabled: true };
        const w = mount(ScopeSwitcher, { props: { active: 'shared', permissions: { canViewShared: true } } });
        expect(w.findAll('[role="tab"]')[1].attributes('aria-selected')).toBe('true');
    });
});
