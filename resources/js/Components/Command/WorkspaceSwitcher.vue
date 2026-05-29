<script setup lang="ts">
/*
 * Command-styled workspace switcher. Drops into the topbar between the
 * command button and the bell when workspaces is enabled and the user has at
 * least one membership.
 *
 * - 0 memberships → hidden (no bare chip)
 * - 1 membership  → Link pill (always clickable even when already scoped)
 * - N memberships → button + dropdown with accent-soft highlight on current
 *
 * All navigation targets `/w/{slug}/dashboard`, which the ResolveWorkspace
 * middleware resolves to the active workspace server-side.
 */
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { Workspace, PageProps } from '@/types';
import Icon from './Icon.vue';

const { t } = useI18n();
const page = usePage<PageProps>();

const current = computed<Workspace | null>(() => page.props.workspace ?? null);
const list = computed<Workspace[]>(() => page.props.available_workspaces ?? []);

const open = ref(false);
const rootRef = ref<HTMLElement | null>(null);

function toggle() {
    if (list.value.length <= 1) return;
    open.value = !open.value;
}

function onDocClick(e: MouseEvent) {
    if (!rootRef.value) return;
    if (!rootRef.value.contains(e.target as Node)) open.value = false;
}

onMounted(() => document.addEventListener('click', onDocClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocClick));

const visible = computed(() => Boolean(page.props.workspaces?.enabled) && list.value.length > 0);
const hasMany = computed(() => list.value.length > 1);
const soleWorkspace = computed<Workspace | null>(() => (list.value.length === 1 ? list.value[0] : null));

const triggerLabel = computed<string>(() => {
    if (current.value) return current.value.name;
    if (soleWorkspace.value) return soleWorkspace.value.name;
    return t('workspace_switcher.pick');
});

function urlFor(c: Workspace): string {
    return `/w/${c.slug}/dashboard`;
}

const pillStyle = {
    display: 'inline-flex',
    alignItems: 'center',
    gap: '7px',
    padding: '4px 9px',
    borderRadius: '5px',
    background: 'var(--panel2)',
    border: '1px solid var(--border)',
    fontSize: '11.5px',
    color: 'var(--fg)',
    cursor: 'pointer',
    fontFamily: 'inherit',
    textDecoration: 'none',
    maxWidth: '200px',
} as const;
</script>

<template>
    <div v-if="visible" ref="rootRef" :style="{ position: 'relative' }">
        <Link
            v-if="soleWorkspace"
            :href="urlFor(soleWorkspace)"
            :style="pillStyle"
        >
            <Icon name="workspace" :size="12" :style="{ color: 'var(--accent)' }" />
            <span class="cmd-cust-name" :style="{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">
                {{ soleWorkspace.name }}
            </span>
        </Link>

        <button
            v-else
            type="button"
            :disabled="!hasMany"
            @click="toggle"
            :style="pillStyle"
        >
            <Icon name="workspace" :size="12" :style="{ color: 'var(--accent)' }" />
            <span class="cmd-cust-name" :style="{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">
                {{ triggerLabel }}
            </span>
            <Icon v-if="hasMany" name="chevD" :size="10" :style="{ color: 'var(--fg-mute)' }" />
        </button>

        <div
            v-if="open && hasMany"
            :style="{
                position: 'absolute',
                right: 0,
                top: 'calc(100% + 4px)',
                width: '240px',
                maxHeight: '320px',
                overflow: 'auto',
                background: 'var(--panel)',
                border: '1px solid var(--border)',
                borderRadius: '6px',
                boxShadow: '0 8px 24px rgba(0,0,0,0.35)',
                zIndex: 40,
                animation: 'cmdFadeIn 0.12s ease-out',
            }"
        >
            <div
                class="cmd-mono cmd-uc"
                :style="{ padding: '8px 10px 4px', fontSize: '9.5px', color: 'var(--fg-mute)', fontWeight: 500 }"
            >{{ t('workspace_switcher.workspaces') }}</div>
            <Link
                v-for="c in list"
                :key="c.id"
                :href="urlFor(c)"
                @click="open = false"
                :style="{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    padding: '7px 10px',
                    fontSize: '12px',
                    cursor: 'pointer',
                    textDecoration: 'none',
                    background: c.id === current?.id ? 'var(--accent-soft)' : 'transparent',
                    color: c.id === current?.id ? 'var(--fg)' : 'var(--fg-dim)',
                }"
            >
                <span :style="{ display: 'flex', alignItems: 'center', gap: '7px', overflow: 'hidden' }">
                    <Icon name="workspace" :size="11" />
                    <span :style="{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">{{ c.name }}</span>
                </span>
                <span v-if="c.id === current?.id" class="cmd-mono" :style="{ fontSize: '9.5px', color: 'var(--accent)' }">●</span>
            </Link>
        </div>
    </div>
</template>

<style scoped>
/* On narrow screens collapse the pill to an icon so the topbar fits. */
@media (max-width: 640px) {
    .cmd-cust-name {
        display: none;
    }
}
</style>
