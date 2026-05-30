<script setup lang="ts" generic="T extends string | number">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Icon, { type IconName } from '@/Components/Command/Icon.vue';

/**
 * A custom select rendered as the same popover menu the Equipment toolbar uses
 * for Export / Columns — a button + role="menu" panel with click-outside and
 * Escape handling, an optional colour swatch per option, and a check on the
 * active item. Preferred over a native <select> wherever the menu look matters
 * (e.g. the category filter / picker). Composes tokens, never raw Tailwind.
 */
export interface MenuOption<V extends string | number> {
    value: V;
    label: string;
    color?: string | null;
}

const props = withDefaults(
    defineProps<{
        /** The selected value, or null/'' for "none" (the placeholder/empty row). */
        modelValue: T | null | '';
        options: MenuOption<T>[];
        /** Label for the button when nothing is selected, and for the empty row. */
        placeholder?: string;
        /** Render a first row that clears the selection (value → ''). */
        includeEmpty?: boolean;
        /** Which edge the panel aligns to. */
        align?: 'left' | 'right';
        ariaLabel?: string;
        disabled?: boolean;
        /** Leading icon name shown in the trigger. */
        icon?: IconName;
        /** Stretch the trigger to fill its container (form-field usage). */
        block?: boolean;
    }>(),
    { placeholder: '', includeEmpty: false, align: 'left', disabled: false, block: false },
);

const emit = defineEmits<{ 'update:modelValue': [value: T | ''] }>();

const open = ref(false);
const wrap = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);

// The panel is teleported to <body> and positioned with fixed coords from the
// trigger's rect, so it overlays dialogs/scroll containers instead of being
// clipped by their overflow.
const panelStyle = ref<Record<string, string>>({});

function updatePosition() {
    const el = trigger.value;
    if (!el) {
        return;
    }
    const r = el.getBoundingClientRect();
    const style: Record<string, string> = {
        position: 'fixed',
        top: `${r.bottom + 4}px`,
        minWidth: `${Math.max(r.width, 180)}px`,
    };
    if (props.align === 'right') {
        style.right = `${window.innerWidth - r.right}px`;
    } else {
        style.left = `${r.left}px`;
    }
    panelStyle.value = style;
}

// Keep the floating panel pinned to the trigger while open (e.g. when the user
// scrolls a dialog body). `true` capture catches scroll on nested containers.
watch(open, (isOpen) => {
    if (isOpen) {
        updatePosition();
        window.addEventListener('scroll', updatePosition, true);
        window.addEventListener('resize', updatePosition);
    } else {
        window.removeEventListener('scroll', updatePosition, true);
        window.removeEventListener('resize', updatePosition);
    }
});

const selected = computed(() =>
    props.modelValue === '' || props.modelValue === null
        ? null
        : props.options.find((o) => o.value === props.modelValue) ?? null,
);

function toggle() {
    if (props.disabled) return;
    open.value = !open.value;
    if (open.value) {
        // Focus the first menu item so keyboard users land inside the panel.
        requestAnimationFrame(() => panel.value?.querySelector<HTMLElement>('[role="menuitem"]')?.focus());
    }
}

function choose(value: T | '') {
    emit('update:modelValue', value);
    open.value = false;
    // Restore focus to the trigger so keyboard users keep their place (the
    // active menuitem is about to unmount).
    trigger.value?.focus();
}

function onDocPointerDown(e: PointerEvent) {
    // Outside click: just close — don't steal focus back to the trigger, the
    // user is interacting elsewhere. The panel is teleported out of `wrap`, so
    // check it too or a click on a menu item would close before it registers.
    if (!open.value) {
        return;
    }
    const target = e.target as Node;
    if (wrap.value?.contains(target) || panel.value?.contains(target)) {
        return;
    }
    open.value = false;
}
function onKeydown(e: KeyboardEvent) {
    if (!open.value) return;
    if (e.key === 'Escape') {
        open.value = false;
        trigger.value?.focus();
        return;
    }
    // Roving focus across the menu items.
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const items = [...(panel.value?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])];
        if (!items.length) return;
        const i = items.indexOf(document.activeElement as HTMLElement);
        const next = e.key === 'ArrowDown' ? i + 1 : i - 1;
        items[(next + items.length) % items.length]?.focus();
    }
}
onMounted(() => {
    document.addEventListener('pointerdown', onDocPointerDown);
    document.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocPointerDown);
    document.removeEventListener('keydown', onKeydown);
    window.removeEventListener('scroll', updatePosition, true);
    window.removeEventListener('resize', updatePosition);
});

