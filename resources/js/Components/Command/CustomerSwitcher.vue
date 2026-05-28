<script setup lang="ts">
/*
 * Command-styled customer switcher. Drops into the topbar between the
 * command button and the bell when tenancy is enabled and the user has at
 * least one membership.
 *
 * - 0 memberships → hidden (no bare chip)
 * - 1 membership  → Link pill (always clickable even when already scoped)
 * - N memberships → button + dropdown with accent-soft highlight on current
 *
 * All navigation targets `/c/{slug}/dashboard`, which triggers
 * InitializeTenancyByPath server-side to swap the schema.
 */
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { Customer, PageProps } from '@/types';
import Icon from './Icon.vue';

const { t } = useI18n();
const page = usePage<PageProps>();

const current = computed<Customer | null>(() => page.props.customer ?? null);
const list = computed<Customer[]>(() => page.props.available_customers ?? []);

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

const visible = computed(() => Boolean(page.props.tenancy?.enabled) && list.value.length > 0);
const hasMany = computed(() => list.value.length > 1);
const soleCustomer = computed<Customer | null>(() => (list.value.length === 1 ? list.value[0] : null));

const triggerLabel = computed<string>(() => {
    if (current.value) return current.value.name;
    if (soleCustomer.value) return soleCustomer.value.name;
    return t('customer_switcher.pick');
});

function urlFor(c: Customer): string {
    return `/c/${c.slug}/dashboard`;
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
            v-if="soleCustomer"
            :href="urlFor(soleCustomer)"
            :style="pillStyle"
        >
            <Icon name="customer" :size="12" :style="{ color: 'var(--accent)' }" />
            <span class="cmd-cust-name" :style="{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">
                {{ soleCustomer.name }}
            </span>
        </Link>

        <button
            v-else
            type="button"
            :disabled="!hasMany"
            @click="toggle"
            :style="pillStyle"
        >
            <Icon name="customer" :size="12" :style="{ color: 'var(--accent)' }" />
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
            >{{ t('customer_switcher.customers') }}</div>
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
                    <Icon name="customer" :size="11" />
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
