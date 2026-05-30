/*
 * Single source of truth for the left rail / sidebar nav.
 *
 * The rail has two modes, picked by route in Rail.vue:
 *   - app   → `appItems` / `appVisible`: Home, Chat, and the Workspace group.
 *   - admin → `adminItems` / `adminVisible`: the platform admin groups, shown
 *             on /admin/* and reached from the topbar profile dropdown.
 *
 * The Workspace group is driven by `current_workspace` (resolved server-side on
 * every route, see HandleInertiaRequests), so it renders the same on /home as
 * inside a /w/{slug}/... route — the topbar switcher just changes which
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
    const tenancyEnabled = computed(() => page.props.workspaces?.enabled ?? false);
    const chatEnabled = computed(() => page.props.chat?.enabled ?? false);
    const globalFilesEnabled = computed(() => page.props.app_settings?.files_feature_enabled ?? false);
    // Enabled-modules map, shared from the `modules` registry (see ModuleRegistry).
    const equipmentEnabled = computed(() => page.props.modules?.equipment?.enabled ?? false);
    const equipmentCategoryEnabled = computed(() => page.props.modules?.equipment_category?.enabled ?? false);
    const canManageEmailTemplates = computed(() => page.props.auth?.can?.manage_email_templates === true);

    // The workspace the rail is scoped to. `current_workspace` is resolved on
    // every route; fall back to the workspaces-scoped `workspace` for safety.
    const workspace = computed(() => page.props.current_workspace ?? page.props.workspace ?? null);
    const workspaceSlug = computed(() => workspace.value?.slug ?? null);
    const isWorkspaceAdmin = computed(() => isSuperAdmin.value || (workspace.value as { is_admin?: boolean } | null)?.is_admin === true);
    const canViewCompanyFiles = computed(() => isSuperAdmin.value || (workspace.value as { can_view_company_files?: boolean } | null)?.can_view_company_files === true);

    // Workspace URL helper: prefix with /w/<slug> in multi-tenant mode, bare
    // path in single-tenant mode (routes mounted at root). Matches are made
    // prefix-agnostic by stripping any leading /w/<slug> before comparing, so
    // the same entry highlights correctly in both modes.
    const wsHref = (suffix: string) => (tenancyEnabled.value && workspaceSlug.value ? `/w/${workspaceSlug.value}` : '') + suffix;
    const railMatch = (test: (suffix: string) => boolean) => (p: string) => test(p.replace(/^\/w\/[^/]+/, '') || '/');

    // ── App mode ────────────────────────────────────────────────────────────
    const appItems = computed<SidebarEntry[]>(() => {
        const entries: SidebarEntry[] = [
            { id: 'home', href: '/home', label: t('rail.home'), icon: 'user', kb: 'H', match: (p) => p === '/home' || p === '/' },
            { id: 'chat', href: '/chat', label: t('rail.chat'), icon: 'mail', match: (p) => p.startsWith('/chat'), hideWhen: () => !chatEnabled.value },
        ];

        // Workspace group: the current workspace's own dashboard, files, shared
        // files, assets, and (for workspace admins) members. Always present
        // when a workspace is resolvable, so the rail is identical on /home and
        // inside the workspace.
        if (workspaceSlug.value) {
            const ws = workspace.value;
            entries.push(
                { separator: true, key: 'workspace', label: t('rail.section_workspace') },
                { id: 'my-dashboard', href: wsHref('/dashboard'), label: t('rail.dashboard'), icon: 'home', match: railMatch((s) => s.startsWith('/dashboard')) },
                { id: 'files', href: wsHref('/files'), label: t('rail.files'), icon: 'disk', match: railMatch((s) => s.startsWith('/files') && !s.startsWith('/files/company')), hideWhen: () => !globalFilesEnabled.value || !ws?.files_feature_enabled },
                { id: 'company-files', href: wsHref('/files/company'), label: t('rail.company_files'), icon: 'workspace', match: railMatch((s) => s.startsWith('/files/company')), hideWhen: () => !tenancyEnabled.value || !globalFilesEnabled.value || !ws?.company_files_enabled || !canViewCompanyFiles.value },
                // The Equipment module — the template for real file-owning
                // modules (Car, Medicine, …). Gated by the `modules` registry,
                // so toggling it off in /admin/modules hides this entry. The
                // match is anchored so it does NOT also light up on the nested
                // /equipment-categories routes below.
                { id: 'equipment', href: wsHref('/equipment'), label: t('rail.equipment'), icon: 'box', match: railMatch((s) => s === '/equipment' || s.startsWith('/equipment/')), hideWhen: () => !equipmentEnabled.value || !canViewCompanyFiles.value },
                // Categories — a sub-item nested under Equipment (the demo
                // related entity). Its own module, gated independently.
                { id: 'equipment-categories', href: wsHref('/equipment-categories'), label: t('rail.equipment_categories'), icon: 'role', indent: true, match: railMatch((s) => s.startsWith('/equipment-categories')), hideWhen: () => !equipmentCategoryEnabled.value || !canViewCompanyFiles.value },
            );

            if (isWorkspaceAdmin.value) {
                entries.push(
                    { id: 'members', href: wsHref('/members'), label: t('rail.members'), icon: 'users', match: railMatch((s) => s.startsWith('/members')) },
                    { id: 'ws-modules', href: wsHref('/settings/modules'), label: t('rail.module_settings'), icon: 'cog', match: railMatch((s) => s.startsWith('/settings/modules')) },
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
                { id: 'workspaces', href: '/admin/workspaces', label: t('rail.workspaces'), icon: 'workspace', match: (p) => p.startsWith('/admin/workspaces'), hideWhen: () => !tenancyEnabled.value },
                { id: 'roles', href: '/admin/roles', label: t('rail.roles'), icon: 'role', match: (p) => p.startsWith('/admin/roles') },
                { id: 'perms', href: '/admin/permissions', label: t('rail.permissions'), icon: 'key', match: (p) => p.startsWith('/admin/permissions') },
                { separator: true, key: 'system', label: t('rail.section_system') },
                { id: 'settings', href: '/admin/settings', label: t('rail.app_settings'), icon: 'cog', kb: 'A', match: (p) => p === '/admin/settings' },
                { id: 'modules', href: '/admin/modules', label: t('rail.modules'), icon: 'box', match: (p) => p.startsWith('/admin/modules') },
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
