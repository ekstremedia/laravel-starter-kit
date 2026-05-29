<script setup lang="ts">
/*
 * Standard page header: title + optional subtitle on the left, optional actions
 * on the right. Consolidates the h1 + subtitle block repeated across the admin
 * and settings pages so the type scale and spacing stay identical everywhere.
 *
 * Usage:
 *   <PageTitle :title="t('admin.backups.title')" :subtitle="t('admin.backups.subtitle')">
 *     <template #actions> ...buttons... </template>
 *   </PageTitle>
 */
defineProps<{ title: string; subtitle?: string }>();
</script>

<template>
    <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', gap: '12px', marginBottom: '14px' }">
        <div :style="{ minWidth: 0 }">
            <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">{{ title }}</h1>
            <div
                v-if="subtitle || $slots.subtitle"
                class="cmd-mono"
                :style="{ marginTop: '3px', fontSize: '11.5px', color: 'var(--fg-mute)' }"
            ><slot name="subtitle">{{ subtitle }}</slot></div>
        </div>
        <div v-if="$slots.actions" :style="{ display: 'flex', gap: '6px', flexShrink: 0, alignItems: 'center' }">
            <slot name="actions" />
        </div>
    </div>
</template>
