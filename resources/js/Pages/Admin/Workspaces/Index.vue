<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from 'primevue/useconfirm';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CmdDataTable, { type Column } from '@/Components/Command/DataTable.vue';
import Icon from '@/Components/Command/Icon.vue';
import Dot from '@/Components/Command/Dot.vue';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();
const confirmer = useConfirm();

interface WorkspaceRow {
    id: number;
    slug: string;
    name: string;
    status: 'active' | 'suspended';
    users_count: number;
    created_at: string;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page?: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

const props = defineProps<{ workspaces: Paginated<WorkspaceRow> }>();

const search = ref('');
const sortKey = ref<string>('name');
const sortDir = ref<'asc' | 'desc'>('asc');

const columns: Column<WorkspaceRow>[] = [
    { key: 'name', label: t('common.name'), sortable: true },
    { key: 'slug', label: t('admin.workspaces.slug'), sortable: true, mono: true },
    { key: 'status', label: t('common.status'), sortable: true, width: '120px' },
    { key: 'users_count', label: t('admin.workspaces.members'), sortable: true, width: '100px', align: 'right', mono: true },
];

function destroy(c: WorkspaceRow) {
    confirmer.require({
        group: 'command',
        message: t('admin.workspaces.confirm_delete', { name: c.name }),
        header: t('common.delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: t('common.delete'),
        rejectLabel: t('common.cancel'),
        accept: () => {
            // Server flashes flash.workspaces.deleted via useFlashToast.
            router.delete(`/admin/workspaces/${c.id}`);
        },
    });
}
</script>

<template>
    <div>
    <Head :title="t('admin.workspaces.head_title')" />

    <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: '14px' }">
        <div>
            <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">
                {{ t('admin.workspaces.title') }}
            </h1>
            <div
                class="cmd-mono"
                :style="{ marginTop: '3px', fontSize: '11.5px', color: 'var(--fg-mute)' }"
            >{{ props.workspaces.total }} {{ t('admin.workspaces.title').toLowerCase() }}</div>
        </div>
        <Link
            href="/admin/workspaces/create"
            :style="{
                background: 'var(--accent)',
                color: '#fff',
                border: 'none',
                padding: '5px 11px',
                borderRadius: '5px',
                fontSize: '11.5px',
                fontWeight: 500,
                textDecoration: 'none',
                display: 'inline-flex',
                alignItems: 'center',
                gap: '5px',
            }"
        >
            <Icon name="plus" :size="12" />
            {{ t('admin.workspaces.new_workspace') }}
        </Link>
    </div>

    <CmdDataTable
        :rows="workspaces"
        :columns="columns"
        v-model:search="search"
        v-model:sort-key="sortKey"
        v-model:sort-dir="sortDir"
        :search-placeholder="t('admin.workspaces.filter')"
        :search-keys="['name', 'slug']"
    >
        <template #cell:name="{ row }">
            <Link
                :href="`/admin/workspaces/${row.id}/edit`"
                :style="{ fontWeight: 500, color: 'var(--fg)', textDecoration: 'none' }"
            >{{ row.name }}</Link>
        </template>

        <template #cell:slug="{ row }">
            <code
                class="cmd-mono"
                :style="{
                    fontSize: '10.5px',
                    background: 'var(--panel2)',
                    border: '1px solid var(--border)',
                    padding: '1px 6px',
                    borderRadius: '3px',
                    color: 'var(--fg-dim)',
                }"
            >/w/{{ row.slug }}</code>
        </template>

        <template #cell:status="{ row }">
            <span :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '11.5px' }">
                <Dot
                    :color="row.status === 'active' ? 'var(--success)' : 'var(--warning)'"
                    :size="6"
                />
                <span :style="{ color: row.status === 'active' ? 'var(--fg)' : 'var(--fg-dim)' }">
                    {{ row.status }}
                </span>
            </span>
        </template>

        <template #actions="{ row }">
            <Link
                :href="`/admin/workspaces/${row.id}/edit`"
                :title="t('common.edit')"
                :style="{ background: 'transparent', border: 'none', color: 'var(--fg-dim)', cursor: 'pointer', padding: '4px', borderRadius: '3px', display: 'flex', alignItems: 'center', justifyContent: 'center' }"
            >
                <Icon name="edit" :size="12" />
            </Link>
            <button
                type="button"
                :title="t('common.delete')"
                @click="destroy(row)"
                :style="{ background: 'transparent', border: 'none', color: 'var(--danger)', cursor: 'pointer', padding: '4px', borderRadius: '3px', display: 'flex', alignItems: 'center', justifyContent: 'center' }"
            >
                <Icon name="trash" :size="12" />
            </button>
        </template>
    </CmdDataTable>
    </div>
</template>
