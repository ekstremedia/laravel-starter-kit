<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import PageTitle from '@/Components/Command/PageTitle.vue';
import LazyChart from '@/Components/Command/LazyChart.vue';
import { useTweaks } from '@/composables/useTweaks';

defineOptions({ layout: CommandLayout });

interface TopUser { user_id: number; name: string; email: string; bytes: number }
interface WorkspaceUsage { workspace_id: number; name: string; slug: string; bytes: number; file_count: number }
interface EntityTypeUsage { type: string; key: string; label: string; file_count: number; bytes: number }
interface GrowthPoint { date: string; bytes: number }

interface PageData {
    totals: {
        bytes: number;
        disk_total: number;
        disk_free: number;
        file_count: number;
        user_count: number;
        workspace_count: number;
    };
    by_type: Record<string, number>;
    by_collection: Record<string, number>;
    by_entity_type: EntityTypeUsage[];
    by_workspace: WorkspaceUsage[];
    top_users: TopUser[];
    growth: GrowthPoint[];
    filters: { user_search: string; workspace_search: string };
}

const props = defineProps<PageData>();
const { t } = useI18n();

// Localize the well-known owner types; custom entities (Asset, …) fall back
// to the server-provided class-basename label.
const entityLabel = (row: EntityTypeUsage): string =>
    row.key === 'personal' ? t('admin.storage.entity_personal')
    : row.key === 'company' ? t('admin.storage.entity_company')
    : row.label;
const { state: tweaks } = useTweaks();

const ACCENT_HEX: Record<string, string> = { cobalt: '#4c6fff', emerald: '#10b981', amber: '#f59e0b', violet: '#8b5cf6' };
const accentHex = computed(() => ACCENT_HEX[tweaks.value.accent] ?? '#4c6fff');

const userSearch = ref(props.filters.user_search ?? '');
const workspaceSearch = ref(props.filters.workspace_search ?? '');

