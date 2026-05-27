<script lang="ts">
export type IconName =
    | 'home' | 'users' | 'user' | 'customer' | 'role' | 'key' | 'cog'
    | 'mail' | 'disk' | 'shield' | 'server' | 'log' | 'bell' | 'search' | 'box'
    | 'chevR' | 'chevD' | 'plus' | 'edit' | 'trash' | 'restore' | 'arrow'
    | 'check' | 'x' | 'alert' | 'link';
</script>

<script setup lang="ts">
/*
 * Inline SVG icon set ported verbatim from the handoff's shared.jsx.
 * All icons are 16×16 viewBox, 1.5 stroke, no fill, currentColor.
 * Render at 12–15 px in the UI.
 */
import { computed } from 'vue';

interface Props {
    name: IconName;
    size?: number;
}

const props = withDefaults(defineProps<Props>(), { size: 14 });
const side = computed(() => props.size);
</script>

<template>
    <svg :width="side" :height="side" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <template v-if="name === 'home'"><path d="M2 7l6-5 6 5v7H2V7z" /></template>
        <template v-else-if="name === 'users'">
            <circle cx="6" cy="5.5" r="2.5" />
            <circle cx="11.5" cy="6" r="2" />
            <path d="M2 13c0-2.2 1.8-4 4-4s4 1.8 4 4M14 13c0-1.6-1.3-3-3-3" />
        </template>
        <template v-else-if="name === 'user'">
            <circle cx="8" cy="5.5" r="2.5" />
            <path d="M3 14c0-2.8 2.2-5 5-5s5 2.2 5 5" />
        </template>
        <template v-else-if="name === 'customer'">
            <rect x="2" y="4" width="12" height="9" rx="1" />
            <path d="M5 4V3a3 3 0 016 0v1" />
        </template>
        <template v-else-if="name === 'role'">
            <path d="M8 2l5 2v5c0 3-2 5-5 6-3-1-5-3-5-6V4l5-2z" />
        </template>
        <template v-else-if="name === 'key'">
            <circle cx="5" cy="10.5" r="2.5" />
            <path d="M7.2 9L13 3.3M11 5l2 2" />
        </template>
        <template v-else-if="name === 'cog'">
            <circle cx="8" cy="8" r="2.5" />
            <path d="M8 1v2M8 13v2M1 8h2M13 8h2M3 3l1.5 1.5M11.5 11.5L13 13M3 13l1.5-1.5M11.5 4.5L13 3" />
        </template>
        <template v-else-if="name === 'mail'">
            <rect x="2" y="3.5" width="12" height="9" rx="1" />
            <path d="M2.5 4.5L8 9l5.5-4.5" />
        </template>
        <template v-else-if="name === 'disk'">
            <ellipse cx="8" cy="4" rx="5.5" ry="2" />
            <path d="M2.5 4v8c0 1.1 2.5 2 5.5 2s5.5-.9 5.5-2V4" />
            <path d="M2.5 8c0 1.1 2.5 2 5.5 2s5.5-.9 5.5-2" />
        </template>
        <template v-else-if="name === 'shield'">
            <path d="M8 2l5 2v5c0 3-2 5-5 6-3-1-5-3-5-6V4l5-2z" />
            <path d="M6 8l1.5 1.5L10 7" />
        </template>
        <template v-else-if="name === 'server'">
            <rect x="2" y="3" width="12" height="4" rx="1" />
            <rect x="2" y="9" width="12" height="4" rx="1" />
            <circle cx="4.5" cy="5" r="0.5" fill="currentColor" />
            <circle cx="4.5" cy="11" r="0.5" fill="currentColor" />
        </template>
        <template v-else-if="name === 'box'">
            <path d="M8 1.5 14 5v6L8 14.5 2 11V5l6-3.5z" />
            <path d="M2 5l6 3.5L14 5" />
            <path d="M8 8.5V14.5" />
        </template>
        <template v-else-if="name === 'log'">
            <path d="M3 2h7l3 3v9H3V2z" />
            <path d="M5 7h6M5 9.5h6M5 12h4" />
        </template>
        <template v-else-if="name === 'bell'">
            <path d="M4 12V8a4 4 0 118 0v4l1 1H3l1-1z" />
            <path d="M6.5 14a1.5 1.5 0 003 0" />
        </template>
        <template v-else-if="name === 'search'">
            <circle cx="7" cy="7" r="4.5" />
            <path d="M10.5 10.5L14 14" />
        </template>
        <template v-else-if="name === 'chevR'">
            <path d="M6 3l5 5-5 5" />
        </template>
        <template v-else-if="name === 'chevD'">
            <path d="M3 6l5 5 5-5" />
        </template>
        <template v-else-if="name === 'plus'">
            <path d="M8 3v10M3 8h10" stroke-width="1.8" />
        </template>
        <template v-else-if="name === 'edit'">
            <path d="M3 13l3-1 7-7-2-2-7 7-1 3z" />
        </template>
        <template v-else-if="name === 'trash'">
            <path d="M3 4h10M6 4V2h4v2M4 4l1 10h6l1-10" />
        </template>
        <template v-else-if="name === 'restore'">
            <path d="M3 8a5 5 0 105-5H5M3 3v3h3" />
        </template>
        <template v-else-if="name === 'arrow'">
            <path d="M3 8h10M9 4l4 4-4 4" />
        </template>
        <template v-else-if="name === 'check'">
            <path d="M3 8l3 3 7-7" stroke-linecap="round" stroke-linejoin="round" />
        </template>
        <template v-else-if="name === 'x'">
            <path d="M4 4l8 8M12 4l-8 8" stroke-linecap="round" />
        </template>
        <template v-else-if="name === 'alert'">
            <path d="M8 2l6.5 11h-13L8 2zM8 6v3M8 11.5v.01" stroke-linecap="round" stroke-linejoin="round" />
        </template>
        <template v-else-if="name === 'link'">
            <path d="M6.5 9.5a2.5 2.5 0 010-3.5L9 4a2.5 2.5 0 013.5 3.5l-1.25 1.25M9.5 6.5a2.5 2.5 0 010 3.5L7 12.5A2.5 2.5 0 013.5 9l1.25-1.25" stroke-linecap="round" />
        </template>
    </svg>
</template>
