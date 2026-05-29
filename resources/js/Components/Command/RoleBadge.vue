<script setup lang="ts">
/*
 * Role pill with a consistent per-role tone. Centralizes the role→colour
 * mapping that was duplicated as roleTone*() helpers across the admin Users
 * Index and Edit pages. Pass `role` for the colour; the displayed text is the
 * default slot (falls back to `label`, then the role name) so callers can show
 * e.g. "SUPER" for SuperAdmin.
 */
import { computed } from 'vue';

const props = defineProps<{ role: string; label?: string }>();

const TONES: Record<string, { fg: string; bg: string; border: string }> = {
    SuperAdmin: { fg: '#ef4444', bg: 'rgba(239,68,68,0.12)', border: 'rgba(239,68,68,0.33)' },
    Admin: { fg: '#8b5cf6', bg: 'rgba(139,92,246,0.12)', border: 'rgba(139,92,246,0.33)' },
    Editor: { fg: 'var(--warning)', bg: 'rgba(251,191,36,0.12)', border: 'rgba(251,191,36,0.33)' },
};

const tone = computed(
    () => TONES[props.role] ?? { fg: 'var(--accent)', bg: 'var(--accent-soft)', border: 'var(--accent-border)' },
);
</script>

<template>
    <span
        class="cmd-mono"
        :style="{
            display: 'inline-flex',
            alignItems: 'center',
            fontSize: '10px',
            padding: '1px 6px',
            borderRadius: 'var(--radius-chip, 3px)',
            letterSpacing: '0.02em',
            color: tone.fg,
            background: tone.bg,
            border: `1px solid ${tone.border}`,
        }"
    ><slot>{{ label ?? role }}</slot></span>
</template>
