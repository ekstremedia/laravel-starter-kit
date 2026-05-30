<script setup lang="ts">
/*
 * Equipment module's dashboard widget: totals, a category donut, and the most
 * recent items. Receives its payload from EquipmentDashboardWidget (PHP).
 */
import { computed, onMounted, ref } from 'vue';
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

// ApexCharts renders to SVG and can't resolve CSS variables, so read the theme
// tokens once at mount: the accent leads the palette, the legend uses --fg-mute,
// and the tooltip follows light/dark inferred from the panel background.
const theme = ref({
    palette: ['#4c6fff', '#10b981', '#f59e0b', '#8b5cf6', '#ff8a8a', '#5ee59a'],
    legend: '#8a8f98',
    tooltip: 'dark' as 'dark' | 'light',
});
function luminance(color: string): number {
    const m = color.match(/\d+(\.\d+)?/g);
    if (color.startsWith('#')) {
        const h = color.slice(1);
        const v = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
        const n = parseInt(v.slice(0, 6) || '000000', 16);
        return (0.299 * ((n >> 16) & 255) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255;
    }
    if (m && m.length >= 3) return (0.299 * +m[0] + 0.587 * +m[1] + 0.114 * +m[2]) / 255;
    return 0;
}
onMounted(() => {
    const s = getComputedStyle(document.documentElement);
    const accent = s.getPropertyValue('--accent').trim();
    const legend = s.getPropertyValue('--fg-mute').trim();
    const panel = s.getPropertyValue('--panel').trim();
    if (accent) theme.value.palette = [accent, '#10b981', '#f59e0b', '#8b5cf6', '#ff8a8a', '#5ee59a'];
    if (legend) theme.value.legend = legend;
    if (panel) theme.value.tooltip = luminance(panel) > 0.5 ? 'light' : 'dark';
});

const series = computed(() => props.data.by_category.map((c) => c.count));
const chartOptions = computed(() => ({
    chart: { background: 'transparent', toolbar: { show: false } },
    labels: props.data.by_category.map((c) => c.label),
    legend: { position: 'bottom', fontSize: '11px', labels: { colors: theme.value.legend } },
    colors: theme.value.palette,
    dataLabels: { enabled: false },
    stroke: { width: 0 },
    tooltip: { theme: theme.value.tooltip },
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
