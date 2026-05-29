import { describe, it, expect, vi, afterEach } from 'vitest';
import { defineComponent } from 'vue';
import { mount } from '@vue/test-utils';

const pageState = { value: {} as Record<string, unknown> };

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageState.value }),
    router: {},
    Link: { name: 'Link', template: '<a><slot /></a>' },
}));

import { useSidebarItems } from '@/composables/useSidebarItems';
import { isSidebarItem } from '@/types/sidebar';

function renderWithProps(props: Record<string, unknown>) {
    pageState.value = props;
    const Host = defineComponent({
        setup() {
            const { appVisible, adminVisible } = useSidebarItems();
            return { appVisible, adminVisible };
        },
        template: '<div />',
    });
    const wrapper = mount(Host);
    return {
        get appIds() {
            return wrapper.vm.appVisible.filter(isSidebarItem).map((e) => e.id);
        },
        get adminIds() {
            return wrapper.vm.adminVisible.filter(isSidebarItem).map((e) => e.id);
        },
        get app() {
            return wrapper.vm.appVisible;
        },
    };
}

// A typical workspace payload as shared by HandleInertiaRequests::currentWorkspace().
function workspace(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        slug: 'acme',
        name: 'Acme',
        files_feature_enabled: true,
        company_files_enabled: true,
        is_admin: false,
        can_view_company_files: false,
        ...overrides,
    };
}

afterEach(() => {
    pageState.value = {};
});

describe('useSidebarItems — app rail', () => {
    it('shows only global items (no workspace entries) when the user has no workspace', () => {
        const w = renderWithProps({
            auth: { user: {} },
            chat: { enabled: false },
            tenancy: { enabled: false },
            app_settings: { files_feature_enabled: true },
            customer: null,
            current_customer: null,
            available_customers: [],
        });
        expect(w.appIds).toContain('home');
        expect(w.appIds).not.toContain('chat');
        expect(w.appIds).not.toContain('my-dashboard');
        expect(w.appIds).not.toContain('files');
    });

    it('shows chat only when enabled', () => {
        const w = renderWithProps({
            auth: { user: {} },
            chat: { enabled: true },
            tenancy: { enabled: false },
            app_settings: { files_feature_enabled: false },
            customer: null,
            current_customer: null,
            available_customers: [],
        });
        expect(w.appIds).toContain('chat');
    });

    it('renders the workspace section on a central route once current_customer is resolved', () => {
        const w = renderWithProps({
            auth: { user: {} },
            chat: { enabled: false },
            tenancy: { enabled: true },
            app_settings: { files_feature_enabled: true },
            // central route: tenancy not initialised, but a workspace is resolved
            customer: null,
            current_customer: workspace(),
            available_customers: [{ id: 1, slug: 'acme', name: 'Acme' }],
        });
        expect(w.appIds).toEqual(expect.arrayContaining(['home', 'my-dashboard', 'about', 'files']));
        const dash = w.app.filter(isSidebarItem).find((e) => e.id === 'my-dashboard');
        expect(dash?.href).toBe('/c/acme/dashboard');
    });

    it('renders an identical app rail on /home and inside the workspace', () => {
        const onHome = renderWithProps({
            auth: { user: {} },
            chat: { enabled: false },
            tenancy: { enabled: true },
            app_settings: { files_feature_enabled: true },
            assetsEnabled: true,
            customer: null, // /home → no tenancy-scoped customer
            current_customer: workspace({ is_admin: true, can_view_company_files: true }),
            available_customers: [{ id: 1, slug: 'acme', name: 'Acme' }],
        });
        const inWorkspace = renderWithProps({
            auth: { user: {} },
            chat: { enabled: false },
            tenancy: { enabled: true },
            app_settings: { files_feature_enabled: true },
            assetsEnabled: true,
            customer: workspace({ is_admin: true, can_view_company_files: true }), // /c/acme/... → set
            current_customer: workspace({ is_admin: true, can_view_company_files: true }),
            available_customers: [{ id: 1, slug: 'acme', name: 'Acme' }],
        });
        expect(onHome.appIds).toEqual(inWorkspace.appIds);
        expect(onHome.appIds).toEqual(expect.arrayContaining(['home', 'my-dashboard', 'files', 'company-files', 'assets', 'members']));
    });

    it('hides files when the global flag is off even when the workspace has them on', () => {
        const w = renderWithProps({
            auth: { user: {} },
            chat: { enabled: false },
            tenancy: { enabled: true },
            app_settings: { files_feature_enabled: false },
            customer: null,
            current_customer: workspace({ company_files_enabled: true, can_view_company_files: true }),
            available_customers: [{ id: 1, slug: 'acme', name: 'Acme' }],
        });
        expect(w.appIds).not.toContain('files');
        expect(w.appIds).not.toContain('company-files');
    });

    it('gates Shared files on the workspace-scoped can_view_company_files flag', () => {
        const withPerm = renderWithProps({
            auth: { user: {} },
            chat: { enabled: false },
            tenancy: { enabled: true },
            app_settings: { files_feature_enabled: true },
            customer: null,
            current_customer: workspace({ files_feature_enabled: false, company_files_enabled: true, can_view_company_files: true }),
            available_customers: [{ id: 1, slug: 'acme', name: 'Acme' }],
        });
        const shared = withPerm.app.filter(isSidebarItem).find((e) => e.id === 'company-files');
        expect(shared?.href).toBe('/c/acme/files/company');

        const withoutPerm = renderWithProps({
            auth: { user: {} },
            chat: { enabled: false },
            tenancy: { enabled: true },
            app_settings: { files_feature_enabled: true },
            customer: null,
            current_customer: workspace({ files_feature_enabled: false, company_files_enabled: true, can_view_company_files: false }),
            available_customers: [{ id: 1, slug: 'acme', name: 'Acme' }],
        });
        expect(withoutPerm.appIds).not.toContain('company-files');
    });

    it('shows the Members link only for a workspace admin', () => {
        const asAdmin = renderWithProps({
            auth: { user: {} },
            chat: { enabled: false },
            tenancy: { enabled: true },
            app_settings: { files_feature_enabled: false },
            customer: null,
            current_customer: workspace({ is_admin: true }),
            available_customers: [{ id: 1, slug: 'acme', name: 'Acme' }],
        });
        expect(asAdmin.appIds).toContain('members');

        const asMember = renderWithProps({
            auth: { user: {} },
            chat: { enabled: false },
            tenancy: { enabled: true },
            app_settings: { files_feature_enabled: false },
            customer: null,
            current_customer: workspace({ is_admin: false }),
            available_customers: [{ id: 1, slug: 'acme', name: 'Acme' }],
        });
        expect(asMember.appIds).not.toContain('members');
    });

    it('never includes admin entries in the app rail, even for super admins', () => {
        const w = renderWithProps({
            auth: { user: { is_super_admin: true } },
            chat: { enabled: false },
            tenancy: { enabled: true },
            app_settings: { files_feature_enabled: false },
            customer: null,
            current_customer: workspace({ is_admin: true, can_view_company_files: true }),
            available_customers: [{ id: 1, slug: 'acme', name: 'Acme' }],
        });
        expect(w.appIds).not.toContain('users');
        expect(w.appIds).not.toContain('settings');
    });
});

