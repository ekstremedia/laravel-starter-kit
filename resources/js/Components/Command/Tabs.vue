<script setup lang="ts">
/*
 * Minimal, accessible tab bar for the Command design system. The parent owns
 * the active key (v-model) and renders the panels itself (v-if / v-show on the
 * key), so it stays flexible. Arrow keys move between tabs (roving selection).
 */
import Icon, { type IconName } from './Icon.vue';

interface Tab {
    key: string;
    label: string;
    icon?: IconName;
}

const props = defineProps<{ tabs: Tab[]; modelValue: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

function select(key: string) {
    if (key !== props.modelValue) emit('update:modelValue', key);
}

function onKeydown(e: KeyboardEvent) {
    const i = props.tabs.findIndex((t) => t.key === props.modelValue);
    if (i < 0) return;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
        e.preventDefault();
        select(props.tabs[(i + 1) % props.tabs.length].key);
    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
        e.preventDefault();
        select(props.tabs[(i - 1 + props.tabs.length) % props.tabs.length].key);
    }
}
</script>

<template>
    <div role="tablist" class="cmd-tabs" @keydown="onKeydown">
        <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            role="tab"
            :aria-selected="tab.key === modelValue"
            :tabindex="tab.key === modelValue ? 0 : -1"
            class="cmd-tab"
            :class="{ 'cmd-tab-active': tab.key === modelValue }"
            @click="select(tab.key)"
        >
            <Icon v-if="tab.icon" :name="tab.icon" :size="12" />
            <span>{{ tab.label }}</span>
        </button>
    </div>
</template>

<style scoped>
.cmd-tabs {
    display: flex;
    gap: 2px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 18px;
}
.cmd-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 8px 14px;
    margin-bottom: -1px;
    font-size: 12.5px;
    font-family: inherit;
    font-weight: 500;
    color: var(--fg-mute);
    cursor: pointer;
    transition: color 0.12s, border-color 0.12s;
}
.cmd-tab:hover {
    color: var(--fg-dim);
}
.cmd-tab-active {
    color: var(--fg);
    border-bottom-color: var(--accent);
}
</style>
