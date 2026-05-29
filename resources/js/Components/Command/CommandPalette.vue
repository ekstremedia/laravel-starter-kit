<script setup lang="ts">
/*
 * ⌘K command palette. 540 px modal at top: 12vh with blur backdrop.
 * Grouped: Navigasjon / Handlinger / Tema. Arrow-keys navigate, Enter
 * executes + closes. ESC closes. Input is autofocused on mount.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from './Icon.vue';
import { useTweaks } from '@/composables/useTweaks';
import { useCommandToasts } from '@/composables/useCommandToasts';
import type { CommandAccent, CommandDensity, CommandTheme, PageProps } from '@/types';

interface Props { open: boolean }
const props = defineProps<Props>();
const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();
const { setTheme, setAccent, setDensity } = useTweaks();
const { push } = useCommandToasts();
const page = usePage<PageProps>();
const isAdmin = computed(() => page.props.auth?.user?.is_super_admin === true);
const chatEnabled = computed(() => page.props.chat?.enabled ?? false);
const workspaceSlug = computed(() => page.props.workspace?.slug ?? null);
const globalFilesEnabled = computed(() => page.props.app_settings?.files_feature_enabled ?? false);
const filesTarget = computed(() => {
    if (page.props.workspace?.files_feature_enabled) return page.props.workspace;
    return (page.props.available_workspaces ?? []).find((c) => c.files_feature_enabled) ?? null;
});

interface Cmd {
    id: string;
    label: string;
    group: string;
    kbd?: string;
    fn: () => void;
}

const commands = computed<Cmd[]>(() => {
    const gNav = t('palette.group_nav');
    const gAct = t('palette.group_actions');
    const gTheme = t('palette.group_theme');

    const nav: Cmd[] = [
        { id: 'go-home', label: t('palette.go_home'), group: gNav, kbd: 'G H', fn: () => router.visit('/home') },
        { id: 'go-profile', label: t('palette.go_profile'), group: gNav, fn: () => router.visit('/profile') },
        { id: 'go-notif', label: t('palette.go_notification_settings'), group: gNav, fn: () => router.visit('/settings/notifications') },
        { id: 'go-tokens', label: t('palette.go_api_tokens'), group: gNav, fn: () => router.visit('/settings/tokens') },
    ];
    if (workspaceSlug.value) {
        nav.push({ id: 'go-cdash', label: t('palette.go_dashboard'), group: gNav, fn: () => router.visit(`/w/${workspaceSlug.value}/dashboard`) });
    }
    if (globalFilesEnabled.value && filesTarget.value) {
        nav.push({ id: 'go-files', label: t('palette.go_files'), group: gNav, fn: () => router.visit(`/w/${filesTarget.value!.slug}/files`) });
    }
    if (chatEnabled.value) nav.push({ id: 'go-chat', label: t('palette.go_chat'), group: gNav, fn: () => router.visit('/chat') });

    const adminNav: Cmd[] = isAdmin.value
        ? [
            { id: 'go-admin', label: t('palette.go_admin_overview'), group: gNav, kbd: 'G D', fn: () => router.visit('/admin') },
            { id: 'go-users', label: t('palette.go_admin_users'), group: gNav, kbd: 'G U', fn: () => router.visit('/admin/users') },
            { id: 'go-settings', label: t('palette.go_admin_settings'), group: gNav, kbd: 'G A', fn: () => router.visit('/admin/settings') },
            { id: 'go-mail', label: t('palette.go_admin_mail'), group: gNav, fn: () => router.visit('/admin/mail') },
            { id: 'go-roles', label: t('palette.go_admin_roles'), group: gNav, fn: () => router.visit('/admin/roles') },
            { id: 'go-storage', label: t('palette.go_admin_storage'), group: gNav, fn: () => router.visit('/admin/storage') },
            { id: 'go-backups', label: t('palette.go_admin_backups'), group: gNav, fn: () => router.visit('/admin/backups') },
            { id: 'go-logs', label: t('palette.go_admin_logs'), group: gNav, fn: () => router.visit('/admin/monitoring') },
        ]
        : [];

    const actions: Cmd[] = [
        ...(isAdmin.value ? [
            { id: 'new-user', label: t('palette.action_new_user'), group: gAct, fn: () => router.visit('/admin/users/create') } as Cmd,
            { id: 'run-backup', label: t('palette.action_run_backup'), group: gAct, fn: () => {
                router.post('/admin/backups/run', {}, { onSuccess: () => push(t('palette.toast_backup_started'), 'success') });
            } } as Cmd,
        ] : []),
        { id: 'logout', label: t('palette.action_logout'), group: gAct, fn: () => router.post('/logout') },
    ];

    return [...nav, ...adminNav, ...actions,

    ...(['dark', 'hc', 'light'] as CommandTheme[]).map<Cmd>((th) => ({
        id: `theme-${th}`,
        label: t('palette.theme_label', { value: t(`palette.theme_${th}`) }),
        group: gTheme,
        fn: () => { setTheme(th); push(t('palette.theme_label', { value: t(`palette.theme_${th}`) }), 'success'); },
    })),

    ...(['cobalt', 'emerald', 'amber', 'violet'] as CommandAccent[]).map<Cmd>((a) => ({
        id: `acc-${a}`,
        label: t('palette.accent_label', { value: t(`palette.accent_${a}`) }),
        group: gTheme,
        fn: () => { setAccent(a); push(t('palette.toast_accent_updated'), 'success'); },
    })),

    ...(['compact', 'comfortable', 'relaxed'] as CommandDensity[]).map<Cmd>((d) => ({
        id: `dens-${d}`,
        label: t('palette.density_label', { value: t(`palette.density_${d}`) }),
        group: gTheme,
        fn: () => { setDensity(d); push(t('palette.toast_density_updated'), 'success'); },
    })),
    ];
});

const q = ref('');
const idx = ref(0);
const inputRef = ref<HTMLInputElement | null>(null);

const filtered = computed(() => {
    if (!q.value) return commands.value;
    const needle = q.value.toLowerCase();
    return commands.value.filter((c) => c.label.toLowerCase().includes(needle) || c.group.toLowerCase().includes(needle));
});

const groups = computed(() => {
    const out: Record<string, Cmd[]> = {};
    filtered.value.forEach((c) => {
        (out[c.group] = out[c.group] || []).push(c);
    });
    return out;
});

watch(q, () => { idx.value = 0; });
watch(
    () => props.open,
    (open) => {
        if (open) {
            q.value = '';
            idx.value = 0;
            nextTick(() => inputRef.value?.focus());
        }
    },
    { immediate: true },
);

function onKey(e: KeyboardEvent) {
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        idx.value = Math.min(idx.value + 1, filtered.value.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        idx.value = Math.max(idx.value - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const c = filtered.value[idx.value];
        if (c) { c.fn(); emit('close'); }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        emit('close');
    }
}

function execute(c: Cmd) {
    c.fn();
    emit('close');
}
</script>

<template>
    <div
        v-if="open"
        @click="emit('close')"
        :style="{
            position: 'fixed',
            inset: 0,
            background: 'rgba(0,0,0,0.6)',
            zIndex: 100,
            display: 'flex',
            alignItems: 'flex-start',
            justifyContent: 'center',
            paddingTop: '12vh',
            backdropFilter: 'blur(4px)',
        }"
    >
        <div
            @click.stop
            :style="{
                width: '540px',
                maxWidth: '94vw',
                background: 'var(--panel)',
                border: '1px solid var(--border)',
                borderRadius: '8px',
                overflow: 'hidden',
                boxShadow: 'var(--shadow-palette)',
                animation: 'cmdFadeIn 0.12s ease-out',
            }"
        >
            <div
                :style="{
                    display: 'flex',
                    alignItems: 'center',
                    padding: '10px 14px',
                    borderBottom: '1px solid var(--border)',
                    gap: '10px',
                }"
            >
                <Icon name="search" :size="15" :style="{ color: 'var(--fg-mute)' }" />
                <input
                    ref="inputRef"
                    v-model="q"
                    @keydown="onKey"
                    :placeholder="t('palette.input_placeholder')"
                    :style="{
                        flex: 1,
                        background: 'transparent',
                        border: 'none',
                        outline: 'none',
                        color: 'var(--fg)',
                        fontSize: '14px',
                        fontFamily: 'inherit',
                    }"
                />
                <kbd
                    class="cmd-mono"
                    :style="{
                        fontSize: '10px',
                        padding: '2px 6px',
                        border: '1px solid var(--border)',
                        borderRadius: '3px',
                        color: 'var(--fg-dim)',
                    }"
                >ESC</kbd>
            </div>

            <div :style="{ maxHeight: '50vh', overflow: 'auto', padding: '4px' }">
                <template v-for="(items, g) in groups" :key="g">
                    <div
                        class="cmd-mono cmd-uc"
                        :style="{
                            fontSize: '9.5px',
                            color: 'var(--fg-mute)',
                            padding: '8px 10px 4px',
                            fontWeight: 500,
                        }"
                    >{{ g }}</div>
                    <div
                        v-for="c in items"
                        :key="c.id"
                        @mouseenter="idx = filtered.indexOf(c)"
                        @click="execute(c)"
                        :style="{
                            padding: '7px 10px',
                            borderRadius: '4px',
                            cursor: 'pointer',
                            background: filtered.indexOf(c) === idx ? 'var(--accent-soft)' : 'transparent',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'space-between',
                            fontSize: '12.5px',
                            color: filtered.indexOf(c) === idx ? 'var(--fg)' : 'var(--fg-dim)',
                        }"
                    >
                        <span>{{ c.label }}</span>
                        <kbd
                            v-if="c.kbd"
                            class="cmd-mono"
                            :style="{
                                fontSize: '9.5px',
                                padding: '1px 5px',
                                border: '1px solid var(--border)',
                                borderRadius: '3px',
                                color: 'var(--fg-dim)',
                            }"
                        >{{ c.kbd }}</kbd>
                    </div>
                </template>
                <div
                    v-if="filtered.length === 0"
                    :style="{ padding: '20px', textAlign: 'center', color: 'var(--fg-mute)', fontSize: '12px' }"
                >{{ t('palette.empty', { query: q }) }}</div>
            </div>

            <div
                class="cmd-mono"
                :style="{
                    display: 'flex',
                    justifyContent: 'space-between',
                    padding: '8px 14px',
                    borderTop: '1px solid var(--border)',
                    fontSize: '10.5px',
                    color: 'var(--fg-mute)',
                }"
            >
                <span>
                    <kbd class="cmd-mono" style="font-size:9.5px;padding:1px 5px;border:1px solid var(--border);border-radius:3px;margin-right:4px;">↑↓</kbd> {{ t('palette.nav') }}
                    <kbd class="cmd-mono" style="font-size:9.5px;padding:1px 5px;border:1px solid var(--border);border-radius:3px;margin:0 4px 0 6px;">⏎</kbd> {{ t('palette.select') }}
                </span>
                <span>{{ t('palette.matches', { n: filtered.length }) }}</span>
            </div>
        </div>
    </div>
</template>
