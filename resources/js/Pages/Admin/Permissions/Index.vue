<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from 'primevue/useconfirm';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CmdDataTable, { type Column } from '@/Components/Command/DataTable.vue';
import Icon from '@/Components/Command/Icon.vue';
import PageTitle from '@/Components/Command/PageTitle.vue';
import { useCommandToasts } from '@/composables/useCommandToasts';
import { useLiveReload } from '@/composables/useLiveReload';

defineOptions({ layout: CommandLayout });

// Live: refresh when any admin creates/deletes a permission elsewhere.
useLiveReload(() => 'admin.resources', { resource: 'permissions', only: ['permissions'] });

const { t } = useI18n();
const { push } = useCommandToasts();
const confirmer = useConfirm();

interface Permission {
    id: number;
    name: string;
    guard_name: string;
    roles_count: number;
}

defineProps<{ permissions: Permission[] }>();

const form = useForm({ name: '' });
const search = ref('');
const sortKey = ref<string>('name');
const sortDir = ref<'asc' | 'desc'>('asc');

const columns: Column<Permission>[] = [
    { key: 'name', label: t('common.name'), sortable: true },
    { key: 'guard_name', label: t('admin.permissions.guard'), sortable: true, width: '160px', mono: true },
    { key: 'roles_count', label: t('admin.permissions.roles'), sortable: true, width: '100px', align: 'right', mono: true },
];

function create() {
    form.post('/admin/permissions', {
        onSuccess: () => {
            form.reset();
            push(t('admin.permissions.toast_created'), 'success');
        },
    });
}

function destroy(p: Permission) {
    confirmer.require({
        group: 'command',
        message: t('admin.permissions.confirm_delete', { name: p.name }),
        header: t('common.delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: t('common.delete'),
        rejectLabel: t('common.cancel'),
        accept: () => {
            // Server flashes flash.permissions.deleted via useFlashToast.
            router.delete(`/admin/permissions/${p.id}`);
        },
    });
}
</script>

<template>
    <div>
    <Head :title="t('admin.permissions.head_title')" />

    <PageTitle :title="t('admin.permissions.title')">
        <template #subtitle>{{ permissions.length }} {{ t('admin.permissions.title').toLowerCase() }}</template>
    </PageTitle>

    <!-- Quick-add form -->
    <form
        @submit.prevent="create"
        :style="{
            display: 'flex',
            gap: '6px',
            alignItems: 'center',
            background: 'var(--panel)',
            border: '1px solid var(--border)',
            borderRadius: 'var(--radius-card)',
            padding: '10px 12px',
            marginBottom: '12px',
        }"
    >
        <input
            v-model="form.name"
            :placeholder="t('admin.permissions.new_permission')"
            :style="{
                flex: 1,
                maxWidth: '320px',
                background: 'var(--panel2)',
                border: '1px solid var(--border)',
                borderRadius: '5px',
                padding: '6px 10px',
                color: 'var(--fg)',
                fontSize: '12px',
                outline: 'none',
                fontFamily: 'inherit',
            }"
        />
        <button
            type="submit"
            :disabled="form.processing"
            :style="{
                background: 'var(--accent)',
                color: '#fff',
                border: 'none',
                padding: '6px 11px',
                borderRadius: '5px',
                fontSize: '11.5px',
                fontWeight: 500,
                cursor: form.processing ? 'not-allowed' : 'pointer',
                opacity: form.processing ? 0.6 : 1,
                fontFamily: 'inherit',
                display: 'inline-flex',
                alignItems: 'center',
                gap: '5px',
            }"
        >
            <Icon name="plus" :size="12" />
            {{ t('common.add') }}
        </button>
        <span
            v-if="form.errors.name"
            :style="{ color: 'var(--danger)', fontSize: '11px', marginLeft: '8px' }"
        >{{ form.errors.name }}</span>
    </form>

    <CmdDataTable
        :rows="permissions"
        :columns="columns"
        v-model:search="search"
        v-model:sort-key="sortKey"
        v-model:sort-dir="sortDir"
        :search-placeholder="t('admin.permissions.filter')"
        :search-keys="['name', 'guard_name']"
    >
        <template #cell:name="{ row }">
            <span :style="{ fontWeight: 500, color: 'var(--fg)' }">{{ row.name }}</span>
        </template>

        <template #actions="{ row }">
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
