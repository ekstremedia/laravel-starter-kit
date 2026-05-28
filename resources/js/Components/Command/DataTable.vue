<script lang="ts">
export interface Column<T = any> {
    key: string;
    label: string;
    width?: string;
    sortable?: boolean;
    align?: 'left' | 'right' | 'center';
    mono?: boolean;
    getter?: (row: T) => unknown;
}
</script>

<script setup lang="ts" generic="Row extends { id: number | string }">
/*
 * Command-styled data table — the same look used on /admin/users, extracted
 * so every admin index page can drop it in.
 *
 * Capabilities
 * - Sortable columns (mono uppercase headers, chevron indicator).
 * - Debounced search input (emits `update:search` so parents can run it
 *   client-side or fire a server request).
 * - Optional bulk-select checkbox column.
 * - Row-hover "actions" slot (4 icon buttons in the handoff; up to you).
 * - Optional pagination footer bound to Inertia-style `links[]`.
 *
 * Sorting & filtering are both optionally handled locally when `localSort`
 * / `localSearch` are true. When false the table just renders `rows` as
 * provided and emits events — use this for server-side Inertia tables
 * (Users/Customers) that already paginate.
 */
import { computed, ref, useSlots, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from './Icon.vue';
import Skeleton from './Skeleton.vue';

const { t } = useI18n();

type SortDir = 'asc' | 'desc';

interface PaginatedLike {
    data?: any[];
    current_page?: number;
    last_page?: number;
    total?: number;
    per_page?: number;
    links?: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props {
    rows: Row[] | PaginatedLike;
    columns: Column<Row>[];
    search?: string;
    searchPlaceholder?: string;
    searchable?: boolean;
    sortKey?: string;
    sortDir?: SortDir;
    localSort?: boolean;
    localSearch?: boolean;
    loading?: boolean;
    selectable?: boolean;
    selected?: Set<number | string>;
    actionColumnWidth?: string;
    emptyText?: string;
    rowLink?: (row: Row) => string | null;
    searchKeys?: string[];
}

const props = withDefaults(defineProps<Props>(), {
    search: '',
    searchable: true,
    sortKey: '',
    sortDir: 'desc',
    localSort: true,
    localSearch: true,
    loading: false,
    selectable: false,
    selected: () => new Set(),
    actionColumnWidth: '120px',
    rowLink: () => null,
    searchKeys: () => [],
});

// Localized fallbacks: callers can still pass explicit strings, but the
// defaults follow the active locale instead of being hardcoded.
const resolvedSearchPlaceholder = computed(() => props.searchPlaceholder ?? `${t('common.search')}…`);
const resolvedEmptyText = computed(() => props.emptyText ?? t('common.no_results'));

const emit = defineEmits<{
    'update:search': [value: string];
    'update:sortKey': [value: string];
    'update:sortDir': [value: SortDir];
    'update:selected': [value: Set<number | string>];
    sort: [payload: { key: string; dir: SortDir }];
}>();

const isPaginated = computed(() => !Array.isArray(props.rows));
const allRows = computed<Row[]>(() => (isPaginated.value ? (props.rows as PaginatedLike).data ?? [] : (props.rows as Row[])));
const pagination = computed<PaginatedLike | null>(() => (isPaginated.value ? (props.rows as PaginatedLike) : null));

const localSearch = ref(props.search);
watch(() => props.search, (v) => { localSearch.value = v; });

let searchDebounce: ReturnType<typeof setTimeout> | null = null;
watch(localSearch, (v) => {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => emit('update:search', v), 220);
});

function toggleSort(col: Column<Row>) {
    if (!col.sortable) return;
    const nextDir: SortDir = props.sortKey === col.key && props.sortDir === 'asc' ? 'desc' : 'asc';
    emit('update:sortKey', col.key);
    emit('update:sortDir', nextDir);
    emit('sort', { key: col.key, dir: nextDir });
}

function compareValues(a: unknown, b: unknown): number {
    if (a == null && b == null) return 0;
    if (a == null) return -1;
    if (b == null) return 1;
    if (typeof a === 'number' && typeof b === 'number') return a - b;
    return String(a).localeCompare(String(b), 'nb-NO', { numeric: true });
}

function cellValue(row: Row, col: Column<Row>): unknown {
    if (col.getter) return col.getter(row);
    return (row as any)[col.key];
}

const displayRows = computed<Row[]>(() => {
    let list = allRows.value;

    if (props.localSearch && localSearch.value.trim()) {
        const needle = localSearch.value.trim().toLowerCase();
        const keys = props.searchKeys.length ? props.searchKeys : props.columns.map((c) => c.key);
        list = list.filter((row) =>
            keys.some((k) => {
                const col = props.columns.find((c) => c.key === k);
                const v = col?.getter ? col.getter(row) : (row as any)[k];
                if (v == null) return false;
                if (Array.isArray(v)) return v.some((x) => String(x).toLowerCase().includes(needle));
                return String(v).toLowerCase().includes(needle);
            }),
        );
    }

    if (props.localSort && props.sortKey) {
        const col = props.columns.find((c) => c.key === props.sortKey);
        if (col) {
            list = [...list].sort((a, b) => {
                const cmp = compareValues(cellValue(a, col), cellValue(b, col));
                return props.sortDir === 'asc' ? cmp : -cmp;
            });
        }
    }

    return list;
});

const slots = useSlots();
function hasSlot(name: string): boolean {
    return name in slots;
}

const gridTemplate = computed(() => {
    const parts: string[] = [];
    if (props.selectable) parts.push('32px');
    props.columns.forEach((c) => {
        parts.push(c.width ?? '1fr');
    });
    if (hasSlot('actions')) parts.push(props.actionColumnWidth);
    return parts.join(' ');
});

const hoverId = ref<number | string | null>(null);

// Selection helpers
const allSelected = computed(() => displayRows.value.length > 0 && displayRows.value.every((r) => props.selected.has(r.id)));
function toggleOne(r: Row) {
    const next = new Set(props.selected);
    if (next.has(r.id)) next.delete(r.id); else next.add(r.id);
    emit('update:selected', next);
}
function toggleAll() {
    if (allSelected.value) {
        emit('update:selected', new Set());
    } else {
        emit('update:selected', new Set(displayRows.value.map((r) => r.id)));
    }
}

// Pagination
const prevLink = computed(() => pagination.value?.links?.find((l) => l.label.includes('Previous') || l.label.includes('«'))?.url ?? null);
const nextLink = computed(() => pagination.value?.links?.find((l) => l.label.includes('Next') || l.label.includes('»'))?.url ?? null);

function pageStart(): number {
    const p = pagination.value;
    if (!p || !p.data?.length) return 0;
    const cur = p.current_page ?? 1;
    const per = p.per_page ?? p.data.length;
    return (cur - 1) * per + 1;
}
function pageEnd(): number {
    const p = pagination.value;
    if (!p || !p.data?.length) return 0;
    const cur = p.current_page ?? 1;
    const per = p.per_page ?? p.data.length;
    return Math.min(p.total ?? 0, (cur - 1) * per + p.data.length);
}
function goToPage(url: string | null) {
    if (!url) return;
    router.visit(url, { preserveState: true, preserveScroll: true });
}

function colAlign(col: Column<Row>) {
    return col.align ?? 'left';
}
</script>

<template>
    <div>
        <!-- Search bar -->
        <div
            v-if="searchable"
            :style="{ display: 'flex', justifyContent: 'flex-end', marginBottom: '10px' }"
        >
            <div :style="{ position: 'relative' }">
                <Icon
                    name="search"
                    :size="12"
                    :style="{ position: 'absolute', left: '10px', top: '50%', transform: 'translateY(-50%)', color: 'var(--fg-mute)' }"
                />
                <input
                    v-model="localSearch"
                    :placeholder="resolvedSearchPlaceholder"
                    :style="{
                        background: 'var(--panel2)',
                        border: '1px solid var(--border)',
                        borderRadius: '5px',
                        padding: '5px 10px 5px 28px',
                        color: 'var(--fg)',
                        fontSize: '11.5px',
                        width: '220px',
                        outline: 'none',
                        fontFamily: 'inherit',
                    }"
                />
            </div>
        </div>

        <div class="cmd-card">
          <!-- Desktop: tabular grid. Hidden on phones, where the stacked card
               list below renders instead (see <style>). -->
          <div class="cmd-dt-grid">
            <!-- Header row -->
            <div
                class="cmd-mono cmd-uc"
                :style="{
                    display: 'grid',
                    gridTemplateColumns: gridTemplate,
                    padding: '8px 16px',
                    fontSize: '10px',
                    color: 'var(--fg-mute)',
                    fontWeight: 500,
                    borderBottom: '1px solid var(--border)',
                    letterSpacing: '0.06em',
                    alignItems: 'center',
                }"
            >
                <div v-if="selectable">
                    <input
                        type="checkbox"
                        :checked="allSelected"
                        @change="toggleAll"
                        :style="{ accentColor: 'var(--accent)' }"
                    />
                </div>
                <div
                    v-for="col in columns"
                    :key="col.key"
                    :role="col.sortable ? 'button' : undefined"
                    :tabindex="col.sortable ? 0 : undefined"
                    :aria-sort="col.sortable
                        ? (sortKey === col.key ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none')
                        : undefined"
                    :style="{
                        textAlign: colAlign(col),
                        cursor: col.sortable ? 'pointer' : 'default',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: colAlign(col) === 'right' ? 'flex-end' : colAlign(col) === 'center' ? 'center' : 'flex-start',
                        gap: '4px',
                        userSelect: 'none',
                    }"
                    @click="toggleSort(col)"
                    @keydown.enter.prevent="toggleSort(col)"
                    @keydown.space.prevent="toggleSort(col)"
                >
                    <span>{{ col.label }}</span>
                    <span
                        v-if="col.sortable"
                        :style="{
                            opacity: sortKey === col.key ? 1 : 0.35,
                            color: sortKey === col.key ? 'var(--accent)' : 'var(--fg-mute)',
                            transform: sortKey === col.key && sortDir === 'asc' ? 'rotate(180deg)' : 'rotate(0deg)',
                            transition: 'transform 0.12s',
                            display: 'flex',
                        }"
                    >
                        <Icon name="chevD" :size="9" />
                    </span>
                </div>
                <div v-if="hasSlot('actions')" :style="{ textAlign: 'right' }">
                    <slot name="actions-header">{{ t('common.actions') }}</slot>
                </div>
            </div>

            <!-- Loading skeleton -->
            <div
                v-if="loading"
                :style="{ padding: '16px', display: 'flex', flexDirection: 'column', gap: '10px' }"
            >
                <Skeleton v-for="i in 6" :key="i" :width="'100%'" :height="20" :radius="3" />
            </div>

            <template v-else>
                <div
                    v-if="displayRows.length === 0"
                    :style="{ padding: '28px 16px', textAlign: 'center', color: 'var(--fg-mute)', fontSize: '12px' }"
                >{{ resolvedEmptyText }}</div>

                <!-- Rows -->
                <div
                    v-for="row in displayRows"
                    :key="row.id"
                    @mouseenter="hoverId = row.id"
                    @mouseleave="hoverId = null"
                    :style="{
                        display: 'grid',
                        gridTemplateColumns: gridTemplate,
                        padding: `var(--pad-row) 16px`,
                        alignItems: 'center',
                        fontSize: '12px',
                        borderBottom: '1px solid var(--border)',
                        background: selected.has(row.id)
                            ? 'var(--accent-soft)'
                            : hoverId === row.id ? 'var(--row-hover)' : 'transparent',
                        transition: 'background 0.1s',
                    }"
                >
                    <div v-if="selectable">
                        <input
                            type="checkbox"
                            :checked="selected.has(row.id)"
                            @change="toggleOne(row)"
                            :style="{ accentColor: 'var(--accent)' }"
                        />
                    </div>
                    <div
                        v-for="col in columns"
                        :key="col.key"
                        :class="col.mono ? 'cmd-mono' : ''"
                        :style="{
                            textAlign: colAlign(col),
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            whiteSpace: 'nowrap',
                            color: col.mono ? 'var(--fg-dim)' : 'var(--fg)',
                            fontSize: col.mono ? '11px' : '12px',
                        }"
                    >
                        <slot :name="`cell:${col.key}`" :row="row" :value="cellValue(row, col)">
                            {{ cellValue(row, col) }}
                        </slot>
                    </div>
                    <div
                        v-if="hasSlot('actions')"
                        :style="{
                            display: 'flex',
                            gap: '2px',
                            justifyContent: 'flex-end',
                            opacity: hoverId === row.id ? 1 : 0.35,
                            transition: 'opacity 0.1s',
                        }"
                    >
                        <slot name="actions" :row="row" />
                    </div>
                </div>
            </template>
          </div>

          <!-- Mobile: one card per row, columns stacked as label/value pairs. -->
          <div class="cmd-dt-cards">
            <div
                v-if="loading"
                :style="{ padding: '16px', display: 'flex', flexDirection: 'column', gap: '10px' }"
            >
                <Skeleton v-for="i in 4" :key="i" :width="'100%'" :height="56" :radius="6" />
            </div>
            <template v-else>
                <div
                    v-if="displayRows.length === 0"
                    :style="{ padding: '28px 16px', textAlign: 'center', color: 'var(--fg-mute)', fontSize: '12px' }"
                >{{ resolvedEmptyText }}</div>

                <div
                    v-for="row in displayRows"
                    :key="row.id"
                    class="cmd-dt-card"
                    :style="{ background: selected.has(row.id) ? 'var(--accent-soft)' : 'transparent' }"
                >
                    <label v-if="selectable" class="cmd-dt-field">
                        <span class="cmd-dt-label">{{ t('common.select') }}</span>
                        <input
                            type="checkbox"
                            :checked="selected.has(row.id)"
                            @change="toggleOne(row)"
                            :style="{ accentColor: 'var(--accent)' }"
                        />
                    </label>
                    <div v-for="col in columns" :key="col.key" class="cmd-dt-field">
                        <span class="cmd-dt-label">{{ col.label }}</span>
                        <span class="cmd-dt-value" :class="col.mono ? 'cmd-mono' : ''">
                            <slot :name="`cell:${col.key}`" :row="row" :value="cellValue(row, col)">
                                {{ cellValue(row, col) }}
                            </slot>
                        </span>
                    </div>
                    <div v-if="hasSlot('actions')" class="cmd-dt-card-actions">
                        <slot name="actions" :row="row" />
                    </div>
                </div>
            </template>
          </div>

            <!-- Footer -->
            <div
                class="cmd-mono"
                :style="{
                    padding: '10px 16px',
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    fontSize: '10.5px',
                    color: 'var(--fg-mute)',
                }"
            >
                <span v-if="pagination">rows {{ pageStart() }}–{{ pageEnd() }} / {{ pagination.total ?? displayRows.length }}</span>
                <span v-else>rows {{ displayRows.length }} / {{ allRows.length }}</span>

                <div v-if="pagination" :style="{ display: 'flex', gap: '4px', alignItems: 'center' }">
                    <span>page {{ pagination.current_page ?? 1 }} / {{ pagination.last_page ?? 1 }}</span>
                    <button
                        type="button"
                        :disabled="!prevLink"
                        @click="goToPage(prevLink)"
                        :style="{
                            background: 'transparent',
                            color: prevLink ? 'var(--fg-dim)' : 'var(--fg-mute)',
                            border: '1px solid var(--border)',
                            padding: '2px 7px',
                            borderRadius: '5px',
                            fontSize: '11.5px',
                            cursor: prevLink ? 'pointer' : 'not-allowed',
                            fontFamily: 'inherit',
                        }"
                    >‹</button>
                    <button
                        type="button"
                        :disabled="!nextLink"
                        @click="goToPage(nextLink)"
                        :style="{
                            background: 'transparent',
                            color: nextLink ? 'var(--fg-dim)' : 'var(--fg-mute)',
                            border: '1px solid var(--border)',
                            padding: '2px 7px',
                            borderRadius: '5px',
                            fontSize: '11.5px',
                            cursor: nextLink ? 'pointer' : 'not-allowed',
                            fontFamily: 'inherit',
                        }"
                    >›</button>
                </div>
            </div>
        </div>
    </div>
</template>
