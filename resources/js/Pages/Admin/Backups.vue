<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CmdDataTable, { type Column } from '@/Components/Command/DataTable.vue';
import Icon from '@/Components/Command/Icon.vue';
import Dot from '@/Components/Command/Dot.vue';
import PageTitle from '@/Components/Command/PageTitle.vue';
import CmdButton from '@/Components/Command/Button.vue';
import { useCommandToasts } from '@/composables/useCommandToasts';
import { formatDateTime } from '@/composables/useDateTime';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();
const { push } = useCommandToasts();

interface Backup { id?: string; disk: string; path: string; size: number; date: string }
interface Summary { disk: string; count: number; used_bytes: number; newest_at: string | null; error?: boolean }
interface BackupConfig {
    includes: string[];
    excludes: string[];
    databases: string[];
    retention_daily: number;
    retention_daily_keep: number;
    retention_weekly_keep: number;
    retention_monthly_keep: number;
    retention_yearly_keep: number;
    max_storage_mb: number;
}

const props = defineProps<{
    backups: Backup[];
    disks: string[];
    name: string;
    summaries: Summary[];
    config: BackupConfig;
}>();

// Rows need a stable id for the shared table. Use disk:path as the key.
const rows = computed(() =>
    props.backups.map((b) => ({ ...b, id: `${b.disk}:${b.path}` })),
);

const columns: Column<(Backup & { id: string })>[] = [
    { key: 'date', label: t('admin.backups.date'), sortable: true, width: '180px', mono: true },
    { key: 'disk', label: t('admin.backups.disk'), sortable: true, width: '120px' },
    { key: 'path', label: t('admin.backups.file'), sortable: true, mono: true },
    { key: 'size', label: t('admin.backups.size'), sortable: true, width: '120px', align: 'right', mono: true },
];

const search = ref('');
const sortKey = ref('date');
const sortDir = ref<'asc' | 'desc'>('desc');

function humanSize(bytes: number | null | undefined): string {
    if (!bytes || bytes <= 0) return '0 B';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
}

function runBackup() {
    // Server flashes flash.backups.queued; useFlashToast shows it.
    router.post('/admin/backups/run', {}, { preserveScroll: true });
}
function runClean() {
    // Server flashes flash.backups.cleanup_queued.
    router.post('/admin/backups/clean', {}, { preserveScroll: true });
}

function downloadUrl(b: Backup): string {
    const params = new URLSearchParams({ disk: b.disk, path: b.path });
    return `/admin/backups/download?${params.toString()}`;
}

const infoOpen = ref(false);

const restoreTarget = ref<Backup | null>(null);
const restoreConfirmText = ref('');

const restoreFilename = computed(() =>
    restoreTarget.value ? restoreTarget.value.path.split('/').pop() ?? '' : '',
);

const restoreValid = computed(() => restoreConfirmText.value.trim() === restoreFilename.value);

function openRestore(b: Backup) {
    restoreTarget.value = b;
    restoreConfirmText.value = '';
}

function cancelRestore() {
    restoreTarget.value = null;
    restoreConfirmText.value = '';
}

function submitRestore() {
    if (!restoreTarget.value || !restoreValid.value) return;
    router.post(
        '/admin/backups/prepare-restore',
        { disk: restoreTarget.value.disk, path: restoreTarget.value.path, confirm: restoreConfirmText.value.trim() },
        { preserveScroll: true, onSuccess: cancelRestore },
    );
}
</script>

