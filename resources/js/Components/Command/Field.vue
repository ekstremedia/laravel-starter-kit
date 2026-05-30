<script setup lang="ts">
/*
 * Command-styled text field: small mono uppercase label, panel2 input,
 * accent border on focus, danger border + message on error.
 */
import { computed, useId } from 'vue';

interface Props {
    modelValue: string | number | null;
    type?: string;
    label?: string;
    placeholder?: string;
    error?: string | null;
    autocomplete?: string;
    autofocus?: boolean;
    disabled?: boolean;
    required?: boolean;
    numeric?: boolean;
    min?: number;
    max?: number;
    id?: string;
    inputmode?: 'none' | 'text' | 'tel' | 'url' | 'email' | 'numeric' | 'decimal' | 'search';
}

const props = withDefaults(defineProps<Props>(), {
    type: 'text',
    label: '',
    placeholder: '',
    error: '',
    autocomplete: '',
    autofocus: false,
    disabled: false,
    required: false,
    numeric: false,
    min: undefined,
    max: undefined,
    id: undefined,
    inputmode: undefined,
});

const emit = defineEmits<{ 'update:modelValue': [value: string | number] }>();

const autoId = useId();
const inputId = computed(() => props.id ?? `cmd-field-${autoId}`);

const value = computed({
    get: () => props.modelValue ?? '',
    set: (v) => emit('update:modelValue', props.type === 'number' ? Number(v) : (v as string)),
});

const inputStyle = computed(() => ({
    width: '100%',
    background: 'var(--panel2)',
    border: `1px solid ${props.error ? 'var(--danger)' : 'var(--border)'}`,
    borderRadius: '5px',
    padding: '8px 10px',
    color: 'var(--fg)',
    fontSize: '13px',
    outline: 'none',
    fontFamily: props.numeric ? 'var(--font-mono)' : 'inherit',
    transition: 'border-color 0.12s',
}));

function onFocus(e: FocusEvent) {
    if (!props.error && e.target instanceof HTMLInputElement) {
        e.target.style.borderColor = 'var(--accent)';
    }
}
function onBlur(e: FocusEvent) {
    if (!props.error && e.target instanceof HTMLInputElement) {
        e.target.style.borderColor = 'var(--border)';
    }
}
</script>

<template>
    <div>
        <label
            v-if="label"
            :for="inputId"
            class="cmd-mono cmd-uc"
            :style="{ display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em', fontWeight: 500 }"
        >{{ label }}</label>
        <input
            :id="inputId"
            v-model="value"
            :type="type"
            :placeholder="placeholder"
            :autocomplete="autocomplete"
            :autofocus="autofocus"
            :data-autofocus="autofocus || undefined"
            :disabled="disabled"
            :required="required"
            :min="min"
            :max="max"
            :inputmode="inputmode"
            :aria-invalid="!!error"
            :aria-describedby="error ? `${inputId}-error` : undefined"
            :style="inputStyle"
            @focus="onFocus"
            @blur="onBlur"
        />
        <div
            v-if="error"
            :id="`${inputId}-error`"
            :style="{ color: 'var(--danger)', fontSize: '11px', marginTop: '4px' }"
        >{{ error }}</div>
    </div>
</template>