describe('useSidebarItems — admin rail', () => {
    it('exposes the full admin groups only for super admins', () => {
        const superAdmin = renderWithProps({
            auth: { user: { is_super_admin: true } },
            chat: { enabled: false },
            tenancy: { enabled: true },
            app_settings: { files_feature_enabled: false },
            customer: null,
            current_customer: null,
            available_customers: [],
        });
        expect(superAdmin.adminIds).toEqual(
            expect.arrayContaining(['dashboard', 'users', 'customers', 'roles', 'perms', 'settings', 'mail', 'storage', 'server', 'logs']),
        );

        const plain = renderWithProps({
            auth: { user: { is_super_admin: false } },
            chat: { enabled: false },
            tenancy: { enabled: true },
            app_settings: { files_feature_enabled: false },
            customer: null,
            current_customer: null,
            available_customers: [],
        });
        expect(plain.adminIds).toEqual([]);
    });

    it('hides the Customers admin entry when tenancy is disabled', () => {
        const w = renderWithProps({
            auth: { user: { is_super_admin: true } },
            chat: { enabled: false },
            tenancy: { enabled: false },
            app_settings: { files_feature_enabled: false },
            customer: null,
            current_customer: null,
            available_customers: [],
        });
        expect(w.adminIds).not.toContain('customers');
    });

    it('gives a delegated email-template editor an admin rail with just Mail', () => {
        const w = renderWithProps({
            auth: { user: { is_super_admin: false }, can: { manage_email_templates: true } },
            chat: { enabled: false },
            tenancy: { enabled: true },
            app_settings: { files_feature_enabled: false },
            customer: null,
            current_customer: null,
            available_customers: [],
        });
        expect(w.adminIds).toEqual(['mail']);
        expect(w.appIds).not.toContain('mail');
    });
});
