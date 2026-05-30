<script setup lang="ts">
/*
 * Workspace-scoped dashboard. Differs from /home (personal account overview)
 * by showing workspace-level stats: workspace identity, member count, files
 * usage (when the feature is on), chat backlog (when chat is on), and
 * cross-member recent activity. Non-admins only see cells relevant to
 * them — the Admin deep-link on the member tile is hidden when they lack
 * the role.
 */
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref, type Component } from 'vue';
import AppLayout from '@/Layouts/CommandLayout.vue';
import Icon, { type IconName } from '@/Components/Command/Icon.vue';
import CommandDialog from '@/Components/Command/Dialog.vue';
import CmdButton from '@/Components/Command/Button.vue';
import Toggle from '@/Components/Command/Toggle.vue';
import EquipmentWidget from '@/Components/Dashboard/EquipmentWidget.vue';
import { useWorkspace } from '@/composables/useWorkspace';
import type { PageProps } from '@/types';

interface FilesStats { count: number; bytes: number }
interface ChatStats { unread: number }
interface WidgetMeta {
    key: string;
    title_key: string;
    icon: string;
    component: string;
    enabled: boolean;
    data: Record<string, unknown> | null;
}
interface Props {
    memberCount: number;
    filesStats: FilesStats | null;
    chatStats: ChatStats | null;
    widgets: WidgetMeta[];
}

const props = defineProps<Props>();

const { t } = useI18n();
const page = usePage<PageProps>();
const user = computed(() => page.props.auth.user!);
const workspace = computed(() => page.props.workspace);
const isAdmin = computed(() => user.value?.is_super_admin === true);
const { workspaceUrl } = useWorkspace();

// Module dashboard widgets. The string `component` name maps to a real Vue
// component here; add a module's widget by importing it + a map entry.
const widgetComponents: Record<string, Component> = { EquipmentWidget };
const enabledWidgets = computed(() => props.widgets.filter((w) => w.enabled && widgetComponents[w.component]));

// "Customize" — toggle which widgets show. Persists hidden keys to user settings.
const customizeOpen = ref(false);
const hidden = ref<Set<string>>(new Set(props.widgets.filter((w) => !w.enabled).map((w) => w.key)));
function isHidden(key: string): boolean {
    return hidden.value.has(key);
}
function toggleWidget(key: string, show: boolean) {
    const next = new Set(hidden.value);
    show ? next.delete(key) : next.add(key);
    hidden.value = next;
}
function saveWidgets() {
    router.patch('/settings', { dashboard_hidden_widgets: [...hidden.value] }, {
        preserveScroll: true,
        onSuccess: () => { customizeOpen.value = false; },
    });
}

function formatBytes(bytes: number): string {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
}
</script>

