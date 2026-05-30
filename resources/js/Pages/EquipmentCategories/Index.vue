<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from 'primevue/useconfirm';
import ConfirmDialog from 'primevue/confirmdialog';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CmdDataTable, { type Column } from '@/Components/Command/DataTable.vue';
import CommandDialog from '@/Components/Command/Dialog.vue';
import CmdButton from '@/Components/Command/Button.vue';
import Field from '@/Components/Command/Field.vue';
import Icon from '@/Components/Command/Icon.vue';
import PageTitle from '@/Components/Command/PageTitle.vue';
import CategoryChip from '@/Components/Equipment/CategoryChip.vue';
import ColorPicker from '@/Components/Equipment/ColorPicker.vue';
import { useWorkspace } from '@/composables/useWorkspace';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();
const { workspaceUrl } = useWorkspace();
const confirm = useConfirm();

interface CategoryRow {
    id: number;
    name: string;
    color: string | null;
    description: string | null;
    equipment_count: number;
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
    categories: Paginated<CategoryRow>;
    can_manage: boolean;
    search: string | null;
    stats: { total: number; with_equipment: number; total_equipment: number };
}>();

const menuItemStyle = { display: 'block', width: '100%', textAlign: 'left' as const, background: 'transparent', border: 'none', padding: '8px 12px', fontSize: '12px', color: 'var(--fg)', cursor: 'pointer', fontFamily: 'inherit' };
const fieldLabelStyle = { display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em', fontWeight: 500 as const };
const statCardStyle = { padding: '10px 16px', minWidth: '96px' };
const statValueStyle = { fontSize: '20px', fontWeight: 600, color: 'var(--fg)', lineHeight: 1.1 };
const statLabelStyle = { fontSize: '10.5px', color: 'var(--fg-mute)', textTransform: 'uppercase' as const, letterSpacing: '0.05em', marginTop: '3px' };

// ── Toolbar state (search / sort), round-tripped through the URL.
const urlParams = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
const search = ref(props.search ?? '');
const sortKey = ref<string>(urlParams.get('sort') ?? 'name');
const sortDir = ref<'asc' | 'desc'>((urlParams.get('direction') as 'asc' | 'desc') ?? 'asc');

function reload(overrides: Record<string, string | undefined> = {}) {
    clearSelection();
    const sorted = sortKey.value !== 'name' || sortDir.value !== 'asc';
    const query: Record<string, string | undefined> = {
        q: search.value || undefined,
        sort: sorted ? sortKey.value : undefined,
        direction: sorted ? sortDir.value : undefined,
        ...overrides,
    };
    router.get(workspaceUrl('/equipment-categories'), query, { preserveState: true, preserveScroll: true, replace: true });
}

function onSearch(value: string) {
    search.value = value;
    reload();
}
function onSort(payload: { key: string; dir: 'asc' | 'desc' }) {
    sortKey.value = payload.key;
    sortDir.value = payload.dir;
    reload();
}

// ── Column visibility (persisted). `name` is always shown.
const TOGGLEABLE = ['description', 'equipment_count'];
const STORAGE_KEY = 'equipment_categories.columns';
function readHiddenColumns(): string[] {
    if (typeof window === 'undefined') return [];
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);
        return stored ? JSON.parse(stored) : [];
    } catch {
        return [];
    }
}
const hidden = ref<Set<string>>(new Set(readHiddenColumns()));
const columnsOpen = ref(false);

const allColumns: Column<CategoryRow>[] = [
    { key: 'name', label: t('equipment_category.name'), sortable: true, width: 'minmax(150px, 2fr)' },
    { key: 'description', label: t('equipment_category.description'), sortable: false, width: 'minmax(0, 3fr)' },
    { key: 'equipment_count', label: t('equipment_category.equipment_count'), sortable: true, width: '110px', align: 'right', mono: true },
];
const columns = computed<Column<CategoryRow>[]>(() => allColumns.filter((c) => c.key === 'name' || !hidden.value.has(c.key)));

function toggleColumn(key: string) {
    const next = new Set(hidden.value);
    next.has(key) ? next.delete(key) : next.add(key);
    hidden.value = next;
    localStorage.setItem(STORAGE_KEY, JSON.stringify([...next]));
}

// ── Selection + bulk delete (categories own no files, so no download/zip).
const selected = ref<Set<number | string>>(new Set());
const allMatching = ref(false);
const selectedIds = computed(() => [...selected.value].map(Number));

