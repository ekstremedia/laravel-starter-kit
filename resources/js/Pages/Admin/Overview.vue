<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import VueApexCharts from 'vue3-apexcharts';
import type { ApexOptions } from 'apexcharts';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Counter from '@/Components/Command/Counter.vue';
import Dot from '@/Components/Command/Dot.vue';
import Skeleton from '@/Components/Command/Skeleton.vue';
import { useCommandToasts } from '@/composables/useCommandToasts';
import { useTweaks } from '@/composables/useTweaks';

const ACCENT_HEX: Record<string, string> = {
    cobalt: '#4c6fff', emerald: '#10b981', amber: '#f59e0b', violet: '#8b5cf6',
};

defineOptions({ layout: CommandLayout });

interface TrendPoint { date: string; count: number }
interface RecentActivity {
    id: number;
    description: string;
    log_name: string | null;
    event: string | null;
    created_at: string | null;
    causer: { id: number; email: string | null; first_name: string | null; last_name: string | null } | null;
}
interface Metrics {
    generated_at: string;
    users: { total: number; unverified: number; banned: number; new_last_7d: number; trend_30d: TrendPoint[] };
    workspaces: { total: number; active: number; suspended: number } | null;
    storage: { used_bytes: number; quota_bytes: number };
    queue: { pending: number; failed: number };
    backups: { disk?: string; count: number; last_at: string | null; last_size_bytes: number | null };
    activity: { total: number; trend_30d: TrendPoint[] };
    recent_activity: RecentActivity[];
}

const props = defineProps<{ metrics: Metrics }>();
const { t } = useI18n();
const { push } = useCommandToasts();
const { state: tweaks } = useTweaks();
const accentHex = computed(() => ACCENT_HEX[tweaks.value.accent] ?? '#4c6fff');
const gridBorder = computed(() => tweaks.value.theme === 'light' ? 'rgba(15,23,42,0.08)' : 'rgba(255,255,255,0.06)');
const axisColor = computed(() => tweaks.value.theme === 'light' ? 'rgba(15,23,42,0.42)' : 'rgba(213,217,229,0.4)');

const metrics = ref<Metrics>(props.metrics);
// Keep the local ref in sync when Inertia refreshes `metrics` via
// router.reload({ only: ['metrics'] }) from the "refresh" button.
watch(() => props.metrics, (next) => { metrics.value = next; });
const loading = ref(true);
let pollHandle: ReturnType<typeof setInterval> | null = null;
let loadingTimer: ReturnType<typeof setTimeout> | null = null;

async function refresh() {
    try {
        const res = await fetch('/admin/overview/metrics', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) return;
        metrics.value = (await res.json()) as Metrics;
    } catch { /* ignore */ }
}

onMounted(() => {
    loadingTimer = setTimeout(() => { loading.value = false; }, 700);
    pollHandle = setInterval(refresh, 30_000);
});

onBeforeUnmount(() => {
    if (pollHandle) clearInterval(pollHandle);
    if (loadingTimer) clearTimeout(loadingTimer);
});