<template>
    <AppLayout>
        <Head :title="t('nav.dashboard')" />

        <div :style="{ maxWidth: '880px', margin: '0 auto', padding: '32px 16px' }">
            <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '12px' }">
                <div>
                    <h1
                        :style="{ margin: 0, fontSize: '32px', fontWeight: 700, letterSpacing: '-0.02em', color: 'var(--fg)' }"
                    >{{ workspace?.name ?? t('nav.dashboard') }}</h1>
                    <p :style="{ fontSize: '13px', color: 'var(--fg-dim)', margin: '8px 0 0' }">
                        {{ t('dashboard.workspace_subtitle') }}
                    </p>
                </div>
                <button
                    v-if="widgets.length"
                    type="button"
                    :style="{ flexShrink: 0, background: 'var(--panel2)', color: 'var(--fg)', border: '1px solid var(--border)', padding: '6px 11px', borderRadius: '5px', fontSize: '11.5px', display: 'inline-flex', alignItems: 'center', gap: '5px', cursor: 'pointer', fontFamily: 'inherit', marginTop: '6px' }"
                    @click="customizeOpen = true"
                >
                    <Icon name="cog" :size="12" />
                    {{ t('dashboard.customize') }}
                </button>
            </div>

            <!-- Workspace stats grid -->
            <div
                :style="{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
                    gap: '1px',
                    background: 'var(--border)',
                    border: '1px solid var(--border)',
                    borderRadius: 'var(--radius-card)',
                    marginTop: '24px',
                    overflow: 'hidden',
                }"
            >
                <!-- Members -->
                <div :style="{ background: 'var(--panel)', padding: '14px 16px' }">
                    <div
                        class="cmd-mono cmd-uc"
                        :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '8px', fontWeight: 500 }"
                    >{{ t('dashboard.members') }}</div>
                    <div :style="{ display: 'flex', alignItems: 'baseline', gap: '8px' }">
                        <span class="cmd-mono" :style="{ fontSize: '20px', color: 'var(--fg)', fontWeight: 600 }">{{ memberCount }}</span>
                        <Link
                            v-if="isAdmin"
                            href="/admin/users"
                            :style="{ fontSize: '11.5px', color: 'var(--accent)', textDecoration: 'none' }"
                        >{{ t('dashboard.manage_members') }} →</Link>
                    </div>
                </div>

                <!-- Files -->
                <div
                    v-if="filesStats"
                    :style="{ background: 'var(--panel)', padding: '14px 16px' }"
                >
                    <div
                        class="cmd-mono cmd-uc"
                        :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '8px', fontWeight: 500 }"
                    >{{ t('dashboard.files_usage') }}</div>
                    <div :style="{ display: 'flex', alignItems: 'baseline', gap: '8px' }">
                        <span class="cmd-mono" :style="{ fontSize: '20px', color: 'var(--fg)', fontWeight: 600 }">{{ filesStats.count }}</span>
                        <span :style="{ fontSize: '12px', color: 'var(--fg-dim)' }">{{ t('dashboard.files_count_suffix') }}</span>
                        <span class="cmd-mono" :style="{ fontSize: '11.5px', color: 'var(--fg-mute)', marginLeft: 'auto' }">{{ formatBytes(filesStats.bytes) }}</span>
                    </div>
                    <Link
                        :href="workspaceUrl('/files')"
                        :style="{ fontSize: '11.5px', color: 'var(--accent)', textDecoration: 'none', display: 'inline-block', marginTop: '6px' }"
                    >{{ t('dashboard.open_files') }} →</Link>
                </div>

                <!-- Chat -->
                <div
                    v-if="chatStats"
                    :style="{ background: 'var(--panel)', padding: '14px 16px' }"
                >
                    <div
                        class="cmd-mono cmd-uc"
                        :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '8px', fontWeight: 500 }"
                    >{{ t('dashboard.chat') }}</div>
                    <div :style="{ display: 'flex', alignItems: 'baseline', gap: '8px' }">
                        <span class="cmd-mono" :style="{ fontSize: '20px', color: chatStats.unread > 0 ? 'var(--accent)' : 'var(--fg)', fontWeight: 600 }">
                            {{ chatStats.unread }}
                        </span>
                        <span :style="{ fontSize: '12px', color: 'var(--fg-dim)' }">{{ t('dashboard.chat_unread_suffix') }}</span>
                    </div>
                    <Link
                        href="/chat"
                        :style="{ fontSize: '11.5px', color: 'var(--accent)', textDecoration: 'none', display: 'inline-block', marginTop: '6px' }"
                    >{{ t('dashboard.open_chat') }} →</Link>
                </div>
            </div>

            <!-- Module widgets (per-user customizable) -->
            <div
                v-if="enabledWidgets.length"
                :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '14px', marginTop: '24px' }"
            >
                <div v-for="w in enabledWidgets" :key="w.key" class="cmd-card" :style="{ padding: '16px 18px' }">
                    <div :style="{ display: 'flex', alignItems: 'center', gap: '7px', marginBottom: '14px' }">
                        <Icon :name="(w.icon as IconName)" :size="13" :style="{ color: 'var(--accent)' }" />
                        <h2 :style="{ margin: 0, fontSize: '14px', fontWeight: 600, color: 'var(--fg)' }">{{ t(w.title_key) }}</h2>
                    </div>
                    <component :is="widgetComponents[w.component]" :data="w.data" />
                </div>
            </div>

            <!-- Quick link back to personal home -->
            <div :style="{ marginTop: '24px', display: 'flex', alignItems: 'center', gap: '8px' }">
                <Link
                    href="/home"
                    :style="{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: '5px',
                        background: 'transparent',
                        color: 'var(--fg-dim)',
                        border: '1px solid var(--border)',
                        padding: '6px 11px',
                        borderRadius: '5px',
                        fontSize: '11.5px',
                        textDecoration: 'none',
                    }"
                >
                    <Icon name="user" :size="12" />
                    {{ t('dashboard.go_personal_home') }}
                </Link>
            </div>
        </div>

        <!-- Customize dashboard widgets -->
        <CommandDialog v-model:visible="customizeOpen" :title="t('dashboard.customize_title')" width="420px">
            <p :style="{ margin: '0 0 12px', fontSize: '12px', color: 'var(--fg-mute)' }">{{ t('dashboard.customize_help') }}</p>
            <div :style="{ display: 'flex', flexDirection: 'column', gap: '4px' }">
                <label
                    v-for="w in widgets"
                    :key="w.key"
                    :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '10px', padding: '8px 4px' }"
                >
                    <span :style="{ display: 'inline-flex', alignItems: 'center', gap: '8px', fontSize: '13px', color: 'var(--fg)' }">
                        <Icon :name="(w.icon as IconName)" :size="13" :style="{ color: 'var(--fg-mute)' }" />
                        {{ t(w.title_key) }}
                    </span>
                    <Toggle :model-value="!isHidden(w.key)" :label="t(w.title_key)" @update:model-value="(v) => toggleWidget(w.key, v)" />
                </label>
                <p v-if="!widgets.length" :style="{ fontSize: '12px', color: 'var(--fg-mute)', textAlign: 'center', padding: '8px' }">{{ t('dashboard.no_widgets') }}</p>
            </div>
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="customizeOpen = false">{{ t('common.cancel') }}</CmdButton>
                <CmdButton variant="primary" size="sm" @click="saveWidgets">
                    <template #icon><Icon name="disk" :size="12" /></template>
                    {{ t('common.save') }}
                </CmdButton>
            </template>
        </CommandDialog>
    </AppLayout>
</template>
