/*
 * Single source of truth for the left rail / sidebar nav.
 *
 * The rail has two modes, picked by route in Rail.vue:
 *   - app   → `appItems` / `appVisible`: Home, Chat, and the Workspace group.
 *   - admin → `adminItems` / `adminVisible`: the platform admin groups, shown
 *             on /admin/* and reached from the topbar profile dropdown.
 *
 * The Workspace group is driven by `current_customer` (resolved server-side on
 * every route, see HandleInertiaRequests), so it renders the same on /home as
 * inside a /c/{slug}/... route — the topbar switcher just changes which
 * workspace the links target.
 *
 * To add, remove, or reorder an item: edit the arrays below. Every
 * presentational concern (collapse state, tooltip, active-indicator, section
 * labels, render loop) lives in Rail.vue and does not need to change.
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
    const tenancyEnabled = computed(() => page.props.tenancy?.enabled ?? false);
    const chatEnabled = computed(() => page.props.chat?.enabled ?? false);
    const globalFilesEnabled = computed(() => page.props.app_settings?.files_feature_enabled ?? false);
    const assetsEnabled = computed(() => page.props.assetsEnabled ?? false);
    const canManageEmailTemplates = computed(() => page.props.auth?.can?.manage_email_templates === true);

    // The workspace the rail is scoped to. `current_customer` is resolved on
    // every route; fall back to the tenancy-scoped `customer` for safety.
    const workspace = computed(() => page.props.current_customer ?? page.props.customer ?? null);
    const workspaceSlug = computed(() => workspace.value?.slug ?? null);
    const isWorkspaceAdmin = computed(() => isSuperAdmin.value || (workspace.value as { is_admin?: boolean } | null)?.is_admin === true);
    const canViewCompanyFiles = computed(() => isSuperAdmin.value || (workspace.value as { can_view_company_files?: boolean } | null)?.can_view_company_files === true);

    // ── App mode ────────────────────────────────────────────────────────────
    const appItems = computed<SidebarEntry[]>(() => {
        // Match the personal files nav on /files but NOT /files/company so the
        // two entries highlight independently as the user moves between them.
        const filesActive = (p: string) => p.startsWith('/c/') && p.includes('/files') && !p.includes('/files/company');
        const companyFilesActive = (p: string) => p.startsWith('/c/') && p.includes('/files/company');

        const entries: SidebarEntry[] = [
            { id: 'home', href: '/home', label: t('rail.home'), icon: 'user', kb: 'H', match: (p) => p === '/home' || p === '/' },
            { id: 'chat', href: '/chat', label: t('rail.chat'), icon: 'mail', match: (p) => p.startsWith('/chat'), hideWhen: () => !chatEnabled.value },
        ];

        // Workspace group: the current customer's own dashboard, files, shared
        // files, assets, and (for workspace admins) members. Always present
        // when a workspace is resolvable, so the rail is identical on /home and
        // inside the workspace.
        if (workspaceSlug.value) {
            const slug = workspaceSlug.value;
            const ws = workspace.value;
            entries.push(
                { separator: true, key: 'workspace', label: t('rail.section_workspace') },
                { id: 'my-dashboard', href: `/c/${slug}/dashboard`, label: t('rail.dashboard'), icon: 'home', match: (p) => p.startsWith('/c/') && p.includes('/dashboard') },
                { id: 'about', href: `/c/${slug}/about`, label: t('rail.about'), icon: 'customer', match: (p) => p.startsWith(`/c/${slug}/about`) },
                { id: 'files', href: `/c/${slug}/files`, label: t('rail.files'), icon: 'disk', match: filesActive, hideWhen: () => !globalFilesEnabled.value || !ws?.files_feature_enabled },
                { id: 'company-files', href: `/c/${slug}/files/company`, label: t('rail.company_files'), icon: 'customer', match: companyFilesActive, hideWhen: () => !globalFilesEnabled.value || !ws?.company_files_enabled || !canViewCompanyFiles.value },
                // Demo entity documents. Remove this entry (and the Assets
                // module) to drop the demo — it's the template for real
                // file-owning entities (Vehicle, Medicine, …).
                { id: 'assets', href: `/c/${slug}/assets`, label: t('rail.assets'), icon: 'box', match: (p) => p.startsWith(`/c/${slug}/assets`), hideWhen: () => !assetsEnabled.value || !canViewCompanyFiles.value },
            );

            if (isWorkspaceAdmin.value) {
                entries.push(
                    { id: 'members', href: `/c/${slug}/members`, label: t('rail.members'), icon: 'users', match: (p) => p.startsWith(`/c/${slug}/members`) },
                );
            }
        }

        return entries;
    });

    // ── Admin mode ───────────────────────────────────────────────────────────
    const adminItems = computed<SidebarEntry[]>(() => {
        if (isSuperAdmin.value) {
            return [
                { separator: true, key: 'access', label: t('rail.section_access') },
                { id: 'dashboard', href: '/admin', label: t('rail.admin_overview'), icon: 'home', kb: 'D', match: (p) => p === '/admin' },
                { id: 'users', href: '/admin/users', label: t('rail.users'), icon: 'users', kb: 'U', match: (p) => p.startsWith('/admin/users') },
                { id: 'customers', href: '/admin/customers', label: t('rail.customers'), icon: 'customer', match: (p) => p.startsWith('/admin/customers'), hideWhen: () => !tenancyEnabled.value },
                { id: 'roles', href: '/admin/roles', label: t('rail.roles'), icon: 'role', match: (p) => p.startsWith('/admin/roles') },
                { id: 'perms', href: '/admin/permissions', label: t('rail.permissions'), icon: 'key', match: (p) => p.startsWith('/admin/permissions') },
                { separator: true, key: 'system', label: t('rail.section_system') },
                { id: 'settings', href: '/admin/settings', label: t('rail.app_settings'), icon: 'cog', kb: 'A', match: (p) => p === '/admin/settings' },
                { id: 'mail', href: '/admin/mail', label: t('rail.mail'), icon: 'mail', match: (p) => p.startsWith('/admin/mail') },
                { id: 'storage', href: '/admin/storage', label: t('rail.storage'), icon: 'disk', match: (p) => p.startsWith('/admin/storage') },
                { id: 'backups', href: '/admin/backups', label: t('rail.backups'), icon: 'shield', match: (p) => p.startsWith('/admin/backups') },
                { id: 'server', href: '/admin/system', label: t('rail.server'), icon: 'server', match: (p) => p.startsWith('/admin/system') || p.startsWith('/admin/health') },
                { separator: true, key: 'logs', label: t('rail.section_logs') },
                { id: 'logs', href: '/admin/monitoring', label: t('rail.logs'), icon: 'log', match: (p) => p.startsWith('/admin/monitoring') || p.startsWith('/admin/activity') },
            ];
        }

        // Delegated email-template editors aren't super admins, so they get a
        // pared-down admin rail with just the mail page they can reach.
        if (canManageEmailTemplates.value) {
            return [
                { id: 'mail', href: '/admin/mail', label: t('rail.mail'), icon: 'mail', match: (p) => p.startsWith('/admin/mail') },
            ];
        }

        return [];
    });

    const filterVisible = (entries: SidebarEntry[]) =>
        entries.filter((entry) => ('separator' in entry) ? true : !entry.hideWhen?.());

    const appVisible = computed<SidebarEntry[]>(() => filterVisible(appItems.value));
    const adminVisible = computed<SidebarEntry[]>(() => filterVisible(adminItems.value));

    return { appItems, appVisible, adminItems, adminVisible };
}
