/*
 * Single source of truth for the left rail / sidebar nav.
 *
 * To add, remove, or reorder an item: edit the `entries` array below. Every
 * presentational concern (collapse state, tooltip, active-indicator, render
 * loop) lives in Rail.vue and does not need to change.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { PageProps } from '@/types';
import type { SidebarEntry } from '@/types/sidebar';

export function useSidebarItems() {
    const { t } = useI18n();
    const page = usePage<PageProps>();

    const user = computed(() => page.props.auth?.user);
    const isSuperAdmin = computed(() => user.value?.is_super_admin === true);
    const isCustomerAdmin = computed(() => (user.value?.roles ?? []).includes('Admin'));
    const tenancyEnabled = computed(() => page.props.tenancy?.enabled ?? false);
    const chatEnabled = computed(() => page.props.chat?.enabled ?? false);
    const globalFilesEnabled = computed(() => page.props.app_settings?.files_feature_enabled ?? false);
    const assetsEnabled = computed(() => page.props.assetsEnabled ?? false);
    const userPermissions = computed<string[]>(() => (user.value as { permissions?: string[] } | undefined)?.permissions ?? []);
    const canViewCompanyFiles = computed(() => isSuperAdmin.value || userPermissions.value.includes('view company files'));
    const canManageEmailTemplates = computed(() => page.props.auth?.can?.manage_email_templates === true);

    const customer = computed(() => page.props.customer);
    const customerSlug = computed(() => customer.value?.slug ?? null);

    const items = computed<SidebarEntry[]>(() => {
        // Match the personal files nav on /files but NOT /files/company so
        // the two entries highlight independently as the user moves between
        // them.
        const filesActive = (p: string) => p.startsWith('/c/') && p.includes('/files') && !p.includes('/files/company');
        const companyFilesActive = (p: string) => p.startsWith('/c/') && p.includes('/files/company');

        const entries: SidebarEntry[] = [
            { id: 'home', href: '/home', label: t('rail.home'), icon: 'user', kb: 'H', match: (p) => p === '/home' || p === '/' },
            { id: 'chat', href: '/chat', label: t('rail.chat'), icon: 'mail', match: (p) => p.startsWith('/chat'), hideWhen: () => !chatEnabled.value },
        ];

        // Customer-scope section: only meaningful inside a customer context.
        // Groups the customer's own dashboard, files, shared files, and (for
        // admins) members under a single separator so the rail visibly
        // belongs to that customer rather than scattering tenant-only links
        // among global ones.
        if (customerSlug.value) {
            const slug = customerSlug.value;
            entries.push(
                { separator: true, key: 'cust' },
                { id: 'my-dashboard', href: `/c/${slug}/dashboard`, label: t('rail.dashboard'), icon: 'home', match: (p) => p.startsWith('/c/') && p.includes('/dashboard') },
                { id: 'about', href: `/c/${slug}/about`, label: t('rail.about'), icon: 'customer', match: (p) => p.startsWith(`/c/${slug}/about`) },
                { id: 'files', href: `/c/${slug}/files`, label: t('rail.files'), icon: 'disk', match: filesActive, hideWhen: () => !globalFilesEnabled.value || !customer.value?.files_feature_enabled },
                { id: 'company-files', href: `/c/${slug}/files/company`, label: t('rail.company_files'), icon: 'customer', match: companyFilesActive, hideWhen: () => !globalFilesEnabled.value || !customer.value?.company_files_enabled || !canViewCompanyFiles.value },
                // Demo entity documents. Remove this entry (and the Assets
                // module) to drop the demo — it's the template for real
                // file-owning entities (Vehicle, Medicine, …).
                { id: 'assets', href: `/c/${slug}/assets`, label: t('rail.assets'), icon: 'box', match: (p) => p.startsWith(`/c/${slug}/assets`), hideWhen: () => !assetsEnabled.value || !canViewCompanyFiles.value },
            );

            if (isCustomerAdmin.value) {
                entries.push(
                    { id: 'members', href: `/c/${slug}/members`, label: t('rail.members'), icon: 'users', match: (p) => p.startsWith(`/c/${slug}/members`) },
                );
            }
        }

        if (isSuperAdmin.value) {
            entries.push(
                { separator: true, key: 's1' },
                { id: 'dashboard', href: '/admin', label: t('rail.admin_overview'), icon: 'home', kb: 'D', match: (p) => p === '/admin' },
                { id: 'users', href: '/admin/users', label: t('rail.users'), icon: 'users', kb: 'U', match: (p) => p.startsWith('/admin/users') },
                { id: 'customers', href: '/admin/customers', label: t('rail.customers'), icon: 'customer', match: (p) => p.startsWith('/admin/customers'), hideWhen: () => !tenancyEnabled.value },
                { id: 'roles', href: '/admin/roles', label: t('rail.roles'), icon: 'role', match: (p) => p.startsWith('/admin/roles') },
                { id: 'perms', href: '/admin/permissions', label: t('rail.permissions'), icon: 'key', match: (p) => p.startsWith('/admin/permissions') },
                { separator: true, key: 's2' },
                { id: 'settings', href: '/admin/settings', label: t('rail.app_settings'), icon: 'cog', kb: 'A', match: (p) => p === '/admin/settings' },
                { id: 'mail', href: '/admin/mail', label: t('rail.mail'), icon: 'mail', match: (p) => p.startsWith('/admin/mail') },
                { id: 'storage', href: '/admin/storage', label: t('rail.storage'), icon: 'disk', match: (p) => p.startsWith('/admin/storage') },
                { id: 'backups', href: '/admin/backups', label: t('rail.backups'), icon: 'shield', match: (p) => p.startsWith('/admin/backups') },
                { id: 'server', href: '/admin/system', label: t('rail.server'), icon: 'server', match: (p) => p.startsWith('/admin/system') || p.startsWith('/admin/health') },
                { separator: true, key: 's3' },
                { id: 'logs', href: '/admin/monitoring', label: t('rail.logs'), icon: 'log', match: (p) => p.startsWith('/admin/monitoring') || p.startsWith('/admin/activity') },
            );
        } else if (canManageEmailTemplates.value) {
            // Delegated email editors aren't super admins, so they don't get
            // the admin section — surface a single entry to the mail page.
            entries.push(
                { separator: true, key: 'mail-sep' },
                { id: 'mail', href: '/admin/mail', label: t('rail.mail'), icon: 'mail', match: (p) => p.startsWith('/admin/mail') },
            );
        }

        return entries;
    });

    const visible = computed<SidebarEntry[]>(() =>
        items.value.filter((entry) => ('separator' in entry) ? true : !entry.hideWhen?.()),
    );

    return { items, visible };
}
