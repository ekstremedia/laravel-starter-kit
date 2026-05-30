<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from 'primevue/useconfirm';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CmdDataTable, { type Column } from '@/Components/Command/DataTable.vue';
import Icon from '@/Components/Command/Icon.vue';
import PageTitle from '@/Components/Command/PageTitle.vue';
import { useCommandToasts } from '@/composables/useCommandToasts';
import { useLiveList } from '@/composables/useLiveList';
import { fetchJson } from '@/utils/fetchJson';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();
const { push } = useCommandToasts();
const confirmer = useConfirm();

interface Role {
    id: number;
    name: string;
    permissions: string[];
    users_count: number;
}

const props = defineProps<{ roles: Role[] }>();

// Live, surgical: a single changed role fetches only its own row and patches in
// place; bulk falls back to one reload. No-op when Echo is unavailable.
const liveRoles = useLiveList<Role>({
    channel: () => 'admin.resources',
    resource: 'roles',
    source: () => props.roles,
    fetchOne: (id) => fetchJson<Role>(`/admin/roles/${id}/live-row`),
    bulkReload: ['roles'],
});

const search = ref('');
const sortKey = ref<string>('name');
const sortDir = ref<'asc' | 'desc'>('asc');

const columns: Column<Role>[] = [
    { key: 'name', label: t('common.name'), sortable: true },
    { key: 'permissions', label: t('admin.roles.permissions'), sortable: false },
    { key: 'users_count', label: t('admin.roles.users'), sortable: true, width: '100px', align: 'right', mono: true },
];

function destroy(r: Role) {
    confirmer.require({
        group: 'command',
        message: t('admin.roles.confirm_delete', { name: r.name }),
        header: t('common.delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: t('common.delete'),
        rejectLabel: t('common.cancel'),
        accept: () => {
            // Server flashes flash.roles.deleted via useFlashToast.
            router.delete(`/admin/roles/${r.id}`);
        },
    });
}
</script>

<template>
    <div>
    <Head :title="t('admin.roles.head_title')" />

    <PageTitle :title="t('admin.roles.title')">
        <template #subtitle>{{ roles.length }} {{ t('admin.roles.title').toLowerCase() }}</template>
        <template #actions>
            <Link
                href="/admin/roles/create"
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
                {{ t('admin.roles.new_role') }}
            </Link>
        </template>
    </PageTitle>

    <CmdDataTable
        :rows="liveRoles"
        :columns="columns"
        v-model:search="search"
        v-model:sort-key="sortKey"
        v-model:sort-dir="sortDir"
        :search-placeholder="t('admin.roles.filter')"
        :search-keys="['name', 'permissions']"
    >
        <template #cell:name="{ row }">
            <Link
                :href="`/admin/roles/${row.id}/edit`"
                :style="{ fontWeight: 500, color: 'var(--fg)', textDecoration: 'none' }"
            >{{ row.name }}</Link>
        </template>

        <template #cell:permissions="{ row }">
            <div :style="{ display: 'flex', flexWrap: 'wrap', gap: '3px' }">
                <span
                    v-for="p in (row.permissions ?? []).slice(0, 6)"
                    :key="p"
                    class="cmd-mono"
                    :style="{
                        fontSize: '10px',
                        padding: '1px 6px',
                        background: 'var(--panel2)',
                        border: '1px solid var(--border)',
                        borderRadius: '3px',
                        color: 'var(--fg-dim)',
                    }"
                >{{ p }}</span>
                <span
                    v-if="(row.permissions ?? []).length > 6"
                    class="cmd-mono"
                    :style="{
                        fontSize: '10px',
                        color: 'var(--fg-mute)',
                        padding: '1px 4px',
                    }"
                >+{{ row.permissions.length - 6 }}</span>
            </div>
        </template>

        <template #actions="{ row }">
            <Link
                :href="`/admin/roles/${row.id}/edit`"
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
