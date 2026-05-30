<script setup lang="ts">
/*
 * Command modal dialog. Teleport to body, backdrop with fade, panel
 * with `cmdFadeIn`. Esc closes, backdrop click closes (unless
 * `closeOnBackdrop` is false), focus moves into the dialog on open
 * and returns to the previously-focused element on close. Use
 * `header`, default, and `footer` slots. Opts for token-driven
 * styling rather than Tailwind so theme/accent switches apply.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';

interface Props {
    visible: boolean;
    title?: string;
    width?: string;
    closeOnBackdrop?: boolean;
    showClose?: boolean;
    padded?: boolean;
    footerAlign?: 'end' | 'between';
    labelledBy?: string;
}

const props = withDefaults(defineProps<Props>(), {
    title: '',
    width: '420px',
    closeOnBackdrop: true,
    showClose: true,
    padded: true,
    footerAlign: 'end',
    labelledBy: undefined,
});

const emit = defineEmits<{
    'update:visible': [value: boolean];
    close: [];
}>();

const { t } = useI18n();
const panel = ref<HTMLDivElement | null>(null);
const autoId = useId();
const titleId = computed(() => props.labelledBy ?? `cmd-dialog-${autoId}-title`);
let lastFocused: HTMLElement | null = null;

function close() {
    emit('update:visible', false);
    emit('close');
}

function onBackdrop() {
    if (props.closeOnBackdrop) close();
}

function onKeydown(e: KeyboardEvent) {
    if (!props.visible) return;
    if (e.key === 'Escape') {
        e.preventDefault();
        close();
        return;
    }
    if (e.key === 'Tab' && panel.value) {
        const focusable = panel.value.querySelectorAll<HTMLElement>(
            'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
        );
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        const active = document.activeElement as HTMLElement | null;
        if (e.shiftKey && active === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && active === last) {
            e.preventDefault();
            first.focus();
        }
    }
}

watch(
    () => props.visible,
    (v) => {
        if (v) {
            lastFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            nextTick(() => {
                // Prefer an explicit [data-autofocus] target (e.g. a Field with
                // autofocus) over the first focusable — otherwise querySelector
                // returns the Close button, which is first in DOM order.
                const focusTarget = panel.value?.querySelector<HTMLElement>('[data-autofocus]')
                    ?? panel.value?.querySelector<HTMLElement>(
                        'input:not([disabled]), textarea:not([disabled]), select:not([disabled]), button:not([disabled])',
                    );
                focusTarget?.focus();
            });
        } else {
            lastFocused?.focus();
            lastFocused = null;
        }
    },
);

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <Transition
            :enter-active-class="'cmd-dialog-enter'"
            :leave-active-class="'cmd-dialog-leave'"
        >
            <div
                v-if="visible"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                :style="{
                    position: 'fixed',
                    inset: 0,
                    zIndex: 90,
                    background: 'rgba(0, 0, 0, 0.55)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    padding: '16px',
                    backdropFilter: 'blur(2px)',
                }"
                @click.self="onBackdrop"
            >
                <div
                    ref="panel"
                    :style="{
                        width: '100%',
                        maxWidth: width,
                        maxHeight: 'calc(100vh - 32px)',
                        display: 'flex',
                        flexDirection: 'column',
                        background: 'var(--panel)',
                        border: '1px solid var(--border)',
                        borderRadius: '8px',
                        boxShadow: 'var(--shadow-palette)',
                        overflow: 'hidden',
                        animation: 'cmdFadeIn 0.14s ease-out',
                    }"
                >
                    <header
                        v-if="$slots.header || title || showClose"
                        :style="{
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'space-between',
                            gap: '12px',
                            padding: '12px 16px',
                            borderBottom: '1px solid var(--border)',
                            flexShrink: 0,
                        }"
                    >
                        <slot name="header">
                            <h2
                                :id="titleId"
                                :style="{
                                    margin: 0,
                                    fontSize: '13px',
                                    fontWeight: 600,
                                    color: 'var(--fg)',
                                    letterSpacing: '-0.005em',
                                }"
                            >{{ title }}</h2>
                        </slot>
                        <button
                            v-if="showClose"
                            type="button"
                            :aria-label="t('common.close')"
                            @click="close"
                            :style="{
                                background: 'transparent',
                                border: 'none',
                                color: 'var(--fg-mute)',
                                cursor: 'pointer',
                                padding: '4px',
                                borderRadius: '4px',
                                display: 'inline-flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                fontSize: '14px',
                                lineHeight: 1,
                            }"
                            class="cmd-dialog-close"
                        >
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                                <path d="M3 3l8 8M11 3l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                            </svg>
                        </button>
                    </header>

                    <div
                        :style="{
                            padding: padded ? '16px' : '0',
                            overflowY: 'auto',
                            color: 'var(--fg)',
                            fontSize: '13px',
                        }"
                    >
                        <slot />
                    </div>

                    <footer
                        v-if="$slots.footer"
                        :style="{
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: footerAlign === 'between' ? 'space-between' : 'flex-end',
                            gap: '8px',
                            padding: '12px 16px',
                            borderTop: '1px solid var(--border)',
                            flexShrink: 0,
                            background: 'var(--bg2)',
                        }"
                    >
                        <slot name="footer" />
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.cmd-dialog-enter {
    animation: cmdBackdropIn 0.14s ease-out;
}
.cmd-dialog-leave {
    animation: cmdBackdropOut 0.12s ease-in;
}
.cmd-dialog-close:hover {
    background: var(--panel2) !important;
    color: var(--fg) !important;
}
@keyframes cmdBackdropIn {
    from { opacity: 0; }
    to   { opacity: 1; }
}
@keyframes cmdBackdropOut {
    from { opacity: 1; }
    to   { opacity: 0; }
}
</style>
