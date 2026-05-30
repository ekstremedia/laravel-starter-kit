<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * The coloured pill used to render an EquipmentCategory wherever it appears —
 * the Equipment list/detail, the category pages, and the dashboard widget.
 * A null category renders a muted "uncategorised" label.
 */
const props = withDefaults(
    defineProps<{
        category: { name: string; color?: string | null } | null;
        /** Override the empty-state label (defaults to equipment.no_category). */
        emptyLabel?: string;
    }>(),
    { emptyLabel: '' },
);

const { t } = useI18n();
</script>

<template>
    <span
        v-if="props.category"
        :style="{
            display: 'inline-flex',
            alignItems: 'center',
            gap: '6px',
            padding: '2px 9px 2px 7px',
            borderRadius: '999px',
            fontSize: '11.5px',
            lineHeight: 1.5,
            color: 'var(--fg-dim)',
            background: 'var(--panel2)',
            border: '1px solid var(--border)',
            maxWidth: '100%',
        }"
    >
        <span
            :style="{
                flexShrink: 0,
                width: '8px',
                height: '8px',
                borderRadius: '50%',
                background: props.category.color || 'var(--fg-mute)',
            }"
        />
        <span :style="{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">{{ props.category.name }}</span>
    </span>
    <span v-else :style="{ color: 'var(--fg-mute)' }">{{ props.emptyLabel || t('equipment.no_category') }}</span>
</template>