const total = computed(() => props.categories.total);
const pageIds = computed(() => props.categories.data.map((r) => r.id));
const allOnPageSelected = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.has(id)));
const selectedCount = computed(() => (allMatching.value ? total.value : selected.value.size));
const showSelectAllMatching = computed(() => !allMatching.value && allOnPageSelected.value && total.value > selected.value.size);
const displaySelected = computed<Set<number | string>>(() => (allMatching.value ? new Set(pageIds.value) : selected.value));

function onUpdateSelected(s: Set<number | string>) {
    selected.value = s;
    allMatching.value = false;
}
function selectAllMatching() {
    allMatching.value = true;
}
function clearSelection() {
    selected.value = new Set();
    allMatching.value = false;
}

function filterParams(): Record<string, string> {
    const p: Record<string, string> = {};
    if (search.value) p.q = search.value;
    return p;
}
function bulkBody(extra: Record<string, unknown> = {}) {
    return allMatching.value ? { all: 1, ...filterParams(), ...extra } : { ids: selectedIds.value, ...extra };
}

function confirmBulkDelete() {
    confirm.require({
        group: 'equipment-categories-index',
        message: t('equipment_category.confirm_bulk_delete', { count: selectedCount.value }),
        header: t('equipment_category.bulk_delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('equipment_category.bulk_delete'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.post(
            workspaceUrl('/equipment-categories/bulk/delete'),
            bulkBody(),
            { preserveScroll: true, onSuccess: clearSelection },
        ),
    });
}

// ── Create.
const createOpen = ref(false);
const form = useForm<{ name: string; color: string | null; description: string }>({ name: '', color: null, description: '' });

function openCreate() {
    form.reset();
    form.clearErrors();
    createOpen.value = true;
}
function submitCreate() {
    form.post(workspaceUrl('/equipment-categories'), {
        onSuccess: () => { createOpen.value = false; },
    });
}

// ── Export (CSV / XLSX — no ZIP, categories own no documents).
const exportOpen = ref(false);
function doExport(format: 'csv' | 'xlsx') {
    exportOpen.value = false;
    const params = new URLSearchParams();
    if (search.value) params.set('q', search.value);
    params.set('format', format);
    window.location.href = workspaceUrl(`/equipment-categories/export?${params.toString()}`);
}

const exportWrap = ref<HTMLElement | null>(null);
const columnsWrap = ref<HTMLElement | null>(null);
function onDocPointerDown(e: PointerEvent) {
    const target = e.target as Node;
    if (exportOpen.value && exportWrap.value && !exportWrap.value.contains(target)) exportOpen.value = false;
    if (columnsOpen.value && columnsWrap.value && !columnsWrap.value.contains(target)) columnsOpen.value = false;
}
function onEscape(e: KeyboardEvent) {
    if (e.key === 'Escape') { exportOpen.value = false; columnsOpen.value = false; }
}
onMounted(() => {
    document.addEventListener('pointerdown', onDocPointerDown);
    document.addEventListener('keydown', onEscape);
});
onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocPointerDown);
    document.removeEventListener('keydown', onEscape);
});
</script>

