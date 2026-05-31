<script setup lang="ts">
/* 42 px topbar. Left: breadcrumbs. Right: command trigger, bell, user pill. */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { PageProps } from '@/types';
import Icon from './Icon.vue';
import WorkspaceSwitcher from './WorkspaceSwitcher.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import { useTweaks } from '@/composables/useTweaks';

interface Props {
    onOpenPalette: () => void;
    // Toggles the mobile nav drawer; only surfaced on narrow viewports.
    onToggleNav?: () => void;
}
defineProps<Props>();

const { t } = useI18n();
const page = usePage<PageProps>();
const user = computed(() => page.props.auth?.user);
const isSuperAdmin = computed(() => user.value?.is_super_admin === true);
// Delegated email-template editors aren't super admins, but can still reach
// the mail page — surface it for them when they don't get the full admin link.
const canManageEmailTemplates = computed(() => page.props.auth?.can?.manage_email_templates === true);
const isAdminMode = computed(() => {
    const p = page.url.split('?')[0];
    return p === '/admin' || p.startsWith('/admin/');
});

// Workspace settings link (module features, …). Mirrors the rail's resolution
// so it behaves the same everywhere, including /home: scope to `current_workspace`
// (resolved on every route), shown only to that workspace's admins.
const tenancyEnabled = computed(() => page.props.workspaces?.enabled ?? false);
const dropdownWorkspace = computed(() => page.props.current_workspace ?? page.props.workspace ?? null);
const isWorkspaceAdmin = computed(() => isSuperAdmin.value || (dropdownWorkspace.value as { is_admin?: boolean } | null)?.is_admin === true);
const showWorkspaceSettings = computed(() => !!dropdownWorkspace.value?.slug && isWorkspaceAdmin.value);
const workspaceSettingsHref = computed(() => {
    const slug = dropdownWorkspace.value?.slug;
    return (tenancyEnabled.value && slug ? `/w/${slug}` : '') + '/settings/modules';
});

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad|iPod/i.test(navigator.platform || navigator.userAgent || '');
const shortcutKey = computed(() => (isMac ? '⌘K' : 'Ctrl K'));

const initials = computed(() =>
    ((user.value?.first_name?.[0] ?? '') + (user.value?.last_name?.[0] ?? '')).toUpperCase() || '??',
);

const breadcrumbs = computed<string[]>(() => {
    const p = page.url.split('?')[0];
    const crumbs = (key: string) => t(`topbar.crumbs.${key}`).split('|');
    if (p === '/home' || p === '/') return crumbs('home');
    if (p === '/admin') return crumbs('admin_overview');
    if (p.startsWith('/admin/users')) return crumbs('admin_users');
    if (p.startsWith('/admin/workspaces')) return crumbs('admin_workspaces');
    if (p.startsWith('/admin/roles')) return crumbs('admin_roles');
    if (p.startsWith('/admin/permissions')) return crumbs('admin_permissions');
    if (p === '/admin/settings') return crumbs('admin_settings');
    if (p.startsWith('/admin/mail')) return crumbs('admin_mail');
    if (p.startsWith('/admin/storage')) return crumbs('admin_storage');
    if (p.startsWith('/admin/backups')) return crumbs('admin_backups');
    if (p.startsWith('/admin/system') || p.startsWith('/admin/health')) return crumbs('admin_system');
    if (p.startsWith('/admin/monitoring') || p.startsWith('/admin/activity')) return crumbs('admin_monitoring');
    if (p === '/profile') return crumbs('profile');
    if (p === '/settings/notifications') return crumbs('settings_notifications');
    if (p === '/settings/tokens') return crumbs('settings_tokens');
    if (p.startsWith('/settings')) return crumbs('settings');
    if (p.startsWith('/chat')) return crumbs('chat');
    if (p.startsWith('/notifications')) return crumbs('notifications');
    const workspaceMatch = p.match(/^\/w\/([^/]+)(?:\/(.+))?$/);
    if (workspaceMatch) {
        const section = workspaceMatch[2] ?? '';
        const workspace = page.props.workspace?.name ?? workspaceMatch[1];
        if (section.startsWith('dashboard')) return [workspace, t('topbar.workspace.dashboard')];
        if (section.startsWith('files')) return [workspace, t('topbar.workspace.files')];
        if (section.startsWith('profile')) return [workspace, t('topbar.workspace.profile')];
        if (section.startsWith('notifications')) return [workspace, t('topbar.workspace.notifications')];
        if (section.startsWith('settings')) return [workspace, t('topbar.workspace.settings')];
        if (section) return [workspace, section];
        return [workspace];
    }
    if (p === '/app') return crumbs('home');
    return [];
});

function logout() {
    router.post('/logout');
}

const menuOpen = ref(false);
const menuRef = ref<HTMLElement | null>(null);
const { state: tweaks, setTheme } = useTweaks();

function toggleTheme() {
    setTheme(tweaks.value.theme === 'light' ? 'dark' : 'light');
    menuOpen.value = false;
}

