<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from 'primevue/useconfirm';
import ConfirmDialog from 'primevue/confirmdialog';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CmdDataTable, { type Column } from '@/Components/Command/DataTable.vue';
import Icon from '@/Components/Command/Icon.vue';
import PageTitle from '@/Components/Command/PageTitle.vue';
import { useWorkspace } from '@/composables/useWorkspace';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();
const { workspaceUrl } = useWorkspace();
const confirm = useConfirm();

interface TrashedRow {
    id: number;
    name: string;
    category: string | null;
    serial: string | null;
    deleted_at: string | null;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page?: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

defineProps<{
    equipment: Paginated<TrashedRow>;
    can_manage: boolean;
}>();

const search = ref('');

const actionBtnStyle = { background: 'transparent', border: 'none', color: 'var(--fg-dim)', cursor: 'pointer', padding: '4px', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' };

const columns: Column<TrashedRow>[] = [
    { key: 'name', label: t('equipment.name'), width: 'minmax(140px, 2fr)' },
    { key: 'category', label: t('equipment.category'), width: 'minmax(0, 1fr)' },
    { key: 'serial', label: t('equipment.serial'), mono: true, width: 'minmax(0, 1fr)' },
    { key: 'deleted_at', label: t('equipment.deleted_at'), mono: true, width: '170px' },
];

function restore(row: TrashedRow) {
    router.post(workspaceUrl(`/equipment/trash/${row.id}/restore`), {}, { preserveScroll: true });
}

function confirmForceDelete(row: TrashedRow) {
    confirm.require({
        group: 'equipment-trash',
        message: t('equipment.confirm_force_delete', { name: row.name }),
        header: t('equipment.force_delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('equipment.force_delete'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(workspaceUrl(`/equipment/trash/${row.id}`), { preserveScroll: true }),
    });
}

function fmt(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '—';
}
</script>

<template>
    <div>
        <Head :title="t('equipment.trash_title')" />
        <ConfirmDialog group="equipment-trash" />

        <PageTitle :title="t('equipment.trash_title')" :subtitle="t('equipment.count', equipment.total)">
            <template #actions>
                <Link
                    :href="workspaceUrl('/equipment')"
                    :style="{ fontSize: '11.5px', color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '5px', padding: '5px 9px' }"
                >
                    <Icon name="chevR" :size="10" :style="{ transform: 'rotate(180deg)' }" />
                    {{ t('equipment.back_to_list') }}
                </Link>
            </template>
        </PageTitle>

        <CmdDataTable
            :rows="equipment"
            :columns="columns"
            v-model:search="search"
            :searchable="false"
            :empty-text="t('equipment.trash_empty')"
        >
            <template #cell:category="{ row }">
                <span :style="{ color: row.category ? 'var(--fg-dim)' : 'var(--fg-mute)' }">{{ row.category || t('equipment.no_category') }}</span>
            </template>
            <template #cell:serial="{ row }">
                <span :style="{ color: 'var(--fg-dim)' }">{{ row.serial || '—' }}</span>
            </template>
            <template #cell:deleted_at="{ row }">
                <span :style="{ color: 'var(--fg-mute)' }">{{ fmt(row.deleted_at) }}</span>
            </template>

            <template #actions="{ row }">
                <button
                    v-if="can_manage"
                    type="button"
                    :title="t('equipment.restore')"
                    :aria-label="t('equipment.restore')"
                    :style="actionBtnStyle"
                    @click="restore(row)"
                ><Icon name="restore" :size="13" /></button>
                <button
                    v-if="can_manage"
                    type="button"
                    :title="t('equipment.force_delete')"
                    :aria-label="t('equipment.force_delete')"
                    :style="{ ...actionBtnStyle, color: 'var(--danger)' }"
                    @click="confirmForceDelete(row)"
                ><Icon name="trash" :size="13" /></button>
            </template>
        </CmdDataTable>
    </div>
</template>
