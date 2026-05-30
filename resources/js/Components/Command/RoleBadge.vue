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
    SuperAdmin: { fg: 'var(--danger)', bg: 'var(--danger-soft)', border: 'var(--danger-border)' },
    Admin: { fg: 'var(--role-admin)', bg: 'var(--role-admin-soft)', border: 'var(--role-admin-border)' },
    Editor: { fg: 'var(--warning)', bg: 'var(--warning-soft)', border: 'var(--warning-border)' },
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
