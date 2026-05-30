<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from 'primevue/useconfirm';
import ConfirmDialog from 'primevue/confirmdialog';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CmdDataTable, { type Column } from '@/Components/Command/DataTable.vue';
import CommandDialog from '@/Components/Command/Dialog.vue';
import CmdButton from '@/Components/Command/Button.vue';
import Field from '@/Components/Command/Field.vue';
import Icon from '@/Components/Command/Icon.vue';
import MenuDropdown from '@/Components/Command/MenuDropdown.vue';
import CategoryChip from '@/Components/Equipment/CategoryChip.vue';
import PageTitle from '@/Components/Command/PageTitle.vue';
import ImageLightbox from '@/Components/Files/ImageLightbox.vue';
import FileDetailsDialog from '@/Components/Files/FileDetailsDialog.vue';
import TextPreviewDialog from '@/Components/Files/TextPreviewDialog.vue';
import { useWorkspace } from '@/composables/useWorkspace';
import { useModuleFeatures } from '@/composables/useModuleFeatures';
import { useFileMedia, type MediaFileRow } from '@/composables/useFileMedia';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();
const { workspaceUrl } = useWorkspace();
const confirm = useConfirm();

// Files are composable per module/workspace — hide the document column + the
// file-only actions (download, ZIP export) when files are disabled.
const { filesEnabled } = useModuleFeatures('equipment');

interface CategoryRef {
    id: number;
    name: string;
    color: string | null;
}

interface EquipmentRow {
    id: number;
    name: string;
    category: CategoryRef | null;
    serial: string | null;
    files_count: number;
    files_preview: MediaFileRow[];
    cover: MediaFileRow | null;
}

interface CategoryOption {
    value: number;
    label: string;
    color: string | null;
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
    equipment: Paginated<EquipmentRow>;
    can_manage: boolean;
    search: string | null;
    categories: CategoryOption[];
    stats: { total: number; with_files: number; by_category: { label: string; count: number; color: string | null }[] };
}>();

// Inline-style fragments reused across the template.
const menuItemStyle = { display: 'block', width: '100%', textAlign: 'left' as const, background: 'transparent', border: 'none', padding: '8px 12px', fontSize: '12px', color: 'var(--fg)', cursor: 'pointer', fontFamily: 'inherit' };
const fieldLabelStyle = { display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em', fontWeight: 500 as const };
const statCardStyle = { padding: '10px 16px', minWidth: '96px' };
const statValueStyle = { fontSize: '20px', fontWeight: 600, color: 'var(--fg)', lineHeight: 1.1 };
const statLabelStyle = { fontSize: '10.5px', color: 'var(--fg-mute)', textTransform: 'uppercase' as const, letterSpacing: '0.05em', marginTop: '3px' };
const coverBoxStyle = { flexShrink: 0, width: '30px', height: '30px', borderRadius: '5px', overflow: 'hidden', background: 'var(--panel2)', border: '1px solid var(--border)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' };
const thumbBtnStyle = { flexShrink: 0, width: '30px', height: '30px', borderRadius: '5px', overflow: 'hidden', background: 'var(--panel2)', border: '1px solid var(--border)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', padding: 0 };

// ── Toolbar state (search / category / sort), round-tripped through the URL.
const urlParams = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
const search = ref(props.search ?? '');
// The category filter carries a category id (the picker's value); '' = all.
const category = ref<number | ''>(urlParams.get('category') ? Number(urlParams.get('category')) : '');
const sortKey = ref<string>(urlParams.get('sort') ?? 'name');
const sortDir = ref<'asc' | 'desc'>((urlParams.get('direction') as 'asc' | 'desc') ?? 'asc');

function reload(overrides: Record<string, string | undefined> = {}) {
    // The matching set changes when filters/sort change, so a carried-over
    // selection would be meaningless — reset it. (Pagination goes through the
    // DataTable directly and keeps the selection, so "select all" survives it.)
    clearSelection();
    const sorted = sortKey.value !== 'name' || sortDir.value !== 'asc';
    const query: Record<string, string | undefined> = {
        q: search.value || undefined,
        category: category.value !== '' ? String(category.value) : undefined,
        sort: sorted ? sortKey.value : undefined,
        direction: sorted ? sortDir.value : undefined,
        ...overrides,
    };
    router.get(workspaceUrl('/equipment'), query, { preserveState: true, preserveScroll: true, replace: true });
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

function onCategory(value: number | '') {
    category.value = value;
    reload();
}

// ── Column visibility (persisted). `name` is always shown.
const TOGGLEABLE = ['files', 'category', 'serial', 'files_count'];
const STORAGE_KEY = 'equipment.columns';
function readHiddenColumns(): string[] {
    if (typeof window === 'undefined') return [];
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);
        const parsed = stored ? JSON.parse(stored) : [];
        return Array.isArray(parsed) ? parsed.filter((k): k is string => typeof k === 'string') : [];
    } catch {
        return [];
    }
}
const hidden = ref<Set<string>>(new Set(readHiddenColumns()));
const columnsOpen = ref(false);