function applyFilters() {
    router.get(
        '/admin/storage',
        {
            user_search: userSearch.value || undefined,
            workspace_search: workspaceSearch.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function formatBytes(n: number): string {
    if (!n) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0; let v = n;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return `${v.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

const diskPercent = computed(() => {
    if (props.totals.disk_total === 0) return 0;
    return ((props.totals.disk_total - props.totals.disk_free) / props.totals.disk_total) * 100;
});

const axisColor = computed(() => tweaks.value.theme === 'light' ? 'rgba(15,23,42,0.42)' : 'rgba(213,217,229,0.4)');
const gridBorder = computed(() => tweaks.value.theme === 'light' ? 'rgba(15,23,42,0.08)' : 'rgba(255,255,255,0.06)');
const chartTheme = computed(() => tweaks.value.theme === 'light' ? 'light' : 'dark');

const donutColors = computed(() => [accentHex.value, '#f59e0b', '#10b981', '#0ea5e9', '#8b5cf6', '#ec4899', '#64748b']);

const typeChartOptions = computed(() => ({
    chart: { type: 'donut', background: 'transparent', fontFamily: 'inherit' },
    theme: { mode: chartTheme.value },
    labels: Object.keys(props.by_type).map((k) => t(`admin.storage.type_${k}`, k.charAt(0).toUpperCase() + k.slice(1))),
    colors: donutColors.value,
    legend: { position: 'bottom', labels: { colors: axisColor.value }, fontSize: '11px' },
    dataLabels: { enabled: true, formatter: (v: number) => `${v.toFixed(1)}%` },
    tooltip: { theme: chartTheme.value, y: { formatter: (v: number) => formatBytes(v) } },
    stroke: { width: 0 },
}));
const typeChartSeries = computed(() => Object.values(props.by_type));

const collectionChartOptions = computed(() => ({
    chart: { type: 'donut', background: 'transparent', fontFamily: 'inherit' },
    theme: { mode: chartTheme.value },
    labels: Object.keys(props.by_collection).map((k) => t(`admin.storage.collection_${k}`, k.replaceAll('_', ' '))),
    colors: donutColors.value,
    legend: { position: 'bottom', labels: { colors: axisColor.value }, fontSize: '11px' },
    dataLabels: { enabled: true, formatter: (v: number) => `${v.toFixed(1)}%` },
    tooltip: { theme: chartTheme.value, y: { formatter: (v: number) => formatBytes(v) } },
    stroke: { width: 0 },
}));
const collectionChartSeries = computed(() => Object.values(props.by_collection));

const topChartOptions = computed(() => ({
    chart: { type: 'bar', background: 'transparent', toolbar: { show: false }, fontFamily: 'inherit' },
    theme: { mode: chartTheme.value },
    plotOptions: { bar: { horizontal: true, borderRadius: 2 } },
    colors: [accentHex.value],
    xaxis: {
        categories: props.top_users.map((u) => u.name || u.email),
        labels: { style: { colors: axisColor.value, fontSize: '10px', fontFamily: "'JetBrains Mono', monospace" }, formatter: (v: string) => formatBytes(Number(v)) },
    },
    yaxis: { labels: { style: { colors: axisColor.value, fontSize: '10px' } } },
    grid: { borderColor: gridBorder.value },
    tooltip: { theme: chartTheme.value, y: { formatter: (v: number) => formatBytes(v) } },
    dataLabels: { enabled: false },
}));
const topChartSeries = computed(() => [{ name: t('admin.storage.bytes'), data: props.top_users.map((u) => u.bytes) }]);

const growthChartOptions = computed(() => ({
    chart: { type: 'area', background: 'transparent', toolbar: { show: false }, fontFamily: 'inherit' },
    theme: { mode: chartTheme.value },
    colors: ['#10b981'],
    xaxis: {
        type: 'datetime',
        categories: props.growth.map((p) => p.date),
        labels: { style: { colors: axisColor.value, fontSize: '10px', fontFamily: "'JetBrains Mono', monospace" } },
    },
    yaxis: { labels: { style: { colors: axisColor.value, fontSize: '10px' }, formatter: (v: number) => formatBytes(v) } },
    stroke: { curve: 'smooth', width: 1.5 },
    dataLabels: { enabled: false },
    tooltip: { theme: chartTheme.value, y: { formatter: (v: number) => formatBytes(v) } },
    grid: { borderColor: gridBorder.value, strokeDashArray: 2 },
    fill: { type: 'gradient', gradient: { shadeIntensity: 0.3, opacityFrom: 0.25, opacityTo: 0 } },
}));
const growthChartSeries = computed(() => [{ name: t('admin.storage.bytes'), data: props.growth.map((p) => p.bytes) }]);

const inputStyle = {
    width: '200px',
    background: 'var(--panel2)',
    border: '1px solid var(--border)',
    borderRadius: '5px',
    padding: '5px 10px',
    color: 'var(--fg)',
    fontSize: '11.5px',
    outline: 'none',
    fontFamily: 'inherit',
} as const;
</script>

<template>
    <div>
    <Head :title="t('admin.storage.title')" />

    <PageTitle :title="t('admin.storage.title')" />

    <!-- KPI grid -->
    <div
        :style="{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))',
            gap: '1px',
            background: 'var(--border)',
            border: '1px solid var(--border)',
            borderRadius: 'var(--radius-card)',
            marginBottom: '16px',
            overflow: 'hidden',
        }"
    >
        <div
            v-for="(kpi, i) in [
                { label: t('admin.storage.total_used'), value: formatBytes(props.totals.bytes), hint: '' },
                { label: t('admin.storage.file_count'), value: props.totals.file_count.toLocaleString(), hint: '' },
                { label: t('admin.storage.users'), value: props.totals.user_count.toLocaleString(), hint: '' },
                { label: t('admin.storage.workspaces'), value: props.totals.workspace_count.toLocaleString(), hint: '' },
                { label: t('admin.storage.disk_usage'), value: `${diskPercent.toFixed(0)}%`, hint: `${formatBytes(props.totals.disk_total - props.totals.disk_free)} / ${formatBytes(props.totals.disk_total)}` },
            ]"
            :key="i"
            :style="{ background: 'var(--panel)', padding: '14px 16px' }"
        >
            <div
                class="cmd-mono cmd-uc"
                :style="{ fontSize: '9.5px', color: 'var(--fg-mute)', marginBottom: '6px', fontWeight: 500 }"
            >{{ kpi.label }}</div>
            <div
                class="cmd-mono"
                :style="{ fontSize: '22px', fontWeight: 600, color: 'var(--fg)', letterSpacing: '-0.01em', lineHeight: 1.1 }"
            >{{ kpi.value }}</div>
            <div v-if="kpi.hint" :style="{ fontSize: '10.5px', color: 'var(--fg-mute)', marginTop: '4px' }" class="cmd-mono">{{ kpi.hint }}</div>
        </div>
    </div>

    <!-- Charts grid -->
    <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: '16px' }">
        <section class="cmd-card" :style="{ padding: '16px' }">
            <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)', marginBottom: '10px' }">
                {{ t('admin.storage.by_type') }}
            </div>
            <LazyChart
                v-if="typeChartSeries.some((v) => v > 0)"
                type="donut"
                height="280"
                :options="typeChartOptions"
                :series="typeChartSeries"
            />
            <p v-else :style="{ padding: '32px 0', textAlign: 'center', fontSize: '12px', color: 'var(--fg-mute)' }">
                {{ t('admin.storage.no_data') }}
            </p>
        </section>

        <section class="cmd-card" :style="{ padding: '16px' }">
            <div :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '10px', gap: '10px' }">
                <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">
                    {{ t('admin.storage.top_users') }}
                </div>
                <input
                    v-model="userSearch"
                    type="search"
                    :placeholder="t('admin.storage.search_users')"
                    :style="inputStyle"
                    @keyup.enter="applyFilters"
                    @search="applyFilters"
                />
            </div>
            <LazyChart
                v-if="props.top_users.length"
                type="bar"
                height="280"
                :options="topChartOptions"
                :series="topChartSeries"
            />
            <p v-else :style="{ padding: '32px 0', textAlign: 'center', fontSize: '12px', color: 'var(--fg-mute)' }">
                {{ t('admin.storage.no_data') }}
            </p>
        </section>

        <section class="cmd-card" :style="{ padding: '16px', gridColumn: 'span 2' }">
            <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)', marginBottom: '12px' }">
                {{ t('admin.storage.by_entity_type') }}
            </div>
            <div v-if="props.by_entity_type.length" :style="{ overflowX: 'auto' }">
                <table :style="{ width: '100%', borderCollapse: 'collapse', fontSize: '12px' }">
                    <thead>
                        <tr class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', fontWeight: 500, borderBottom: '1px solid var(--border)' }">
                            <th :style="{ padding: '8px 10px', textAlign: 'left' }">{{ t('admin.storage.entity_type') }}</th>
                            <th :style="{ padding: '8px 10px', textAlign: 'right' }">{{ t('admin.storage.file_count') }}</th>
                            <th :style="{ padding: '8px 10px', textAlign: 'right' }">{{ t('admin.storage.bytes') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in props.by_entity_type" :key="row.type" :style="{ borderBottom: '1px solid var(--border)' }">
                            <td :style="{ padding: '8px 10px', color: 'var(--fg)' }">{{ entityLabel(row) }}</td>
                            <td class="cmd-mono" :style="{ padding: '8px 10px', textAlign: 'right', color: 'var(--fg-dim)', fontSize: '11px' }">{{ row.file_count.toLocaleString() }}</td>
                            <td class="cmd-mono" :style="{ padding: '8px 10px', textAlign: 'right', color: 'var(--fg)', fontSize: '11px', fontWeight: 500 }">{{ formatBytes(row.bytes) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else :style="{ padding: '24px 0', textAlign: 'center', fontSize: '12px', color: 'var(--fg-mute)' }">
                {{ t('admin.storage.no_data') }}
            </p>
        </section>

        <section class="cmd-card" :style="{ padding: '16px', gridColumn: 'span 2' }">
            <div :style="{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', marginBottom: '12px', gap: '10px' }">
                <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">
                    {{ t('admin.storage.by_workspace') }}
                </div>
                <input
                    v-model="workspaceSearch"
                    type="search"
                    :placeholder="t('admin.storage.search_workspaces')"
                    :style="{ ...inputStyle, width: '260px' }"
                    @keyup.enter="applyFilters"
                    @search="applyFilters"
                />
            </div>
            <div v-if="props.by_workspace.length" :style="{ overflowX: 'auto' }">
                <table :style="{ width: '100%', borderCollapse: 'collapse', fontSize: '12px' }">
                    <thead>
                        <tr class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', fontWeight: 500, borderBottom: '1px solid var(--border)' }">
                            <th :style="{ padding: '8px 10px', textAlign: 'left' }">{{ t('admin.storage.workspace') }}</th>
                            <th :style="{ padding: '8px 10px', textAlign: 'left' }">{{ t('admin.storage.slug') }}</th>
                            <th :style="{ padding: '8px 10px', textAlign: 'right' }">{{ t('admin.storage.file_count') }}</th>
                            <th :style="{ padding: '8px 10px', textAlign: 'right' }">{{ t('admin.storage.bytes') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in props.by_workspace" :key="c.workspace_id" :style="{ borderBottom: '1px solid var(--border)' }">
                            <td :style="{ padding: '8px 10px', color: 'var(--fg)' }">{{ c.name }}</td>
                            <td class="cmd-mono" :style="{ padding: '8px 10px', color: 'var(--fg-dim)', fontSize: '11px' }">{{ c.slug }}</td>
                            <td class="cmd-mono" :style="{ padding: '8px 10px', textAlign: 'right', color: 'var(--fg-dim)', fontSize: '11px' }">{{ c.file_count.toLocaleString() }}</td>
                            <td class="cmd-mono" :style="{ padding: '8px 10px', textAlign: 'right', color: 'var(--fg)', fontSize: '11px', fontWeight: 500 }">{{ formatBytes(c.bytes) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else :style="{ padding: '24px 0', textAlign: 'center', fontSize: '12px', color: 'var(--fg-mute)' }">
                {{ t('admin.storage.no_data') }}
            </p>
        </section>

        <section class="cmd-card" :style="{ padding: '16px', gridColumn: 'span 2' }">
            <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)', marginBottom: '10px' }">
                {{ t('admin.storage.by_collection') }}
            </div>
            <LazyChart
                v-if="collectionChartSeries.some((v) => v > 0)"
                type="donut"
                height="260"
                :options="collectionChartOptions"
                :series="collectionChartSeries"
            />
            <p v-else :style="{ padding: '24px 0', textAlign: 'center', fontSize: '12px', color: 'var(--fg-mute)' }">
                {{ t('admin.storage.no_data') }}
            </p>
        </section>

        <section class="cmd-card" :style="{ padding: '16px', gridColumn: 'span 2' }">
            <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)', marginBottom: '10px' }">
                {{ t('admin.storage.growth_30d') }}
            </div>
            <LazyChart
                v-if="props.growth.length"
                type="area"
                height="280"
                :options="growthChartOptions"
                :series="growthChartSeries"
            />
            <p v-else :style="{ padding: '24px 0', textAlign: 'center', fontSize: '12px', color: 'var(--fg-mute)' }">
                {{ t('admin.storage.no_snapshots') }}
            </p>
        </section>
    </div>
    </div>
</template>
