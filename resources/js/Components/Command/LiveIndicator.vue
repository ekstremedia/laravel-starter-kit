<script setup lang="ts">
/*
 * Topbar "live" dot driven by the realtime Pinia store. Renders only when Echo
 * is actually connected to (store.bound) so deployments without WebSockets show
 * nothing rather than a permanently-red dot. Green = connected, amber =
 * (re)connecting, muted = dropped.
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Dot from '@/Components/Command/Dot.vue';
import { useRealtimeStore } from '@/stores/realtime';

const { t } = useI18n();
const realtime = useRealtimeStore();

const color = computed(() => {
    if (realtime.status === 'connected') return 'var(--success)';
    if (realtime.status === 'connecting') return 'var(--warning)';
    return 'var(--fg-mute)';
});
const label = computed(() => t(`topbar.live.${realtime.status}`));
</script>

<template>
    <span
        v-if="realtime.bound"
        :title="label"
        :aria-label="label"
        role="status"
        :style="{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: '24px', height: '24px', flexShrink: 0 }"
    >
        <Dot :color="color" :size="7" />
    </span>
</template>
