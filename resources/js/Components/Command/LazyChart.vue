<script setup lang="ts">
/*
 * Async boundary around vue3-apexcharts. ApexCharts (~1 MB with its Vue
 * wrapper) is only used on a handful of dashboard/admin surfaces, so it must
 * NOT live in the entry bundle. Loading it through defineAsyncComponent keeps
 * it in its own chunk that is fetched only when a chart actually renders.
 *
 * Drop-in replacement for the previously global <apexchart> / <VueApexCharts>:
 * forwards the chart props (type, height/width, options, series) through.
 */
import { defineAsyncComponent } from 'vue';
import type { ApexOptions } from 'apexcharts';

const ApexChartComponent = defineAsyncComponent(() => import('vue3-apexcharts'));

type ApexChartType = NonNullable<ApexOptions['chart']>['type'];

defineProps<{
    type: ApexChartType;
    height?: number | string;
    width?: number | string;
    options: object;
    series: unknown;
}>();
</script>

<template>
    <ApexChartComponent :type="type" :height="height" :width="width" :options="options" :series="series" />
</template>