const allColumns: Column<EquipmentRow>[] = [
    // Name takes priority and never collapses below a readable floor; the
    // preview column is capped so it can't starve the name on narrow widths.
    { key: 'name', label: t('equipment.name'), sortable: true, width: 'minmax(150px, 2fr)' },
    { key: 'files', label: t('equipment.documents'), sortable: false, width: 'minmax(0, 150px)' },
    { key: 'category', label: t('equipment.category'), sortable: true, width: 'minmax(0, 1fr)' },
    { key: 'serial', label: t('equipment.serial'), sortable: true, mono: true, width: 'minmax(0, 1fr)' },
    { key: 'files_count', label: t('equipment.files_count'), sortable: true, width: '80px', align: 'right', mono: true },
];

// File columns drop out entirely when files are disabled for the module.
const fileColumns = ['files', 'files_count'];
const columns = computed<Column<EquipmentRow>[]>(() =>
    allColumns.filter((c) => {
        if (!filesEnabled.value && fileColumns.includes(c.key)) return false;
        return c.key === 'name' || !hidden.value.has(c.key);
    }),
);
const toggleableColumns = computed(() =>
    allColumns.filter((c) => TOGGLEABLE.includes(c.key) && (filesEnabled.value || !fileColumns.includes(c.key))),
);

function toggleColumn(key: string) {
    const next = new Set(hidden.value);
    next.has(key) ? next.delete(key) : next.add(key);
    hidden.value = next;
    localStorage.setItem(STORAGE_KEY, JSON.stringify([...next]));
}

// ── Selection + mass actions, with an optional "select all matching" mode that
// spans every page of the current filter (not just the visible page).
const selected = ref<Set<number | string>>(new Set());
const allMatching = ref(false);
const selectedIds = computed(() => [...selected.value].map(Number));

const total = computed(() => props.equipment.total);
const pageIds = computed(() => props.equipment.data.map((r) => r.id));
const allOnPageSelected = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.has(id)));
const selectedCount = computed(() => (allMatching.value ? total.value : selected.value.size));
// Offer "select all N" once the whole page is ticked but more rows exist beyond it.
const showSelectAllMatching = computed(() => !allMatching.value && allOnPageSelected.value && total.value > selected.value.size);
// When all-matching, render every visible row checked (even after paging on).
const displaySelected = computed<Set<number | string>>(() => (allMatching.value ? new Set(pageIds.value) : selected.value));

function onUpdateSelected(s: Set<number | string>) {
    selected.value = s;
    allMatching.value = false; // any manual change drops "all matching"
}
function selectAllMatching() {
    allMatching.value = true;
}
function clearSelection() {
    selected.value = new Set();
    allMatching.value = false;
}

// The filter params that define "all matching" for the bulk endpoints.
function filterParams(): Record<string, string> {
    const p: Record<string, string> = {};
    if (search.value) p.q = search.value;
    if (category.value !== '') p.category = String(category.value);
    return p;
}
function bulkBody(extra: Record<string, unknown> = {}) {
    return allMatching.value ? { all: 1, ...filterParams(), ...extra } : { ids: selectedIds.value, ...extra };
}

function bulkDownload() {
    if (!selectedCount.value) return;
    const params = new URLSearchParams(
        allMatching.value ? { all: '1', ...filterParams() } : { ids: selectedIds.value.join(',') },
    );
    window.location.href = workspaceUrl(`/equipment/bulk/zip?${params.toString()}`);
}