<template>
    <div>
    <Head :title="t('admin.backups.head_title')" />

    <PageTitle :title="t('admin.backups.title')" :subtitle="t('admin.backups.subtitle')">
        <template #actions>
            <CmdButton variant="ghost" size="md" @click="runClean">
                <template #icon><Icon name="trash" :size="12" /></template>
                {{ t('admin.backups.clean') }}
            </CmdButton>
            <CmdButton variant="primary" size="md" @click="runBackup">
                <template #icon><Icon name="arrow" :size="12" /></template>
                {{ t('admin.backups.run_now') }}
            </CmdButton>
        </template>
    </PageTitle>

    <!-- Summary strip -->
    <div
        v-if="summaries.length"
        :style="{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))',
            gap: '1px',
            background: 'var(--border)',
            border: '1px solid var(--border)',
            borderRadius: 'var(--radius-card)',
            overflow: 'hidden',
            marginBottom: '16px',
        }"
    >
        <div
            v-for="s in summaries"
            :key="s.disk"
            :style="{ background: 'var(--panel)', padding: '14px 16px' }"
        >
            <div
                class="cmd-mono cmd-uc"
                :style="{ fontSize: '9.5px', color: 'var(--fg-mute)', marginBottom: '6px', fontWeight: 500, letterSpacing: '0.04em', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }"
            >
                <span>{{ s.disk }}</span>
                <span>{{ s.count }} {{ t('admin.backups.files') }}</span>
            </div>
            <div
                class="cmd-mono"
                :style="{ fontSize: '22px', fontWeight: 600, color: 'var(--fg)', letterSpacing: '-0.01em', marginBottom: '4px', lineHeight: 1.1 }"
            >{{ humanSize(s.used_bytes) }}</div>
            <div
                :style="{ fontSize: '10.5px', color: 'var(--fg-mute)', display: 'flex', alignItems: 'center', gap: '6px' }"
            >
                <Dot :color="s.error ? 'var(--danger)' : s.newest_at ? 'var(--success)' : 'var(--warning)'" :size="5" />
                <span>{{ s.newest_at ? t('admin.backups.newest_at', { when: formatDateTime(s.newest_at) }) : t('admin.backups.no_backups_yet') }}</span>
            </div>
        </div>
    </div>

    <!-- Info panel (collapsed by default) -->
    <div
        class="cmd-card"
        :style="{ marginBottom: '16px' }"
    >
        <button
            type="button"
            @click="infoOpen = !infoOpen"
            :style="{
                width: '100%',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                padding: '11px 16px',
                background: 'transparent',
                border: 'none',
                borderBottom: infoOpen ? '1px solid var(--border)' : 'none',
                cursor: 'pointer',
                fontFamily: 'inherit',
            }"
        >
            <span :style="{ fontSize: '12.5px', fontWeight: 600, color: 'var(--fg)' }">
                {{ t('admin.backups.how_it_works') }}
            </span>
            <span :style="{ display: 'flex', transform: infoOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.12s', color: 'var(--fg-mute)' }">
                <Icon name="chevD" :size="11" />
            </span>
        </button>
        <div
            v-if="infoOpen"
            :style="{ padding: '16px', display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: '24px', fontSize: '12px', color: 'var(--fg-dim)' }"
        >
            <div>
                <div
                    class="cmd-mono cmd-uc"
                    :style="{ fontSize: '9.5px', color: 'var(--fg-mute)', marginBottom: '6px' }"
                >{{ t('admin.backups.info_whats_included') }}</div>
                <p :style="{ color: 'var(--fg)', marginBottom: '10px' }">{{ t('admin.backups.info_whats_included_desc') }}</p>
                <div v-if="config.databases.length" :style="{ fontSize: '11px', marginBottom: '4px' }">
                    <span :style="{ color: 'var(--fg-mute)', marginRight: '6px' }">{{ t('admin.backups.databases') }}:</span>
                    <code v-for="db in config.databases" :key="db" class="cmd-mono" :style="{ padding: '1px 6px', background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: '3px', marginRight: '3px', fontSize: '10.5px' }">{{ db }}</code>
                </div>
            </div>
            <div>
                <div
                    class="cmd-mono cmd-uc"
                    :style="{ fontSize: '9.5px', color: 'var(--fg-mute)', marginBottom: '6px' }"
                >{{ t('admin.backups.info_retention') }}</div>
                <ul :style="{ listStyle: 'none', padding: 0, margin: 0, fontSize: '11.5px', lineHeight: 1.8, color: 'var(--fg)' }">
                    <li>{{ t('admin.backups.retention_daily', { n: config.retention_daily_keep }) }}</li>
                    <li>{{ t('admin.backups.retention_weekly', { n: config.retention_weekly_keep }) }}</li>
                    <li>{{ t('admin.backups.retention_monthly', { n: config.retention_monthly_keep }) }}</li>
                    <li>{{ t('admin.backups.retention_yearly', { n: config.retention_yearly_keep }) }}</li>
                </ul>
            </div>
        </div>
    </div>

    <CmdDataTable
        :rows="rows"
        :columns="columns"
        v-model:search="search"
        v-model:sort-key="sortKey"
        v-model:sort-dir="sortDir"
        :search-placeholder="t('admin.backups.search_placeholder')"
        :search-keys="['path', 'disk']"
        :empty-text="t('admin.backups.empty')"
        action-column-width="100px"
    >
        <template #cell:date="{ row }">{{ formatDateTime(row.date) }}</template>
        <template #cell:disk="{ row }">
            <span
                class="cmd-mono"
                :style="{ fontSize: '10.5px', color: 'var(--accent)', background: 'var(--accent-soft)', border: '1px solid var(--accent-border)', padding: '1px 7px', borderRadius: '3px' }"
            >{{ row.disk }}</span>
        </template>
        <template #cell:path="{ row }">
            <code :style="{ color: 'var(--fg-dim)', fontSize: '10.5px' }">{{ row.path }}</code>
        </template>
        <template #cell:size="{ row }">{{ humanSize(row.size) }}</template>

        <template #actions="{ row }">
            <a
                :href="downloadUrl(row)"
                :title="t('admin.backups.download')"
                :style="{ background: 'transparent', border: 'none', color: 'var(--fg-dim)', cursor: 'pointer', padding: '4px', borderRadius: '3px', display: 'flex', alignItems: 'center', justifyContent: 'center', textDecoration: 'none' }"
            >
                <Icon name="arrow" :size="12" />
            </a>
            <button
                type="button"
                :title="t('admin.backups.prepare_restore')"
                @click="openRestore(row)"
                :style="{ background: 'transparent', border: 'none', color: 'var(--warning)', cursor: 'pointer', padding: '4px', borderRadius: '3px', display: 'flex', alignItems: 'center', justifyContent: 'center' }"
            >
                <Icon name="restore" :size="12" />
            </button>
        </template>
    </CmdDataTable>

    <!-- Restore confirmation dialog -->
    <div
        v-if="restoreTarget"
        @click.self="cancelRestore"
        :style="{
            position: 'fixed',
            inset: 0,
            background: 'rgba(0,0,0,0.6)',
            backdropFilter: 'blur(4px)',
            zIndex: 100,
            display: 'flex',
            alignItems: 'flex-start',
            justifyContent: 'center',
            paddingTop: '12vh',
        }"
    >
        <div
            :style="{
                width: '520px',
                maxWidth: '94vw',
                background: 'var(--panel)',
                border: '1px solid var(--border)',
                borderRadius: '8px',
                padding: '20px',
                boxShadow: 'var(--shadow-palette)',
            }"
        >
            <div :style="{ fontSize: '14px', fontWeight: 600, color: 'var(--fg)', marginBottom: '10px' }">
                {{ t('admin.backups.restore_dialog_title') }}
            </div>
            <p :style="{ fontSize: '12.5px', color: 'var(--fg-dim)', marginBottom: '12px', lineHeight: 1.5 }">
                {{ t('admin.backups.restore_dialog_body') }}
            </p>
            <div
                :style="{ padding: '10px 12px', borderRadius: '5px', background: 'rgba(251,191,36,0.10)', border: '1px solid rgba(251,191,36,0.33)', marginBottom: '14px' }"
            >
                <div :style="{ fontSize: '11.5px', fontWeight: 600, color: 'var(--warning)', marginBottom: '4px' }">
                    {{ t('admin.backups.restore_dialog_warning_title') }}
                </div>
                <div :style="{ fontSize: '11.5px', color: 'var(--fg-dim)' }">
                    {{ t('admin.backups.restore_dialog_warning_body') }}
                </div>
            </div>
            <label
                class="cmd-mono cmd-uc"
                :style="{ display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', fontWeight: 500 }"
            >{{ t('admin.backups.restore_dialog_confirm_label', { name: restoreFilename }) }}</label>
            <input
                v-model="restoreConfirmText"
                autocomplete="off"
                :style="{
                    width: '100%',
                    background: 'var(--panel2)',
                    border: '1px solid var(--border)',
                    borderRadius: '5px',
                    padding: '8px 10px',
                    color: 'var(--fg)',
                    fontSize: '12.5px',
                    outline: 'none',
                    fontFamily: 'var(--font-mono)',
                }"
            />
            <div :style="{ display: 'flex', justifyContent: 'flex-end', gap: '6px', marginTop: '16px' }">
                <button
                    type="button"
                    @click="cancelRestore"
                    :style="{ background: 'transparent', color: 'var(--fg-dim)', border: '1px solid var(--border)', padding: '6px 12px', borderRadius: '5px', fontSize: '12px', cursor: 'pointer', fontFamily: 'inherit' }"
                >{{ t('common.cancel') }}</button>
                <button
                    type="button"
                    :disabled="!restoreValid"
                    @click="submitRestore"
                    :style="{
                        background: restoreValid ? 'var(--warning)' : 'var(--panel2)',
                        color: restoreValid ? '#0a0c12' : 'var(--fg-mute)',
                        border: 'none',
                        padding: '6px 12px',
                        borderRadius: '5px',
                        fontSize: '12px',
                        fontWeight: 500,
                        cursor: restoreValid ? 'pointer' : 'not-allowed',
                        fontFamily: 'inherit',
                    }"
                >{{ t('admin.backups.prepare_restore') }}</button>
            </div>
        </div>
    </div>
    </div>
</template>
