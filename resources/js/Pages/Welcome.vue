<script setup lang="ts">
/*
 * Public root page — guests see a marketing landing; authenticated users
 * see their name + quick CTAs to the app. Uses Command tokens directly
 * without the CommandLayout shell: guests have no rail to populate, and
 * authenticated users get clearer primary CTAs here than an empty shell.
 */
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, onMounted } from 'vue';
import { useTweaks } from '@/composables/useTweaks';
import Icon from '@/Components/Command/Icon.vue';
import Dot from '@/Components/Command/Dot.vue';
import PublicTopbar from '@/Components/Command/PublicTopbar.vue';
import type { PageProps } from '@/types';

// Apply tweaks tokens even for unauthenticated visitors so fonts/accent
// line up with the rest of the app.
useTweaks();

const { t } = useI18n();
const page = usePage<PageProps>();
const user = computed(() => page.props.auth?.user);
const isAdmin = computed(() => user.value?.is_super_admin === true);
const registrationOpen = computed(() => page.props.app_settings?.registration_open !== false);
const appName = import.meta.env.VITE_APP_NAME || t('app.name');

const primaryHref = computed(() => {
    if (user.value) return '/home';
    return registrationOpen.value ? '/register' : '/login';
});
const primaryLabel = computed(() => {
    if (user.value) return t('welcome.primary_cta_user');
    return registrationOpen.value ? t('welcome.primary_cta_guest') : t('nav.login');
});
const secondaryHref = computed(() => (user.value ? (isAdmin.value ? '/admin' : '/app') : '/login'));
const secondaryLabel = computed(() =>
    user.value ? t('welcome.secondary_cta_user') : t('welcome.secondary_cta_guest'),
);
const secondaryVisible = computed(() => {
    if (user.value) return true;
    return registrationOpen.value;
});

const chatEnabled = computed(() => page.props.chat?.enabled ?? false);
const workspaceSlug = computed(() => page.props.workspace?.slug ?? null);
const filesEnabled = computed(() => page.props.workspace?.files_feature_enabled ?? false);

interface QuickLink {
    key: string;
    href: string;
    label: string;
    hint: string;
    icon: 'home' | 'user' | 'users' | 'cog' | 'key' | 'role' | 'shield' | 'disk' | 'mail' | 'workspace' | 'server' | 'log';
}

const quickLinks = computed<QuickLink[]>(() => {
    if (!user.value) {
        return [
            { key: 'login', href: '/login', label: t('welcome.quick.login'), hint: t('welcome.quick.login_hint'), icon: 'key' },
            { key: 'register', href: '/register', label: t('welcome.quick.register'), hint: registrationOpen.value ? t('welcome.quick.register_open_hint') : t('welcome.quick.register_closed_hint'), icon: 'user' },
            { key: 'forgot', href: '/forgot-password', label: t('welcome.quick.forgot'), hint: t('welcome.quick.forgot_hint'), icon: 'mail' },
        ];
    }

    const links: QuickLink[] = [
        { key: 'home', href: '/home', label: t('welcome.quick.home'), hint: t('welcome.quick.home_hint'), icon: 'user' },
    ];

    if (workspaceSlug.value) {
        links.push({ key: 'cdash', href: `/w/${workspaceSlug.value}/dashboard`, label: t('welcome.quick.workspace_dashboard'), hint: page.props.workspace?.name ?? t('welcome.quick.workspace_dashboard_hint'), icon: 'home' });
        if (filesEnabled.value) {
            links.push({ key: 'files', href: `/w/${workspaceSlug.value}/files`, label: t('welcome.quick.files'), hint: t('welcome.quick.files_hint'), icon: 'disk' });
        }
    }
    if (chatEnabled.value) {
        links.push({ key: 'chat', href: '/chat', label: t('welcome.quick.chat'), hint: t('welcome.quick.chat_hint'), icon: 'mail' });
    }

    links.push(
        { key: 'profile', href: '/profile', label: t('welcome.quick.profile'), hint: t('welcome.quick.profile_hint'), icon: 'user' },
        { key: 'notif', href: '/settings/notifications', label: t('welcome.quick.notifications'), hint: t('welcome.quick.notifications_hint'), icon: 'cog' },
        { key: 'tokens', href: '/settings/tokens', label: t('welcome.quick.tokens'), hint: t('welcome.quick.tokens_hint'), icon: 'key' },
    );

    if (isAdmin.value) {
        links.push(
            { key: 'admin', href: '/admin', label: t('welcome.quick.admin'), hint: t('welcome.quick.admin_hint'), icon: 'home' },
            { key: 'users', href: '/admin/users', label: t('welcome.quick.users'), hint: t('welcome.quick.users_hint'), icon: 'users' },
            { key: 'settings', href: '/admin/settings', label: t('welcome.quick.settings'), hint: t('welcome.quick.settings_hint'), icon: 'cog' },
        );
    }

    return links;
});