function onDocClick(e: MouseEvent) {
    if (!menuRef.value) return;
    if (!menuRef.value.contains(e.target as Node)) menuOpen.value = false;
}
onMounted(() => document.addEventListener('click', onDocClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocClick));
</script>

<template>
    <div
        :style="{
            height: 'var(--topbar-h)',
            borderBottom: '1px solid var(--border)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            padding: '0 16px',
            background: 'var(--bg)',
            flexShrink: 0,
            position: 'sticky',
            top: 0,
            zIndex: 20,
        }"
    >
        <div
            :style="{ display: 'flex', alignItems: 'center', gap: '8px', fontSize: '12px', minWidth: 0, overflow: 'hidden', whiteSpace: 'nowrap', flexShrink: 1 }"
        >
            <button
                v-if="onToggleNav"
                type="button"
                class="cmd-topbar-only-sm cmd-topbar-burger"
                @click="onToggleNav"
                :aria-label="t('topbar.open_nav')"
            >
                <Icon name="menu" :size="16" />
            </button>
            <template v-for="(b, i) in breadcrumbs" :key="i">
                <Icon v-if="i > 0" name="chevR" :size="10" :style="{ color: 'var(--fg-mute)', flexShrink: 0 }" />
                <span
                    :style="{
                        color: i === breadcrumbs.length - 1 ? 'var(--fg)' : 'var(--fg-dim)',
                        fontWeight: i === breadcrumbs.length - 1 ? 500 : 400,
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                    }"
                >{{ b }}</span>
            </template>
        </div>

        <div :style="{ display: 'flex', alignItems: 'center', gap: '6px', flexShrink: 0 }">
            <button
                type="button"
                @click="onOpenPalette"
                :aria-label="t('topbar.search_prompt')"
                :style="{
                    background: 'var(--panel2)',
                    border: '1px solid var(--border)',
                    borderRadius: '5px',
                    padding: '5px 10px',
                    color: 'var(--fg-dim)',
                    fontSize: '11px',
                    cursor: 'pointer',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '8px',
                    fontFamily: 'inherit',
                    flexShrink: 0,
                }"
            >
                <Icon name="search" :size="12" />
                <span class="cmd-topbar-hide-sm">{{ t('topbar.search_prompt') }}</span>
                <kbd
                    class="cmd-mono cmd-topbar-hide-sm"
                    :style="{
                        fontSize: '9.5px',
                        padding: '1px 5px',
                        border: '1px solid var(--border)',
                        borderRadius: '3px',
                    }"
                >{{ shortcutKey }}</kbd>
            </button>

            <WorkspaceSwitcher v-if="!isAdminMode" />

            <NotificationBell />

            <div ref="menuRef" :style="{ position: 'relative' }">
                <button
                    type="button"
                    @click="menuOpen = !menuOpen"
                    :style="{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '7px',
                        padding: '3px 9px 3px 3px',
                        borderRadius: '5px',
                        background: menuOpen ? 'var(--accent-soft)' : 'var(--panel2)',
                        border: `1px solid ${menuOpen ? 'var(--accent-border)' : 'var(--border)'}`,
                        cursor: 'pointer',
                        fontFamily: 'inherit',
                        transition: 'background 0.12s, border-color 0.12s',
                    }"
                >
                    <span
                        :style="{
                            width: '20px',
                            height: '20px',
                            borderRadius: '3px',
                            background: 'var(--accent)',
                            color: '#fff',
                            fontWeight: 700,
                            fontSize: '9.5px',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            fontFamily: 'var(--font-mono)',
                        }"
                    >{{ initials }}</span>
                    <span class="cmd-topbar-hide-sm" :style="{ fontSize: '11.5px', color: 'var(--fg)' }">{{ user?.full_name ?? '' }}</span>
                    <Icon name="chevD" :size="10" :style="{ color: 'var(--fg-mute)' }" />
                </button>

                <div
                    v-if="menuOpen"
                    :style="{
                        position: 'absolute',
                        right: 0,
                        top: 'calc(100% + 6px)',
                        width: '240px',
                        background: 'var(--panel)',
                        border: '1px solid var(--border)',
                        borderRadius: '6px',
                        boxShadow: 'var(--shadow-palette)',
                        zIndex: 50,
                        overflow: 'hidden',
                        animation: 'cmdFadeIn 0.12s ease-out',
                    }"
                >
                    <div
                        :style="{
                            padding: '12px 14px',
                            borderBottom: '1px solid var(--border)',
                        }"
                    >
                        <div :style="{ fontSize: '12.5px', fontWeight: 500, color: 'var(--fg)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">
                            {{ user?.full_name ?? '' }}
                        </div>
                        <div class="cmd-mono" :style="{ fontSize: '10.5px', color: 'var(--fg-dim)', marginTop: '2px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">
                            {{ user?.email ?? '' }}
                        </div>
                    </div>
                    <div :style="{ padding: '4px' }">
                        <Link
                            href="/home"
                            @click="menuOpen = false"
                            class="cmd-menu-item"
                        >
                            <Icon name="user" :size="12" />
                            <span>{{ t('topbar.menu.home') }}</span>
                        </Link>
                        <Link
                            href="/profile"
                            @click="menuOpen = false"
                            class="cmd-menu-item"
                        >
                            <Icon name="user" :size="12" />
                            <span>{{ t('topbar.menu.profile') }}</span>
                        </Link>
                        <Link
                            href="/settings/notifications"
                            @click="menuOpen = false"
                            class="cmd-menu-item"
                        >
                            <Icon name="bell" :size="12" />
                            <span>{{ t('topbar.menu.notification_settings') }}</span>
                        </Link>
                        <Link
                            href="/settings/tokens"
                            @click="menuOpen = false"
                            class="cmd-menu-item"
                        >
                            <Icon name="key" :size="12" />
                            <span>{{ t('topbar.menu.api_tokens') }}</span>
                        </Link>
                    </div>
                    <!-- Workspace-scoped settings: its own group, headed by the
                         workspace name so it reads as distinct from the
                         platform-wide Administration below. -->
                    <div
                        v-if="showWorkspaceSettings"
                        :style="{ padding: '4px', borderTop: '1px solid var(--border)' }"
                    >
                        <div
                            :style="{ padding: '6px 10px 3px', fontSize: '9.5px', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--fg-mute)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }"
                        >
                            {{ dropdownWorkspace?.name }}
                        </div>
                        <Link
                            :href="workspaceSettingsHref"
                            @click="menuOpen = false"
                            class="cmd-menu-item"
                        >
                            <Icon name="cog" :size="12" />
                            <span>{{ t('topbar.menu.workspace_settings') }}</span>
                        </Link>
                    </div>
                    <div
                        v-if="isSuperAdmin || canManageEmailTemplates"
                        :style="{ padding: '4px', borderTop: '1px solid var(--border)' }"
                    >
                        <Link
                            v-if="isSuperAdmin"
                            href="/admin"
                            @click="menuOpen = false"
                            class="cmd-menu-item"
                        >
                            <Icon name="shield" :size="12" />
                            <span>{{ t('topbar.menu.administration') }}</span>
                        </Link>
                        <Link
                            v-else
                            href="/admin/mail"
                            @click="menuOpen = false"
                            class="cmd-menu-item"
                        >
                            <Icon name="mail" :size="12" />
                            <span>{{ t('topbar.menu.email_templates') }}</span>
                        </Link>
                    </div>
                    <div :style="{ padding: '4px', borderTop: '1px solid var(--border)' }">
                        <button
                            type="button"
                            @click="toggleTheme"
                            class="cmd-menu-item"
                        >
                            <Icon name="cog" :size="12" />
                            <span>{{ tweaks.theme === 'light' ? t('topbar.menu.theme_dark') : t('topbar.menu.theme_light') }}</span>
                        </button>
                    </div>
                    <div :style="{ padding: '4px', borderTop: '1px solid var(--border)' }">
                        <button
                            type="button"
                            @click="logout"
                            class="cmd-menu-item is-danger"
                        >
                            <Icon name="arrow" :size="12" :style="{ transform: 'rotate(180deg)' }" />
                            <span>{{ t('topbar.menu.logout') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Collapse non-essential topbar labels on narrow screens so the bar never
 * overflows: the search button becomes icon-only and the user pill shows just
 * the avatar + chevron. Breadcrumbs truncate via ellipsis (inline styles). */
@media (max-width: 640px) {
    .cmd-topbar-hide-sm {
        display: none;
    }
}
/* Hamburger: hidden on wide screens (rail is in-flow), shown when the rail
 * collapses to an off-canvas drawer. */
.cmd-topbar-burger {
    display: none;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    flex-shrink: 0;
    margin-right: 2px;
    border: 1px solid var(--border);
    border-radius: 5px;
    background: var(--panel2);
    color: var(--fg-dim);
    cursor: pointer;
    font-family: inherit;
    transition: background 0.12s, color 0.12s;
}
.cmd-topbar-burger:hover {
    color: var(--fg);
}
@media (max-width: 640px) {
    .cmd-topbar-only-sm {
        display: inline-flex;
    }
}
.cmd-menu-item {
    display: flex;
    align-items: center;
    gap: 9px;
    width: 100%;
    padding: 7px 10px;
    border-radius: 4px;
    background: transparent;
    border: none;
    cursor: pointer;
    font-family: inherit;
    font-size: 12px;
    color: var(--fg-dim);
    text-decoration: none;
    text-align: left;
    transition: background 0.1s, color 0.1s;
}
.cmd-menu-item:hover {
    background: var(--row-hover);
    color: var(--fg);
}
.cmd-menu-item.is-danger {
    color: var(--danger);
}
.cmd-menu-item.is-danger:hover {
    background: rgba(255,138,138,0.08);
    color: var(--danger);
}
</style>