<template>
    <div>
        <Head :title="t('equipment_category.head_title')" />
        <ConfirmDialog group="equipment-categories-index" />

        <PageTitle :title="t('equipment_category.title')" :subtitle="t('equipment_category.count', props.categories.total)">
            <template #actions>
                <Link
                    :href="workspaceUrl('/equipment-categories/trash')"
                    :style="{ fontSize: '11.5px', color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '5px', padding: '5px 9px' }"
                >
                    <Icon name="trash" :size="12" />
                    {{ t('equipment_category.trash') }}
                </Link>

                <!-- Export -->
                <div ref="exportWrap" :style="{ position: 'relative' }">
                    <button
                        type="button"
                        aria-haspopup="menu"
                        :aria-expanded="exportOpen"
                        :style="{ background: 'var(--panel2)', color: 'var(--fg)', border: '1px solid var(--border)', padding: '5px 11px', borderRadius: '5px', fontSize: '11.5px', display: 'inline-flex', alignItems: 'center', gap: '5px', cursor: 'pointer', fontFamily: 'inherit' }"
                        @click="exportOpen = !exportOpen"
                    >
                        <i class="pi pi-download" :style="{ fontSize: '12px' }" />
                        {{ t('equipment_category.export') }}
                        <Icon name="chevD" :size="9" />
                    </button>
                    <div
                        v-if="exportOpen"
                        role="menu"
                        :style="{ position: 'absolute', right: 0, top: 'calc(100% + 4px)', background: 'var(--panel)', border: '1px solid var(--border)', borderRadius: '6px', boxShadow: 'var(--shadow-palette, 0 10px 30px rgba(0,0,0,0.3))', zIndex: 30, minWidth: '160px', overflow: 'hidden' }"
                    >
                        <button type="button" role="menuitem" class="cmd-menu-item" :style="menuItemStyle" @click="doExport('csv')">{{ t('equipment_category.export_csv') }}</button>
                        <button type="button" role="menuitem" class="cmd-menu-item" :style="menuItemStyle" @click="doExport('xlsx')">{{ t('equipment_category.export_xlsx') }}</button>
                    </div>
                </div>

                <button
                    v-if="can_manage"
                    type="button"
                    :style="{ background: 'var(--accent)', color: 'var(--accent-fg)', border: 'none', padding: '5px 11px', borderRadius: '5px', fontSize: '11.5px', fontWeight: 500, display: 'inline-flex', alignItems: 'center', gap: '5px', cursor: 'pointer', fontFamily: 'inherit' }"
                    @click="openCreate"
                >
                    <Icon name="plus" :size="12" />
                    {{ t('equipment_category.new') }}
                </button>
            </template>
        </PageTitle>

        <!-- Stats strip -->
        <div :style="{ display: 'flex', flexWrap: 'wrap', gap: '10px', marginBottom: '14px' }">
            <div class="cmd-card" :style="statCardStyle">
                <div :style="statValueStyle">{{ props.stats.total }}</div>
                <div :style="statLabelStyle">{{ t('equipment_category.stat_total') }}</div>
            </div>
            <div class="cmd-card" :style="statCardStyle">
                <div :style="statValueStyle">{{ props.stats.with_equipment }}</div>
                <div :style="statLabelStyle">{{ t('equipment_category.stat_with_equipment') }}</div>
            </div>
            <div class="cmd-card" :style="statCardStyle">
                <div :style="statValueStyle">{{ props.stats.total_equipment }}</div>
                <div :style="statLabelStyle">{{ t('equipment_category.stat_total_equipment') }}</div>
            </div>
        </div>

        <!-- Toolbar: column toggle -->
        <div :style="{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '10px', flexWrap: 'wrap' }">
            <div ref="columnsWrap" :style="{ position: 'relative' }">
                <button
                    type="button"
                    aria-haspopup="menu"
                    :aria-expanded="columnsOpen"
                    :style="{ background: 'var(--panel2)', color: 'var(--fg)', border: '1px solid var(--border)', padding: '5px 11px', borderRadius: '5px', fontSize: '11.5px', display: 'inline-flex', alignItems: 'center', gap: '5px', cursor: 'pointer', fontFamily: 'inherit' }"
                    @click="columnsOpen = !columnsOpen"
                >
                    <Icon name="cog" :size="12" />
                    {{ t('equipment_category.columns') }}
                </button>
                <div
                    v-if="columnsOpen"
                    role="menu"
                    :style="{ position: 'absolute', left: 0, top: 'calc(100% + 4px)', background: 'var(--panel)', border: '1px solid var(--border)', borderRadius: '6px', boxShadow: 'var(--shadow-palette, 0 10px 30px rgba(0,0,0,0.3))', zIndex: 30, padding: '6px', minWidth: '160px' }"
                >
                    <label
                        v-for="col in allColumns.filter((c) => TOGGLEABLE.includes(c.key))"
                        :key="col.key"
                        :style="{ display: 'flex', alignItems: 'center', gap: '8px', padding: '5px 7px', fontSize: '12px', color: 'var(--fg)', cursor: 'pointer', borderRadius: '4px' }"
                    >
                        <input type="checkbox" :checked="!hidden.has(col.key)" :style="{ accentColor: 'var(--accent)' }" @change="toggleColumn(col.key)" />
                        {{ col.label }}
                    </label>
                </div>
            </div>
        </div>

        <CmdDataTable
            :rows="categories"
            :columns="columns"
            v-model:search="search"
            v-model:sort-key="sortKey"
            v-model:sort-dir="sortDir"
            :local-search="false"
            :local-sort="false"
            :selectable="can_manage"
            :selected="displaySelected"
            :search-placeholder="t('equipment_category.search_placeholder')"
            :empty-text="t('equipment_category.empty')"
            @update:search="onSearch"
            @update:selected="onUpdateSelected"
            @sort="onSort"
        >
            <template #cell:name="{ row }">
                <Link
                    :href="workspaceUrl(`/equipment-categories/${row.id}`)"
                    :style="{ textDecoration: 'none' }"
                >
                    <CategoryChip :category="row" />
                </Link>
            </template>

            <template #cell:description="{ row }">
                <span :style="{ color: row.description ? 'var(--fg-dim)' : 'var(--fg-mute)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block' }">
                    {{ row.description || '—' }}
                </span>
            </template>

            <template #cell:equipment_count="{ row }">
                <Link
                    :href="workspaceUrl(`/equipment?category=${row.id}`)"
                    :style="{ color: row.equipment_count ? 'var(--accent)' : 'var(--fg-mute)', textDecoration: 'none' }"
                >{{ row.equipment_count }}</Link>
            </template>
        </CmdDataTable>

        <!-- Mass action bar -->
        <Transition
            enter-active-class="transition ease-out duration-150"
            enter-from-class="opacity-0 translate-y-2"
            leave-active-class="transition ease-in duration-100"
            leave-to-class="opacity-0 translate-y-2"
        >
            <div v-if="selectedCount" class="cmd-bulk-bar">
                <span class="cmd-bulk-count">{{ t('equipment_category.selected_of', { count: selectedCount, total }) }}</span>
                <button
                    v-if="showSelectAllMatching"
                    type="button"
                    class="cmd-bulk-link"
                    @click="selectAllMatching"
                >{{ t('equipment_category.select_all_matching', { total }) }}</button>
                <div :style="{ display: 'flex', alignItems: 'center', gap: '6px' }">
                    <button v-if="can_manage" type="button" class="cmd-bulk-btn cmd-bulk-danger" @click="confirmBulkDelete">
                        <Icon name="trash" :size="14" /><span>{{ t('equipment_category.bulk_delete') }}</span>
                    </button>
                    <button type="button" class="cmd-bulk-btn cmd-bulk-ghost" :aria-label="t('equipment_category.clear_selection')" @click="clearSelection">
                        <Icon name="x" :size="14" />
                    </button>
                </div>
            </div>
        </Transition>

        <!-- Create dialog -->
        <CommandDialog v-model:visible="createOpen" :title="t('equipment_category.new')" width="480px">
            <form @submit.prevent="submitCreate" :style="{ display: 'flex', flexDirection: 'column', gap: '12px' }">
                <Field v-model="form.name" :label="t('equipment_category.name')" :error="form.errors.name" required autofocus />
                <div>
                    <label class="cmd-mono cmd-uc" :style="fieldLabelStyle">{{ t('equipment_category.color') }}</label>
                    <ColorPicker v-model="form.color" />
                    <div v-if="form.errors.color" :style="{ color: 'var(--danger)', fontSize: '11px', marginTop: '4px' }">{{ form.errors.color }}</div>
                </div>
                <div>
                    <label class="cmd-mono cmd-uc" :style="fieldLabelStyle">{{ t('equipment_category.description') }}</label>
                    <textarea
                        v-model="form.description"
                        rows="3"
                        :placeholder="t('equipment_category.description_placeholder')"
                        :style="{ width: '100%', background: 'var(--panel2)', border: `1px solid ${form.errors.description ? 'var(--danger)' : 'var(--border)'}`, borderRadius: '5px', padding: '8px 10px', color: 'var(--fg)', fontSize: '13px', outline: 'none', fontFamily: 'inherit', resize: 'vertical' }"
                    />
                    <div v-if="form.errors.description" :style="{ color: 'var(--danger)', fontSize: '11px', marginTop: '4px' }">{{ form.errors.description }}</div>
                </div>
                <button type="submit" :style="{ display: 'none' }" aria-hidden="true" />
            </form>
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="createOpen = false">{{ t('common.cancel') }}</CmdButton>
                <CmdButton variant="primary" size="sm" :loading="form.processing" @click="submitCreate">
                    <template #icon><Icon name="plus" :size="12" /></template>
                    {{ t('equipment_category.create') }}
                </CmdButton>
            </template>
        </CommandDialog>
    </div>
</template>

<style scoped>
.cmd-menu-item:hover {
    background: var(--row-hover);
}
.cmd-bulk-bar {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 40;
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 8px 10px 8px 16px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: var(--shadow-palette, 0 10px 40px rgba(0, 0, 0, 0.35));
}
.cmd-bulk-count {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--fg);
    white-space: nowrap;
}
.cmd-bulk-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--panel2);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 6px 11px;
    font-size: 12px;
    font-family: inherit;
    color: var(--fg);
    cursor: pointer;
    transition: background 0.12s, border-color 0.12s;
}
.cmd-bulk-btn:hover {
    background: var(--panel);
    border-color: var(--accent-border);
}
.cmd-bulk-danger {
    color: var(--danger);
}
.cmd-bulk-ghost {
    padding: 6px;
}
.cmd-bulk-link {
    background: transparent;
    border: none;
    color: var(--accent);
    font-size: 12px;
    font-family: inherit;
    cursor: pointer;
    padding: 0;
    white-space: nowrap;
}
.cmd-bulk-link:hover {
    text-decoration: underline;
}
</style>