function confirmBulkDelete() {
    confirm.require({
        group: 'equipment-index',
        message: t('equipment.confirm_bulk_delete', { count: selectedCount.value }),
        header: t('equipment.bulk_delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('equipment.bulk_delete'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.post(
            workspaceUrl('/equipment/bulk/delete'),
            bulkBody(),
            { preserveScroll: true, onSuccess: clearSelection },
        ),
    });
}

// Bulk edit (re-categorize). The picker value is a category id, or '' to clear.
const bulkEditOpen = ref(false);
const bulkCategory = ref<number | ''>('');
function openBulkEdit() {
    bulkCategory.value = '';
    bulkEditOpen.value = true;
}
function submitBulkEdit() {
    router.post(
        workspaceUrl('/equipment/bulk/update'),
        bulkBody({ category_id: bulkCategory.value === '' ? null : bulkCategory.value }),
        { preserveScroll: true, onSuccess: () => { bulkEditOpen.value = false; clearSelection(); } },
    );
}

// ── Create.
const createOpen = ref(false);
const form = useForm<{ name: string; equipment_category_id: number | ''; serial: string; notes: string }>({
    name: '',
    equipment_category_id: '',
    serial: '',
    notes: '',
});

function openCreate() {
    form.reset();
    form.clearErrors();
    createOpen.value = true;
}

function submitCreate() {
    form.post(workspaceUrl('/equipment'), {
        onSuccess: () => { createOpen.value = false; },
    });
}

// ── Export.
const exportOpen = ref(false);
function doExport(format: 'csv' | 'xlsx') {
    exportOpen.value = false;
    const params = new URLSearchParams();
    if (search.value) params.set('q', search.value);
    if (category.value !== '') params.set('category', String(category.value));
    params.set('format', format);
    window.location.href = workspaceUrl(`/equipment/export?${params.toString()}`);
}
// Download every matching item's documents as one ZIP (reuses the bulk-zip
// endpoint in "all matching" mode, so it honours the current search/filter).
function doExportZip() {
    exportOpen.value = false;
    const params = new URLSearchParams({ all: '1', ...filterParams() });
    window.location.href = workspaceUrl(`/equipment/bulk/zip?${params.toString()}`);
}

// ── Accessible popovers: close the Export/Columns menus on Escape or an
// outside click (no @mouseleave, which is mouse-only and traps keyboard users).
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

// ── Per-row file lightbox. One useFileMedia instance, fed the clicked row's files.
const lightboxRow = ref<EquipmentRow | null>(null);
const lightboxFiles = computed<MediaFileRow[]>(() => lightboxRow.value?.files_preview ?? []);
const media = useFileMedia<MediaFileRow>({
    items: lightboxFiles,
    downloadUrl: (i) => workspaceUrl(`/entity-files/${i.id}/download`),
});
const { lightboxIndex, lightboxItems, detailsItem, textItem, openDetailsById } = media;

function openPreview(row: EquipmentRow, file: MediaFileRow) {
    lightboxRow.value = row;
    nextTick(() => media.openFile(file));
}

function iconFor(file: MediaFileRow): string {
    if (file.is_video) return 'pi-video';
    if (file.is_audio) return 'pi-volume-up';
    if (file.is_markdown || file.is_text) return 'pi-file-edit';
    if (file.mime_type === 'application/pdf') return 'pi-file-pdf';
    return 'pi-file';
}
</script>

