<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from 'primevue/useconfirm';
import ConfirmDialog from 'primevue/confirmdialog';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CmdDataTable, { type Column } from '@/Components/Command/DataTable.vue';
import Toggle from '@/Components/Command/Toggle.vue';
import PageTitle from '@/Components/Command/PageTitle.vue';
import { humanBytes } from '@/utils/bytes';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();
const confirm = useConfirm();

interface ModuleRow {
    id: number;
    key: string;
    name: string;
    enabled: boolean;
    morph_alias: string | null;
    record_count: number;
    trashed_count: number;
    storage_used_bytes: number;
    file_count: number;
}

defineProps<{ modules: ModuleRow[] }>();

const search = ref('');

const columns: Column<ModuleRow>[] = [
    { key: 'name', label: t('admin.modules.module'), width: 'minmax(120px, 1.5fr)' },
    { key: 'enabled', label: t('admin.modules.status'), width: '150px' },
    { key: 'record_count', label: t('admin.modules.records'), align: 'right', mono: true, width: '100px' },
    { key: 'storage_used_bytes', label: t('admin.modules.storage'), align: 'right', mono: true, width: '110px' },
    { key: 'trashed_count', label: t('admin.modules.trashed'), align: 'right', mono: true, width: '90px' },
];

function toggle(row: ModuleRow, value: boolean) {
    // preserveState + only:['modules'] refreshes just the modules prop (fresh
    // stats) without re-mounting the whole page.
    router.patch(`/admin/modules/${row.id}`, { enabled: value }, { preserveScroll: true, preserveState: true, only: ['modules'] });
}

function confirmPurge(row: ModuleRow) {
    confirm.require({
        group: 'admin-modules',
        message: t('admin.modules.confirm_purge', { name: row.name, count: row.record_count }),
        header: t('admin.modules.confirm_purge_title', { name: row.name }),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('admin.modules.purge_confirm'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.post(`/admin/modules/${row.id}/purge`, {}, { preserveScroll: true }),
    });
}
</script>

<template>
    <div>
        <Head :title="t('admin.modules.head_title')" />
        <ConfirmDialog group="admin-modules" />

        <PageTitle :title="t('admin.modules.title')" :subtitle="t('admin.modules.subtitle')" />

        <CmdDataTable :rows="modules" v-model:search="search" :columns="columns" :searchable="false" :empty-text="t('admin.modules.empty')">
            <template #cell:enabled="{ row }">
                <div :style="{ display: 'flex', alignItems: 'center', gap: '8px' }">
                    <Toggle :model-value="row.enabled" :label="row.name" @update:model-value="(v) => toggle(row, v)" />
                    <span :style="{ fontSize: '11px', color: row.enabled ? 'var(--accent)' : 'var(--fg-mute)' }">
                        {{ row.enabled ? t('admin.modules.enabled') : t('admin.modules.disabled') }}
                    </span>
                </div>
            </template>
            <template #cell:storage_used_bytes="{ row }">
                {{ humanBytes(row.storage_used_bytes) }}
            </template>

            <template #actions="{ row }">
                <button
                    type="button"
                    :title="t('admin.modules.delete_all')"
                    :style="{ background: 'transparent', border: '1px solid var(--border)', color: 'var(--danger)', cursor: 'pointer', padding: '3px 9px', borderRadius: '5px', fontSize: '11px', fontFamily: 'inherit' }"
                    @click="confirmPurge(row)"
                >
                    {{ t('admin.modules.delete_all') }}
                </button>
            </template>
        </CmdDataTable>
    </div>
</template>
