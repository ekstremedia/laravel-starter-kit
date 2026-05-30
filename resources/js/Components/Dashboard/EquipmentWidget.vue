<script setup lang="ts">
/*
 * Equipment module's dashboard widget: totals, a category donut, and the most
 * recent items. Receives its payload from EquipmentDashboardWidget (PHP).
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Command/Icon.vue';
import { useWorkspace } from '@/composables/useWorkspace';

interface CategoryDatum { label: string; count: number }
interface RecentItem { id: number; name: string; category: string | null }
interface WidgetData {
    total: number;
    with_files: number;
    by_category: CategoryDatum[];
    recent: RecentItem[];
}

const props = defineProps<{ data: WidgetData }>();
const { t } = useI18n();
const { workspaceUrl } = useWorkspace();

const series = computed(() => props.data.by_category.map((c) => c.count));
const chartOptions = computed(() => ({
    chart: { background: 'transparent', toolbar: { show: false } },
    labels: props.data.by_category.map((c) => c.label),
    legend: { position: 'bottom', fontSize: '11px', labels: { colors: '#8a8f98' } },
    // Theme-agnostic palette (reads on both light + dark backgrounds).
    colors: ['#4c6fff', '#10b981', '#f59e0b', '#8b5cf6', '#ff8a8a', '#5ee59a'],
    dataLabels: { enabled: false },
    stroke: { width: 0 },
    tooltip: { theme: 'dark' },
    plotOptions: { pie: { donut: { size: '62%' } } },
}));
</script>

<template>
    <div>
        <div :style="{ display: 'flex', gap: '18px', marginBottom: '14px' }">
            <div>
                <div :style="{ fontSize: '24px', fontWeight: 700, color: 'var(--fg)', lineHeight: 1.1 }">{{ data.total }}</div>
                <div :style="{ fontSize: '10.5px', color: 'var(--fg-mute)', textTransform: 'uppercase', letterSpacing: '0.05em', marginTop: '2px' }">{{ t('equipment.stat_total') }}</div>
            </div>
            <div>
                <div :style="{ fontSize: '24px', fontWeight: 700, color: 'var(--fg)', lineHeight: 1.1 }">{{ data.with_files }}</div>
                <div :style="{ fontSize: '10.5px', color: 'var(--fg-mute)', textTransform: 'uppercase', letterSpacing: '0.05em', marginTop: '2px' }">{{ t('equipment.stat_with_files') }}</div>
            </div>
        </div>

        <apexchart v-if="series.length" type="donut" height="170" :options="chartOptions" :series="series" />
        <p v-else :style="{ fontSize: '12px', color: 'var(--fg-mute)', padding: '8px 0' }">{{ t('equipment.empty') }}</p>

        <div v-if="data.recent.length" :style="{ marginTop: '12px', borderTop: '1px solid var(--border)', paddingTop: '8px' }">
            <Link
                v-for="item in data.recent"
                :key="item.id"
                :href="workspaceUrl(`/equipment/${item.id}`)"
                :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '8px', padding: '5px 2px', fontSize: '12.5px', color: 'var(--fg)', textDecoration: 'none' }"
            >
                <span :style="{ display: 'inline-flex', alignItems: 'center', gap: '7px', overflow: 'hidden' }">
                    <Icon name="box" :size="11" :style="{ color: 'var(--fg-mute)', flexShrink: 0 }" />
                    <span :style="{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">{{ item.name }}</span>
                </span>
                <span :style="{ fontSize: '10.5px', color: 'var(--fg-mute)', flexShrink: 0 }">{{ item.category || t('equipment.no_category') }}</span>
            </Link>
        </div>
    </div>
</template>