<template>
    <div>
        <Head :title="t('equipment.head_title')" />
        <ConfirmDialog group="equipment-index" />

        <PageTitle :title="t('equipment.title')" :subtitle="t('equipment.count', props.equipment.total)">
            <template #actions>
                <Link
                    :href="workspaceUrl('/equipment/trash')"
                    :style="{ fontSize: '11.5px', color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '5px', padding: '5px 9px' }"
                >
                    <Icon name="trash" :size="12" />
                    {{ t('equipment.trash') }}
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
                        {{ t('equipment.export') }}
                        <Icon name="chevD" :size="9" />
                    </button>
                    <div
                        v-if="exportOpen"
                        role="menu"
                        :style="{ position: 'absolute', right: 0, top: 'calc(100% + 4px)', background: 'var(--panel)', border: '1px solid var(--border)', borderRadius: '6px', boxShadow: 'var(--shadow-palette, 0 10px 30px rgba(0,0,0,0.3))', zIndex: 30, minWidth: '160px', overflow: 'hidden' }"
                    >
                        <button type="button" role="menuitem" class="cmd-menu-item" :style="menuItemStyle" @click="doExport('csv')">{{ t('equipment.export_csv') }}</button>
                        <button type="button" role="menuitem" class="cmd-menu-item" :style="menuItemStyle" @click="doExport('xlsx')">{{ t('equipment.export_xlsx') }}</button>
                        <button v-if="filesEnabled" type="button" role="menuitem" class="cmd-menu-item" :style="menuItemStyle" @click="doExportZip">{{ t('equipment.export_zip') }}</button>
                    </div>
                </div>

                <button
                    v-if="can_manage"
                    type="button"
                    :style="{ background: 'var(--accent)', color: 'var(--accent-fg)', border: 'none', padding: '5px 11px', borderRadius: '5px', fontSize: '11.5px', fontWeight: 500, display: 'inline-flex', alignItems: 'center', gap: '5px', cursor: 'pointer', fontFamily: 'inherit' }"
                    @click="openCreate"
                >
                    <Icon name="plus" :size="12" />
                    {{ t('equipment.new_asset') }}
                </button>
            </template>
        </PageTitle>

        <!-- Stats strip -->
        <div :style="{ display: 'flex', flexWrap: 'wrap', gap: '10px', marginBottom: '14px' }">
            <div class="cmd-card" :style="statCardStyle">
                <div :style="statValueStyle">{{ props.stats.total }}</div>
                <div :style="statLabelStyle">{{ t('equipment.stat_total') }}</div>
            </div>
            <div v-if="filesEnabled" class="cmd-card" :style="statCardStyle">
                <div :style="statValueStyle">{{ props.stats.with_files }}</div>
                <div :style="statLabelStyle">{{ t('equipment.stat_with_files') }}</div>
            </div>
            <div class="cmd-card" :style="statCardStyle">
                <div :style="statValueStyle">{{ props.stats.by_category.length }}</div>
                <div :style="statLabelStyle">{{ t('equipment.stat_categories') }}</div>
            </div>
        </div>

        <!-- Toolbar: category filter + column toggle -->
        <div :style="{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '10px', flexWrap: 'wrap' }">
            <MenuDropdown
                :model-value="category"
                :options="props.categories"
                :placeholder="t('equipment.all_categories')"
                :include-empty="true"
                :aria-label="t('equipment.all_categories')"
                @update:model-value="onCategory($event as number | '')"
            />

            <div ref="columnsWrap" :style="{ position: 'relative' }">
                <button
                    type="button"
                    aria-haspopup="menu"
                    :aria-expanded="columnsOpen"
                    :style="{ background: 'var(--panel2)', color: 'var(--fg)', border: '1px solid var(--border)', padding: '5px 11px', borderRadius: '5px', fontSize: '11.5px', display: 'inline-flex', alignItems: 'center', gap: '5px', cursor: 'pointer', fontFamily: 'inherit' }"
                    @click="columnsOpen = !columnsOpen"
                >
                    <Icon name="cog" :size="12" />
                    {{ t('equipment.columns') }}
                </button>
                <div
                    v-if="columnsOpen"
                    role="menu"
                    :style="{ position: 'absolute', left: 0, top: 'calc(100% + 4px)', background: 'var(--panel)', border: '1px solid var(--border)', borderRadius: '6px', boxShadow: 'var(--shadow-palette, 0 10px 30px rgba(0,0,0,0.3))', zIndex: 30, padding: '6px', minWidth: '160px' }"
                >
                    <label
                        v-for="col in toggleableColumns"
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
            :rows="equipment"
            :columns="columns"
            v-model:search="search"
            v-model:sort-key="sortKey"
            v-model:sort-dir="sortDir"
            :local-search="false"
            :local-sort="false"
            :selectable="can_manage"
            :selected="displaySelected"
            :search-placeholder="t('equipment.search_placeholder')"
            :empty-text="t('equipment.empty')"
            @update:search="onSearch"
            @update:selected="onUpdateSelected"
            @sort="onSort"
        >
            <template #cell:name="{ row }">
                <div :style="{ display: 'flex', alignItems: 'center', gap: '9px', minWidth: 0 }">
                    <span :style="coverBoxStyle">
                        <img v-if="row.cover?.thumbnail_url" :src="row.cover.thumbnail_url" :alt="row.name" :style="{ width: '100%', height: '100%', objectFit: 'cover' }" loading="lazy" />
                        <Icon v-else name="box" :size="13" :style="{ color: 'var(--fg-mute)' }" />
                    </span>
                    <Link
                        :href="workspaceUrl(`/equipment/${row.id}`)"
                        :style="{ fontWeight: 500, color: 'var(--fg)', textDecoration: 'none', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }"
                    >{{ row.name }}</Link>
                </div>
            </template>

            <template #cell:files="{ row }">
                <div v-if="row.files_preview.length" :style="{ display: 'flex', alignItems: 'center', gap: '4px' }">
                    <button
                        v-for="file in row.files_preview"
                        :key="file.id"
                        type="button"
                        :title="file.name"
                        :style="thumbBtnStyle"
                        @click.stop="openPreview(row, file)"
                    >
                        <img v-if="file.thumbnail_url" :src="file.thumbnail_url" :alt="file.name" :style="{ width: '100%', height: '100%', objectFit: 'cover' }" loading="lazy" />
                        <i v-else :class="`pi ${iconFor(file)}`" :style="{ fontSize: '14px', color: 'var(--fg-mute)' }" />
                    </button>
                    <Link
                        v-if="row.files_count > row.files_preview.length"
                        :href="workspaceUrl(`/equipment/${row.id}`)"
                        :style="{ fontSize: '11px', color: 'var(--fg-dim)', textDecoration: 'none', padding: '0 4px' }"
                        @click.stop
                    >{{ t('equipment.more_files', { n: row.files_count - row.files_preview.length }) }}</Link>
                </div>
                <span v-else :style="{ color: 'var(--fg-mute)' }">—</span>
            </template>

            <template #cell:category="{ row }">
                <CategoryChip :category="row.category" />
            </template>

            <template #cell:serial="{ row }">
                <span :style="{ color: 'var(--fg-dim)' }">{{ row.serial || '—' }}</span>
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
                <span class="cmd-bulk-count">{{ t('equipment.selected_of', { count: selectedCount, total }) }}</span>
                <button
                    v-if="showSelectAllMatching"
                    type="button"
                    class="cmd-bulk-link"
                    @click="selectAllMatching"
                >{{ t('equipment.select_all_matching', { total }) }}</button>
                <div :style="{ display: 'flex', alignItems: 'center', gap: '6px' }">
                    <button v-if="filesEnabled" type="button" class="cmd-bulk-btn" @click="bulkDownload">
                        <i class="pi pi-download" :style="{ fontSize: '13px' }" /><span>{{ t('equipment.bulk_download') }}</span>
                    </button>
                    <button v-if="can_manage" type="button" class="cmd-bulk-btn" @click="openBulkEdit">
                        <Icon name="edit" :size="14" /><span>{{ t('equipment.bulk_edit') }}</span>
                    </button>
                    <button v-if="can_manage" type="button" class="cmd-bulk-btn cmd-bulk-danger" @click="confirmBulkDelete">
                        <Icon name="trash" :size="14" /><span>{{ t('equipment.bulk_delete') }}</span>
                    </button>
                    <button type="button" class="cmd-bulk-btn cmd-bulk-ghost" :aria-label="t('equipment.clear_selection')" @click="clearSelection">
                        <Icon name="x" :size="14" />
                    </button>
                </div>
            </div>
        </Transition>

        <!-- Create dialog -->
        <CommandDialog v-model:visible="createOpen" :title="t('equipment.new_asset')" width="480px">
            <form @submit.prevent="submitCreate" :style="{ display: 'flex', flexDirection: 'column', gap: '12px' }">
                <Field v-model="form.name" :label="t('equipment.name')" :error="form.errors.name" required autofocus />
                <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px' }">
                    <div>
                        <label class="cmd-mono cmd-uc" :style="fieldLabelStyle">{{ t('equipment.category') }}</label>
                        <MenuDropdown
                            v-model="form.equipment_category_id"
                            :options="props.categories"
                            :placeholder="t('equipment.no_category')"
                            :include-empty="true"
                            block
                        />
                        <div v-if="form.errors.equipment_category_id" :style="{ color: 'var(--danger)', fontSize: '11px', marginTop: '4px' }">{{ form.errors.equipment_category_id }}</div>
                    </div>
                    <Field v-model="form.serial" :label="t('equipment.serial')" :error="form.errors.serial" />
                </div>
                <div>
                    <label
                        class="cmd-mono cmd-uc"
                        :style="{ display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em', fontWeight: 500 }"
                    >{{ t('equipment.notes') }}</label>
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        :placeholder="t('equipment.notes_placeholder')"
                        :style="{ width: '100%', background: 'var(--panel2)', border: `1px solid ${form.errors.notes ? 'var(--danger)' : 'var(--border)'}`, borderRadius: '5px', padding: '8px 10px', color: 'var(--fg)', fontSize: '13px', outline: 'none', fontFamily: 'inherit', resize: 'vertical' }"
                    />
                    <div v-if="form.errors.notes" :style="{ color: 'var(--danger)', fontSize: '11px', marginTop: '4px' }">{{ form.errors.notes }}</div>
                </div>
                <!-- Hidden submit so Enter in any text input saves (the textarea keeps newline). -->
                <button type="submit" :style="{ display: 'none' }" aria-hidden="true" />
            </form>
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="createOpen = false">{{ t('common.cancel') }}</CmdButton>
                <CmdButton variant="primary" size="sm" :loading="form.processing" @click="submitCreate">
                    <template #icon><Icon name="plus" :size="12" /></template>
                    {{ t('equipment.create_asset') }}
                </CmdButton>
            </template>
        </CommandDialog>

        <!-- Bulk edit dialog -->
        <CommandDialog v-model:visible="bulkEditOpen" :title="t('equipment.bulk_edit_title', { count: selectedCount })" width="420px">
            <form @submit.prevent="submitBulkEdit" :style="{ display: 'flex', flexDirection: 'column', gap: '10px' }">
                <div>
                    <label class="cmd-mono cmd-uc" :style="fieldLabelStyle">{{ t('equipment.category') }}</label>
                    <MenuDropdown
                        v-model="bulkCategory"
                        :options="props.categories"
                        :placeholder="t('equipment.no_category')"
                        :include-empty="true"
                        block
                    />
                </div>
                <p :style="{ margin: 0, fontSize: '11px', color: 'var(--fg-mute)' }">{{ t('equipment.bulk_edit_category_help') }}</p>
                <button type="submit" :style="{ display: 'none' }" aria-hidden="true" />
            </form>
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="bulkEditOpen = false">{{ t('common.cancel') }}</CmdButton>
                <CmdButton variant="primary" size="sm" @click="submitBulkEdit">
                    <template #icon><Icon name="disk" :size="12" /></template>
                    {{ t('common.save') }}
                </CmdButton>
            </template>
        </CommandDialog>

        <!-- Shared media lightbox + previews for the file column -->
        <ImageLightbox v-if="lightboxItems.length" v-model="lightboxIndex" :items="lightboxItems">
            <template #header-actions="{ item }">
                <button
                    type="button"
                    :aria-label="t('files.details.title')"
                    :title="t('files.details.title')"
                    class="cmd-lightbox-action"
                    @click.stop="openDetailsById(item.id)"
                >
                    <i class="pi pi-info-circle" :style="{ fontSize: '18px' }" />
                </button>
            </template>
        </ImageLightbox>
        <FileDetailsDialog :item="detailsItem" @close="detailsItem = null" />
        <TextPreviewDialog
            :item="textItem"
            :download-url="textItem ? workspaceUrl(`/entity-files/${textItem.id}/download`) : undefined"
            @close="textItem = null"
        />
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
.cmd-lightbox-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--panel);
    color: var(--fg);
    transition: background-color 0.15s ease;
}
.cmd-lightbox-action:hover {
    background: var(--panel2);
}
</style>
