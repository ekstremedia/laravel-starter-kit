<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * A small colour chooser for EquipmentCategory: a row of preset swatches plus a
 * native picker for a custom hex and a "clear" option. Stores a #RRGGBB string
 * (or null). Tokens-only styling.
 */
const props = withDefaults(
    defineProps<{ modelValue: string | null }>(),
    { modelValue: null },
);
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const { t } = useI18n();

const PRESETS = ['#ef4444', '#f59e0b', '#eab308', '#10b981', '#14b8a6', '#3b82f6', '#6366f1', '#8b5cf6', '#ec4899', '#64748b'];

function choose(color: string | null) {
    emit('update:modelValue', color);
}
function onCustom(e: Event) {
    emit('update:modelValue', (e.target as HTMLInputElement).value);
}
</script>

<template>
    <div :style="{ display: 'flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' }">
        <button
            v-for="color in PRESETS"
            :key="color"
            type="button"
            :title="color"
            :aria-label="color"
            :aria-pressed="props.modelValue === color"
            :style="{
                width: '22px',
                height: '22px',
                borderRadius: '6px',
                background: color,
                cursor: 'pointer',
                padding: 0,
                border: props.modelValue === color ? '2px solid var(--fg)' : '2px solid transparent',
                boxShadow: '0 0 0 1px var(--border)',
            }"
            @click="choose(color)"
        />

        <!-- Custom hex via the native picker -->
        <label
            :title="t('equipment_category.custom_color')"
            :style="{
                width: '22px',
                height: '22px',
                borderRadius: '6px',
                cursor: 'pointer',
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                border: '1px dashed var(--border)',
                color: 'var(--fg-mute)',
                fontSize: '13px',
            }"
        >
            <i class="pi pi-plus" :style="{ fontSize: '10px' }" />
            <input
                type="color"
                :value="props.modelValue || '#3b82f6'"
                :style="{ position: 'absolute', width: '1px', height: '1px', opacity: 0, pointerEvents: 'none' }"
                @input="onCustom"
            />
        </label>

        <button
            type="button"
            :style="{
                marginLeft: '2px',
                background: 'transparent',
                border: 'none',
                color: 'var(--fg-mute)',
                fontSize: '11px',
                cursor: 'pointer',
                fontFamily: 'inherit',
                textDecoration: props.modelValue === null ? 'underline' : 'none',
            }"
            @click="choose(null)"
        >
            {{ t('equipment_category.no_color') }}
        </button>
    </div>
</template>
