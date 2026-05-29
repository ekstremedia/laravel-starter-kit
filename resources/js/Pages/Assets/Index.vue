<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CmdDataTable, { type Column } from '@/Components/Command/DataTable.vue';
import CommandDialog from '@/Components/Command/Dialog.vue';
import CmdButton from '@/Components/Command/Button.vue';
import Field from '@/Components/Command/Field.vue';
import Icon from '@/Components/Command/Icon.vue';
import { useWorkspace } from '@/composables/useWorkspace';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();
const { workspaceUrl } = useWorkspace();

interface AssetRow {
    id: number;
    name: string;
    category: string | null;
    serial: string | null;
    notes: string | null;
    file_quota_bytes: number | null;
    files_count: number;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page?: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

const props = defineProps<{
    assets: Paginated<AssetRow>;
    can_manage: boolean;
    search: string | null;
}>();

const search = ref(props.search ?? '');
const sortKey = ref<string>('name');
const sortDir = ref<'asc' | 'desc'>('asc');

const columns: Column<AssetRow>[] = [
    { key: 'name', label: t('assets.name'), sortable: true },
    { key: 'category', label: t('assets.category'), sortable: true },
    { key: 'serial', label: t('assets.serial'), sortable: true, mono: true },
    { key: 'files_count', label: t('assets.files_count'), sortable: true, width: '90px', align: 'right', mono: true },
];

function onSearch(value: string) {
    router.get(
        workspaceUrl('/assets'),
        { q: value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const createOpen = ref(false);
const form = useForm({
    name: '',
    category: '',
    serial: '',
    notes: '',
    file_quota_bytes: null as number | null,
});

function openCreate() {
    form.reset();
    form.clearErrors();
    createOpen.value = true;
}

function submitCreate() {
    form.post(workspaceUrl('/assets'), {
        onSuccess: () => { createOpen.value = false; },
    });
}
</script>

<template>
    <div>
        <Head :title="t('assets.head_title')" />

        <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: '14px' }">
            <div>
                <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">
                    {{ t('assets.title') }}
                </h1>
                <div class="cmd-mono" :style="{ marginTop: '3px', fontSize: '11.5px', color: 'var(--fg-mute)' }">
                    {{ t('assets.count', props.assets.total) }}
                </div>
            </div>
            <button
                v-if="can_manage"
                type="button"
                :style="{ background: 'var(--accent)', color: '#fff', border: 'none', padding: '5px 11px', borderRadius: '5px', fontSize: '11.5px', fontWeight: 500, display: 'inline-flex', alignItems: 'center', gap: '5px', cursor: 'pointer', fontFamily: 'inherit' }"
                @click="openCreate"
            >
                <Icon name="plus" :size="12" />
                {{ t('assets.new_asset') }}
            </button>
        </div>

        <CmdDataTable
            :rows="assets"
            :columns="columns"
            v-model:search="search"
            v-model:sort-key="sortKey"
            v-model:sort-dir="sortDir"
            :local-search="false"
            :local-sort="false"
            :search-placeholder="t('assets.search_placeholder')"
            :empty-text="t('assets.empty')"
            @update:search="onSearch"
        >
            <template #cell:name="{ row }">
                <Link
                    :href="workspaceUrl(`/assets/${row.id}`)"
                    :style="{ fontWeight: 500, color: 'var(--fg)', textDecoration: 'none' }"
                >{{ row.name }}</Link>
            </template>

            <template #cell:category="{ row }">
                <span :style="{ color: row.category ? 'var(--fg-dim)' : 'var(--fg-mute)' }">
                    {{ row.category || t('assets.no_category') }}
                </span>
            </template>

            <template #cell:serial="{ row }">
                <span :style="{ color: 'var(--fg-dim)' }">{{ row.serial || '—' }}</span>
            </template>
        </CmdDataTable>

        <CommandDialog
            v-model:visible="createOpen"
            :title="t('assets.new_asset')"
            width="480px"
        >
            <form
                @submit.prevent="submitCreate"
                :style="{ display: 'flex', flexDirection: 'column', gap: '12px' }"
            >
                <Field
                    v-model="form.name"
                    :label="t('assets.name')"
                    :error="form.errors.name"
                    required
                    autofocus
                />
                <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px' }">
                    <Field
                        v-model="form.category"
                        :label="t('assets.category')"
                        :error="form.errors.category"
                    />
                    <Field
                        v-model="form.serial"
                        :label="t('assets.serial')"
                        :error="form.errors.serial"
                    />
                </div>
                <div>
                    <label
                        class="cmd-mono cmd-uc"
                        :style="{ display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em', fontWeight: 500 }"
                    >{{ t('assets.notes') }}</label>
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        :placeholder="t('assets.notes_placeholder')"
                        :style="{ width: '100%', background: 'var(--panel2)', border: `1px solid ${form.errors.notes ? 'var(--danger)' : 'var(--border)'}`, borderRadius: '5px', padding: '8px 10px', color: 'var(--fg)', fontSize: '13px', outline: 'none', fontFamily: 'inherit', resize: 'vertical' }"
                    />
                    <div v-if="form.errors.notes" :style="{ color: 'var(--danger)', fontSize: '11px', marginTop: '4px' }">
                        {{ form.errors.notes }}
                    </div>
                </div>
                <Field
                    v-model="form.file_quota_bytes"
                    type="number"
                    :label="t('assets.storage_quota')"
                    :error="form.errors.file_quota_bytes"
                    :min="-1"
                    numeric
                />
                <p :style="{ margin: 0, fontSize: '11px', color: 'var(--fg-mute)' }">{{ t('assets.storage_quota_help') }}</p>
            </form>
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="createOpen = false">
                    {{ t('common.cancel') }}
                </CmdButton>
                <CmdButton variant="primary" size="sm" :loading="form.processing" @click="submitCreate">
                    <template #icon><Icon name="plus" :size="12" /></template>
                    {{ t('assets.create_asset') }}
                </CmdButton>
            </template>
        </CommandDialog>
    </div>
</template>
