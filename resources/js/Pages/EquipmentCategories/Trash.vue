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
import CategoryChip from '@/Components/Equipment/CategoryChip.vue';
import { useWorkspace } from '@/composables/useWorkspace';

defineOptions({ layout: CommandLayout });

const { t, locale } = useI18n();
const { workspaceUrl } = useWorkspace();
const confirm = useConfirm();

interface TrashedRow {
    id: number;
    name: string;
    color: string | null;
    equipment_count: number;
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
    categories: Paginated<TrashedRow>;
    can_manage: boolean;
}>();

const search = ref('');

const actionBtnStyle = { background: 'transparent', border: 'none', color: 'var(--fg-dim)', cursor: 'pointer', padding: '4px', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' };

const columns: Column<TrashedRow>[] = [
    { key: 'name', label: t('equipment_category.name'), width: 'minmax(150px, 2fr)' },
    { key: 'equipment_count', label: t('equipment_category.equipment_count'), mono: true, align: 'right', width: '110px' },
    { key: 'deleted_at', label: t('equipment_category.deleted_at'), mono: true, width: '170px' },
];

function restore(row: TrashedRow) {
    router.post(workspaceUrl(`/equipment-categories/trash/${row.id}/restore`), {}, { preserveScroll: true });
}

function confirmForceDelete(row: TrashedRow) {
    confirm.require({
        group: 'equipment-categories-trash',
        message: t('equipment_category.confirm_force_delete', { name: row.name }),
        header: t('equipment_category.force_delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('equipment_category.force_delete'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(workspaceUrl(`/equipment-categories/trash/${row.id}`), { preserveScroll: true }),
    });
}

function fmt(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString(locale.value) : '—';
}
</script>

<template>
    <div>
        <Head :title="t('equipment_category.trash_title')" />
        <ConfirmDialog group="equipment-categories-trash" />

        <PageTitle :title="t('equipment_category.trash_title')" :subtitle="t('equipment_category.count', categories.total)">
            <template #actions>
                <Link
                    :href="workspaceUrl('/equipment-categories')"
                    :style="{ fontSize: '11.5px', color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '5px', padding: '5px 9px' }"
                >
                    <Icon name="chevR" :size="10" :style="{ transform: 'rotate(180deg)' }" />
                    {{ t('equipment_category.back_to_list') }}
                </Link>
            </template>
        </PageTitle>

        <CmdDataTable
            :rows="categories"
            :columns="columns"
            v-model:search="search"
            :searchable="false"
            :empty-text="t('equipment_category.trash_empty')"
        >
            <template #cell:name="{ row }">
                <CategoryChip :category="row" />
            </template>
            <template #cell:equipment_count="{ row }">
                <span :style="{ color: 'var(--fg-dim)' }">{{ row.equipment_count }}</span>
            </template>
            <template #cell:deleted_at="{ row }">
                <span :style="{ color: 'var(--fg-mute)' }">{{ fmt(row.deleted_at) }}</span>
            </template>

            <template #actions="{ row }">
                <button
                    v-if="can_manage"
                    type="button"
                    :title="t('equipment_category.restore')"
                    :aria-label="t('equipment_category.restore')"
                    :style="actionBtnStyle"
                    @click="restore(row)"
                ><Icon name="restore" :size="13" /></button>
                <button
                    v-if="can_manage"
                    type="button"
                    :title="t('equipment_category.force_delete')"
                    :aria-label="t('equipment_category.force_delete')"
                    :style="{ ...actionBtnStyle, color: 'var(--danger)' }"
                    @click="confirmForceDelete(row)"
                ><Icon name="trash" :size="13" /></button>
            </template>
        </CmdDataTable>
    </div>
</template>