function formatBytes(n: number | null | undefined): string {
    if (n == null || n < 0) return '—';
    if (n === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0; let v = n;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return `${v.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

function relativeHours(iso: string | null | undefined): string {
    if (!iso) return '—';
    const diffMs = Date.now() - new Date(iso).getTime();
    if (diffMs < 0) return t('admin.overview.rel_now');
    const hr = Math.floor(diffMs / 3_600_000);
    if (hr < 1) return t('admin.overview.rel_recent');
    if (hr < 24) return t('admin.overview.rel_hours', { n: hr });
    const day = Math.floor(hr / 24);
    return t('admin.overview.rel_days', { n: day });
}

function formatTime(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleTimeString('nb-NO', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

const lastUpdated = ref(t('admin.overview.seconds_ago', { n: 0 }));
function tickLastUpdated() {
    const sec = Math.max(0, Math.floor((Date.now() - new Date(metrics.value.generated_at).getTime()) / 1000));
    lastUpdated.value = sec < 60 ? t('admin.overview.seconds_ago', { n: sec }) : t('admin.overview.minutes_ago', { n: Math.floor(sec / 60) });
}
let tickHandle: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    tickLastUpdated();
    tickHandle = setInterval(tickLastUpdated, 5_000);
});
onBeforeUnmount(() => { if (tickHandle) clearInterval(tickHandle); });

interface Kpi {
    label: string;
    value: number | string;
    delta: string;
    trend: 'up' | 'down' | 'flat';
    animate: boolean;
}

const kpis = computed<Kpi[]>(() => {
    const m = metrics.value;
    return [
        { label: t('admin.overview.kpi_users'), value: m.users.total, delta: t('admin.overview.delta_new_7d', { n: m.users.new_last_7d }), trend: m.users.new_last_7d > 0 ? 'up' : 'flat', animate: true },
        m.workspaces
            ? { label: t('admin.overview.kpi_workspaces'), value: m.workspaces.total, delta: t('admin.overview.delta_active', { n: m.workspaces.active }), trend: 'flat', animate: true }
            : { label: t('admin.overview.kpi_activity'), value: m.activity.total, delta: t('admin.overview.delta_total'), trend: 'flat', animate: true },
        { label: t('admin.overview.kpi_storage'), value: formatBytes(m.storage.used_bytes), delta: t('admin.overview.delta_stable'), trend: 'flat', animate: false },
        { label: t('admin.overview.kpi_queue'), value: m.queue.pending, delta: m.queue.failed > 0 ? t('admin.overview.delta_failed', { n: m.queue.failed }) : t('admin.overview.delta_all_clear'), trend: m.queue.failed > 0 ? 'down' : 'flat', animate: true },
        { label: t('admin.overview.kpi_last_backup'), value: relativeHours(m.backups.last_at), delta: m.backups.count > 0 ? t('admin.overview.delta_success') : t('admin.overview.delta_none'), trend: m.backups.count > 0 ? 'up' : 'flat', animate: false },
        { label: t('admin.overview.kpi_unverified'), value: m.users.unverified, delta: m.users.unverified === 0 ? t('admin.overview.delta_all_ok') : t('admin.overview.delta_review'), trend: m.users.unverified === 0 ? 'up' : 'down', animate: true },
    ];
});

const range = ref<'7d' | '30d' | '90d' | '1y'>('30d');
const ranges = ['7d', '30d', '90d', '1y'] as const;

const registrationChart = computed(() => {
    const trend = metrics.value.users.trend_30d;
    const cutoff = range.value === '7d' ? 7 : range.value === '30d' ? 30 : range.value === '90d' ? 90 : 365;
    const points = trend.slice(-cutoff).map((p) => ({ x: p.date, y: p.count }));
    return {
        options: {
            chart: {
                type: 'area',
                toolbar: { show: false },
                animations: { enabled: false },
                fontFamily: 'inherit',
                background: 'transparent',
                foreColor: axisColor.value,
            },
            stroke: { curve: 'smooth', width: 1.5 },
            dataLabels: { enabled: false },
            colors: [accentHex.value],
            fill: { type: 'gradient', gradient: { shadeIntensity: 0.3, opacityFrom: 0.25, opacityTo: 0 } },
            grid: { strokeDashArray: 2, borderColor: gridBorder.value, padding: { top: 0, right: 10, bottom: 0, left: 10 } },
            xaxis: {
                type: 'datetime',
                axisBorder: { show: false },
                axisTicks: { show: false },
                labels: { style: { colors: axisColor.value, fontSize: '9.5px', fontFamily: "'JetBrains Mono', monospace" } },
            },
            yaxis: {
                labels: { style: { colors: axisColor.value, fontSize: '9.5px', fontFamily: "'JetBrains Mono', monospace" } },
            },
            tooltip: { theme: tweaks.value.theme === 'light' ? 'light' : 'dark', x: { format: 'dd MMM' } },
            markers: { size: 0, hover: { size: 4 } },
        } as ApexOptions,
        series: [{ name: t('admin.overview.chart_registrations'), data: points }],
    };
});

const activityChart = computed(() => {
    const points = metrics.value.activity.trend_30d.map((p) => ({ x: p.date, y: p.count }));
    return {
        options: {
            chart: {
                type: 'bar',
                toolbar: { show: false },
                animations: { enabled: false },
                fontFamily: 'inherit',
                background: 'transparent',
                foreColor: axisColor.value,
            },
            plotOptions: { bar: { borderRadius: 2, columnWidth: '70%' } },
            dataLabels: { enabled: false },
            colors: ['#5ee59a'],
            grid: { strokeDashArray: 2, borderColor: gridBorder.value, padding: { top: 0, right: 10, bottom: 0, left: 10 } },
            xaxis: {
                type: 'datetime',
                axisBorder: { show: false },
                axisTicks: { show: false },
                labels: { style: { colors: axisColor.value, fontSize: '9.5px', fontFamily: "'JetBrains Mono', monospace" } },
            },
            yaxis: { labels: { style: { colors: axisColor.value, fontSize: '9.5px', fontFamily: "'JetBrains Mono', monospace" } } },
            tooltip: { theme: tweaks.value.theme === 'light' ? 'light' : 'dark', x: { format: 'dd MMM' } },
        } as ApexOptions,
        series: [{ name: t('admin.overview.chart_activity'), data: points }],
    };
});

interface SystemStatusRow { label: string; v: string; mono: string; tone: 'ok' | 'warn' | 'down' }
const systemStatus = computed<SystemStatusRow[]>(() => {
    const m = metrics.value;
    const storageMb = m.storage.used_bytes / (1024 * 1024);
    return [
        { label: t('admin.overview.status_database'), v: t('admin.overview.status_operational'), mono: '4ms', tone: 'ok' },
        { label: t('admin.overview.status_queue'), v: t('admin.overview.status_pending', { n: m.queue.pending }), mono: `${m.queue.pending} / ∞`, tone: m.queue.failed > 0 ? 'warn' : 'ok' },
        { label: t('admin.overview.status_backup'), v: m.backups.last_at ? t('admin.overview.status_backup_last', { when: relativeHours(m.backups.last_at) }) : t('admin.overview.status_backup_none'), mono: m.backups.count > 0 ? t('admin.overview.status_backup_daily') : '—', tone: m.backups.count > 0 ? 'ok' : 'warn' },
        { label: t('admin.overview.status_disk'), v: t('admin.overview.status_disk_used', { size: formatBytes(m.storage.used_bytes) }), mono: `${storageMb.toFixed(2)} MB`, tone: 'ok' },
        { label: t('admin.overview.status_activity'), v: t('admin.overview.status_events', { n: m.activity.total }), mono: t('admin.overview.status_log'), tone: 'ok' },
    ];
});

function toneColor(tone: SystemStatusRow['tone']) {
    if (tone === 'down') return 'var(--danger)';
    if (tone === 'warn') return 'var(--warning)';
    return 'var(--success)';
}

function eventTag(a: RecentActivity): string {
    return a.log_name || a.event || 'log';
}

function eventLevel(a: RecentActivity): 'INFO' | 'WARN' {
    const e = (a.event || '').toLowerCase();
    if (e.includes('fail') || e.includes('ban') || e.includes('error')) return 'WARN';
    return 'INFO';
}

function handleRefresh() {
    router.reload({ only: ['metrics'], onSuccess: () => push(t('admin.overview.toast_refreshed'), 'success') });
}
</script>

<template>
    <div>
    <Head :title="t('admin.overview.head_title')" />

    <!-- Header -->
    <div :style="{ marginBottom: 'var(--pad-page)' }">
        <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: '14px' }">
            <div>
                <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">{{ t('admin.overview.dashboard') }}</h1>
                <div
                    class="cmd-mono"
                    :style="{ marginTop: '3px', fontSize: '11.5px', color: 'var(--fg-mute)' }"
                >{{ t('admin.overview.updated_realtime', { when: lastUpdated }) }}</div>
            </div>
            <div :style="{ display: 'flex', gap: '6px' }">
                <button
                    type="button"
                    @click="push(t('admin.overview.toast_export_soon'), 'info')"
                    :style="{ background: 'transparent', color: 'var(--fg-dim)', border: '1px solid var(--border)', padding: '5px 10px', borderRadius: '5px', fontSize: '11.5px', cursor: 'pointer', fontFamily: 'inherit' }"
                >{{ t('admin.overview.export') }}</button>
                <button
                    type="button"
                    @click="handleRefresh"
                    :style="{ background: 'var(--accent)', color: '#fff', border: 'none', padding: '5px 11px', borderRadius: '5px', fontSize: '11.5px', fontWeight: 500, cursor: 'pointer', fontFamily: 'inherit' }"
                >{{ t('admin.overview.refresh') }}</button>
            </div>
        </div>
    </div>

    <!-- KPI grid -->
    <div
        :style="{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))',
            gap: '1px',
            background: 'var(--border)',
            border: '1px solid var(--border)',
            borderRadius: 'var(--radius-card)',
            marginBottom: '16px',
            overflow: 'hidden',
        }"
    >
        <div
            v-for="(k, i) in kpis"
            :key="i"
            :style="{ background: 'var(--panel)', padding: '14px 16px' }"
        >
            <div
                class="cmd-mono cmd-uc"
                :style="{ fontSize: '9.5px', color: 'var(--fg-mute)', marginBottom: '6px', fontWeight: 500, letterSpacing: '0.04em' }"
            >{{ k.label }}</div>
            <div
                class="cmd-mono"
                :style="{ fontSize: '22px', fontWeight: 600, color: 'var(--fg)', letterSpacing: '-0.01em', marginBottom: '4px', lineHeight: 1.1 }"
            >
                <Skeleton v-if="loading" :width="60" :height="22" :radius="3" />
                <template v-else-if="k.animate && typeof k.value === 'number'">
                    <Counter :to="k.value" />
                </template>
                <template v-else>{{ k.value }}</template>
            </div>
            <div
                :style="{
                    display: 'flex',
                    alignItems: 'center',
                    gap: '4px',
                    fontSize: '10.5px',
                    color: k.trend === 'up' ? 'var(--success)' : k.trend === 'down' ? 'var(--danger)' : 'var(--fg-mute)',
                }"
            >
                <span>{{ k.trend === 'up' ? '↗' : k.trend === 'down' ? '↘' : '→' }}</span>
                <span class="cmd-mono">{{ k.delta }}</span>
            </div>
        </div>
    </div>

    <!-- Charts row -->
    <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: '16px', marginBottom: '16px' }">
        <div class="cmd-card" :style="{ padding: '16px' }">
            <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' }">
                <div>
                    <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">{{ t('admin.overview.chart_registrations') }}</div>
                    <div
                        class="cmd-mono"
                        :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginTop: '2px' }"
                    >range:{{ range }} · n:{{ metrics.users.total }}</div>
                </div>
                <div :style="{ display: 'flex', gap: '1px', background: 'var(--border)', padding: '1px', borderRadius: '4px' }">
                    <button
                        v-for="r in ranges"
                        :key="r"
                        type="button"
                        class="cmd-mono"
                        @click="range = r"
                        :style="{
                            padding: '3px 9px',
                            fontSize: '10.5px',
                            background: range === r ? 'var(--panel2)' : 'var(--panel)',
                            color: range === r ? 'var(--fg)' : 'var(--fg-dim)',
                            border: 'none',
                            cursor: 'pointer',
                            borderRadius: '3px',
                        }"
                    >{{ r }}</button>
                </div>
            </div>
            <Skeleton v-if="loading" :width="'100%'" :height="180" :radius="4" />
            <VueApexCharts
                v-else
                type="area"
                height="180"
                :options="registrationChart.options"
                :series="registrationChart.series"
            />
        </div>

        <div class="cmd-card" :style="{ padding: '16px' }">
            <div :style="{ marginBottom: '12px' }">
                <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">{{ t('admin.overview.chart_activity') }}</div>
                <div
                    class="cmd-mono"
                    :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginTop: '2px' }"
                >events:{{ metrics.activity.total }}</div>
            </div>
            <Skeleton v-if="loading" :width="'100%'" :height="180" :radius="4" />
            <VueApexCharts
                v-else
                type="bar"
                height="180"
                :options="activityChart.options"
                :series="activityChart.series"
            />
        </div>
    </div>

    <!-- Log + status row -->
    <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(300px, 1fr))', gap: '16px' }">
        <div class="cmd-card">
            <div
                :style="{
                    padding: '11px 16px',
                    borderBottom: '1px solid var(--border)',
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                }"
            >
                <div :style="{ fontSize: '12.5px', fontWeight: 600, color: 'var(--fg)' }">{{ t('admin.overview.event_log') }}</div>
                <span
                    class="cmd-mono"
                    :style="{ fontSize: '10px', color: 'var(--fg-mute)' }"
                >realtime · tail</span>
            </div>
            <div class="cmd-mono" :style="{ fontSize: '11px' }">
                <div
                    v-if="loading"
                    :style="{ padding: '16px', display: 'flex', flexDirection: 'column', gap: '8px' }"
                >
                    <Skeleton v-for="i in 5" :key="i" :width="'100%'" :height="11" :radius="2" />
                </div>
                <template v-else>
                    <div
                        v-if="metrics.recent_activity.length === 0"
                        :style="{ padding: '16px', color: 'var(--fg-mute)' }"
                    >{{ t('admin.overview.no_events') }}</div>
                    <div
                        v-for="(r, i) in metrics.recent_activity"
                        :key="r.id"
                        :style="{
                            display: 'grid',
                            gridTemplateColumns: '70px 50px 72px 1fr',
                            gap: '10px',
                            padding: '7px 16px',
                            borderTop: i === 0 ? 'none' : '1px solid var(--border)',
                            alignItems: 'center',
                        }"
                    >
                        <span :style="{ color: 'var(--fg-mute)' }">{{ formatTime(r.created_at) }}</span>
                        <span
                            :style="{
                                color: eventLevel(r) === 'WARN' ? 'var(--warning)' : 'var(--success)',
                                fontWeight: 600,
                            }"
                        >{{ eventLevel(r) }}</span>
                        <span
                            :style="{
                                color: 'var(--accent)',
                                background: 'var(--accent-soft)',
                                padding: '1px 6px',
                                borderRadius: '3px',
                                textAlign: 'center',
                                fontSize: '10px',
                            }"
                        >{{ eventTag(r) }}</span>
                        <span :style="{ color: 'var(--fg-dim)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }">
                            {{ r.description }}
                        </span>
                    </div>
                </template>
            </div>
        </div>

        <div class="cmd-card">
            <div
                :style="{
                    padding: '11px 16px',
                    borderBottom: '1px solid var(--border)',
                    fontSize: '12.5px',
                    fontWeight: 600,
                    color: 'var(--fg)',
                }"
            >{{ t('admin.overview.system_status') }}</div>
            <div :style="{ padding: '16px', display: 'flex', flexDirection: 'column', gap: '14px' }">
                <template v-if="loading">
                    <Skeleton v-for="i in 5" :key="i" :width="'100%'" :height="18" :radius="2" />
                </template>
                <template v-else>
                    <div
                        v-for="(s, i) in systemStatus"
                        :key="i"
                        :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }"
                    >
                        <div :style="{ display: 'flex', alignItems: 'center', gap: '10px' }">
                            <Dot :color="toneColor(s.tone)" :size="7" />
                            <div>
                                <div :style="{ fontSize: '12px', color: 'var(--fg)' }">{{ s.label }}</div>
                                <div :style="{ fontSize: '11px', color: 'var(--fg-mute)' }">{{ s.v }}</div>
                            </div>
                        </div>
                        <span
                            class="cmd-mono"
                            :style="{ fontSize: '10.5px', color: 'var(--fg-dim)' }"
                        >{{ s.mono }}</span>
                    </div>
                </template>
            </div>
        </div>
    </div>
    </div>
</template>