const triggerStyle = {
    background: 'var(--panel2)',
    color: 'var(--fg)',
    border: '1px solid var(--border)',
    padding: '5px 9px',
    borderRadius: '5px',
    fontSize: '11.5px',
    display: 'inline-flex',
    alignItems: 'center',
    gap: '6px',
    cursor: 'pointer',
    fontFamily: 'inherit',
    outline: 'none',
};
const swatchStyle = (color?: string | null) => ({
    flexShrink: 0,
    width: '10px',
    height: '10px',
    borderRadius: '3px',
    background: color || 'var(--fg-mute)',
    border: '1px solid rgba(0,0,0,0.18)',
});
const itemStyle = {
    display: 'flex',
    alignItems: 'center',
    gap: '8px',
    width: '100%',
    textAlign: 'left' as const,
    background: 'transparent',
    border: 'none',
    padding: '7px 10px',
    fontSize: '12px',
    color: 'var(--fg)',
    cursor: 'pointer',
    fontFamily: 'inherit',
};
</script>

<template>
    <div ref="wrap" :style="{ position: 'relative', width: block ? '100%' : undefined }">
        <button
            ref="trigger"
            type="button"
            aria-haspopup="menu"
            :aria-expanded="open"
            :aria-label="ariaLabel"
            :disabled="disabled"
            :style="{ ...triggerStyle, opacity: disabled ? 0.55 : 1, width: block ? '100%' : undefined, justifyContent: block ? 'space-between' : undefined }"
            @click="toggle"
        >
            <Icon v-if="icon" :name="icon" :size="12" :style="{ color: 'var(--fg-mute)' }" />
            <span v-if="selected" :style="swatchStyle(selected.color)" />
            <span :style="{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: '160px', color: selected ? 'var(--fg)' : 'var(--fg-mute)' }">
                {{ selected ? selected.label : placeholder }}
            </span>
            <Icon name="chevD" :size="9" :style="{ color: 'var(--fg-mute)', marginLeft: '2px' }" />
        </button>

        <Teleport to="body">
        <div
            v-if="open"
            ref="panel"
            role="menu"
            :style="{
                ...panelStyle,
                background: 'var(--panel)',
                border: '1px solid var(--border)',
                borderRadius: '6px',
                boxShadow: 'var(--shadow-palette, 0 10px 30px rgba(0,0,0,0.3))',
                zIndex: 200,
                maxHeight: '280px',
                overflowY: 'auto',
                padding: '4px',
            }"
        >
            <button
                v-if="includeEmpty"
                type="button"
                role="menuitem"
                class="cmd-menu-item"
                :style="itemStyle"
                @click="choose('')"
            >
                <span :style="{ flexShrink: 0, width: '14px', display: 'inline-flex', justifyContent: 'center' }">
                    <Icon v-if="modelValue === '' || modelValue === null" name="check" :size="12" :style="{ color: 'var(--accent)' }" />
                </span>
                <span :style="{ color: 'var(--fg-mute)' }">{{ placeholder }}</span>
            </button>

            <button
                v-for="opt in options"
                :key="opt.value"
                type="button"
                role="menuitem"
                class="cmd-menu-item"
                :style="itemStyle"
                @click="choose(opt.value)"
            >
                <span :style="{ flexShrink: 0, width: '14px', display: 'inline-flex', justifyContent: 'center' }">
                    <Icon v-if="opt.value === modelValue" name="check" :size="12" :style="{ color: 'var(--accent)' }" />
                </span>
                <span v-if="opt.color !== undefined" :style="swatchStyle(opt.color)" />
                <span :style="{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">{{ opt.label }}</span>
            </button>
        </div>
        </Teleport>
    </div>
</template>

<style scoped>
.cmd-menu-item:hover,
.cmd-menu-item:focus-visible {
    background: var(--row-hover);
    outline: none;
}
</style>