const quickLinksTitle = computed(() => (user.value ? t('welcome.shortcuts_title') : t('welcome.get_started_title')));

const now = new Date().toLocaleDateString('nb-NO', { day: '2-digit', month: '2-digit', year: 'numeric' });
</script>

<template>
    <Head :title="t('nav.home')" />

    <div
        class="cmd-shell"
        :style="{
            minHeight: '100vh',
            background: 'var(--bg)',
            color: 'var(--fg)',
            display: 'flex',
            flexDirection: 'column',
        }"
    >
        <PublicTopbar />

        <!-- Hero -->
        <section :style="{ flex: 1, display: 'flex', alignItems: 'center', padding: '0 24px' }">
            <div :style="{ maxWidth: '780px', margin: '0 auto', width: '100%', padding: '80px 0 60px' }">
                <div
                    class="cmd-mono cmd-uc"
                    :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '24px', fontWeight: 500, display: 'inline-flex', alignItems: 'center', gap: '8px' }"
                >
                    <Dot color="var(--success)" :size="5" />
                    <span>{{ now }} · {{ t('welcome.eyebrow') }}</span>
                </div>

                <h1
                    :style="{
                        margin: 0,
                        fontSize: '44px',
                        fontWeight: 700,
                        letterSpacing: '-0.025em',
                        lineHeight: 1.05,
                        color: 'var(--fg)',
                    }"
                >{{ user ? t('welcome.hero_title_user', { name: user.first_name }) : t('welcome.hero_title') }}</h1>

                <p
                    :style="{
                        fontSize: '14px',
                        color: 'var(--fg-dim)',
                        margin: '14px 0 32px',
                        maxWidth: '560px',
                        lineHeight: 1.55,
                    }"
                >{{ t('welcome.hero_subtitle') }}</p>

                <div :style="{ display: 'flex', alignItems: 'center', gap: '8px' }">
                    <Link
                        :href="primaryHref"
                        :style="{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: '6px',
                            background: 'var(--accent)',
                            color: '#fff',
                            padding: '8px 14px',
                            borderRadius: '5px',
                            fontSize: '12.5px',
                            fontWeight: 500,
                            textDecoration: 'none',
                        }"
                    >
                        {{ primaryLabel }}
                        <Icon name="arrow" :size="12" />
                    </Link>
                    <Link
                        v-if="secondaryVisible"
                        :href="secondaryHref"
                        :style="{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: '6px',
                            background: 'transparent',
                            color: 'var(--fg-dim)',
                            border: '1px solid var(--border)',
                            padding: '8px 14px',
                            borderRadius: '5px',
                            fontSize: '12.5px',
                            textDecoration: 'none',
                            fontFamily: 'inherit',
                        }"
                    >{{ secondaryLabel }}</Link>
                </div>
            </div>
        </section>

        <!-- Quick-links: single-column list so we never leave ghost cells in an
             uneven auto-fill grid. Each row is a full-width link with a
             leading icon tile and a trailing chevron. -->
        <section :style="{ borderTop: '1px solid var(--border)', padding: '0 24px', background: 'var(--bg2)' }">
            <div :style="{ maxWidth: '780px', margin: '0 auto', width: '100%', padding: '32px 0' }">
                <div
                    class="cmd-mono cmd-uc"
                    :style="{ fontSize: '10px', color: 'var(--fg-mute)', fontWeight: 500, marginBottom: '14px' }"
                >{{ quickLinksTitle }}</div>

                <div
                    :style="{
                        display: 'flex',
                        flexDirection: 'column',
                        border: '1px solid var(--border)',
                        borderRadius: '6px',
                        overflow: 'hidden',
                        background: 'var(--panel)',
                    }"
                >
                    <Link
                        v-for="(l, i) in quickLinks"
                        :key="l.key"
                        :href="l.href"
                        class="cmd-quick-link"
                        :style="{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '12px',
                            padding: '12px 16px',
                            textDecoration: 'none',
                            transition: 'background 0.12s',
                            borderTop: i === 0 ? 'none' : '1px solid var(--border)',
                        }"
                    >
                        <span
                            :style="{
                                width: '28px',
                                height: '28px',
                                borderRadius: '5px',
                                background: 'var(--accent-soft)',
                                border: '1px solid var(--accent-border)',
                                color: 'var(--accent)',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                flexShrink: 0,
                            }"
                        >
                            <Icon :name="l.icon" :size="14" />
                        </span>
                        <span :style="{ display: 'flex', flexDirection: 'column', gap: '2px', minWidth: 0, flex: 1 }">
                            <span :style="{ fontSize: '13px', fontWeight: 500, color: 'var(--fg)' }">{{ l.label }}</span>
                            <span :style="{ fontSize: '11.5px', color: 'var(--fg-dim)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">{{ l.hint }}</span>
                        </span>
                        <Icon name="chevR" :size="14" :style="{ color: 'var(--fg-mute)', flexShrink: 0 }" />
                    </Link>
                </div>
            </div>
        </section>

        <!-- Footer — three even cells (copyright · links · version). -->
        <footer :style="{ borderTop: '1px solid var(--border)', padding: '0 24px', background: 'var(--bg)' }">
            <div
                class="cmd-mono"
                :style="{
                    maxWidth: '780px',
                    margin: '0 auto',
                    width: '100%',
                    padding: '14px 0',
                    display: 'grid',
                    gridTemplateColumns: '1fr auto 1fr',
                    alignItems: 'center',
                    gap: '16px',
                    fontSize: '10.5px',
                    color: 'var(--fg-mute)',
                }"
            >
                <span>© {{ new Date().getFullYear() }} {{ appName }}</span>
                <span :style="{ display: 'inline-flex', alignItems: 'center', gap: '14px' }">
                    <Link
                        href="/privacy"
                        :style="{ color: 'var(--fg-dim)', textDecoration: 'none' }"
                        class="cmd-footer-link"
                    >{{ t('welcome.footer_privacy') }}</Link>
                    <span :style="{ color: 'var(--fg-mute)' }">·</span>
                    <Link
                        href="/terms"
                        :style="{ color: 'var(--fg-dim)', textDecoration: 'none' }"
                        class="cmd-footer-link"
                    >{{ t('welcome.footer_terms') }}</Link>
                    <span :style="{ color: 'var(--fg-mute)' }">·</span>
                    <a
                        href="/up"
                        target="_blank"
                        rel="noopener"
                        :style="{ color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '5px' }"
                        class="cmd-footer-link"
                    >
                        <Dot color="var(--success)" :size="5" />
                        {{ t('welcome.footer_status') }}
                    </a>
                </span>
                <span :style="{ justifySelf: 'end' }">v1</span>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.cmd-quick-link:hover {
    background: var(--panel2);
}
.cmd-footer-link:hover {
    color: var(--fg) !important;
}
</style>
