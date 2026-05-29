<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Command/Icon.vue';
import { useCustomer } from '@/composables/useCustomer';
import type { PageProps } from '@/types';

/**
 * Segmented pill switching between Private Files (`/files`) and Shared
 * Files (`/files/company`). The active side is highlighted with an
 * accent background; the inactive side is a muted link so the user can
 * see both exist without visual noise.
 *
 * Hides the Shared tab when either the customer hasn't enabled the
 * feature or the viewer lacks `view company files`. Super admins always
 * see both tabs on feature-enabled customers.
 */
const props = defineProps<{
    active: 'private' | 'shared';
    permissions?: { canViewShared?: boolean } | null;
}>();

const { t } = useI18n();
const { customerUrl, customer } = useCustomer();
const page = usePage<PageProps>();

const showShared = computed<boolean>(() => {
    // Shared files are a multi-tenant concept — without tenancy there's no
    // "company" to share into, so the tab (and the whole switcher) drops.
    if (!page.props.tenancy?.enabled) return false;
    if (!customer.value?.files_feature_enabled) return false;
    if (!customer.value?.company_files_enabled) return false;
    return props.permissions?.canViewShared ?? false;
});
</script>

<template>
    <!-- With no Shared tab there's nothing to switch between, so the pill
         disappears entirely rather than showing a lone "Private" chip. -->
    <div
        v-if="showShared"
        role="tablist"
        :aria-label="t('files.scope_switcher')"
        :style="{
            display: 'inline-flex',
            alignItems: 'center',
            gap: '2px',
            padding: '3px',
            background: 'var(--panel2)',
            border: '1px solid var(--border)',
            borderRadius: '7px',
        }"
    >
        <Link
            :href="customerUrl('/files')"
            role="tab"
            :aria-selected="active === 'private'"
            :style="{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '6px',
                padding: '5px 10px',
                fontSize: '12px',
                fontWeight: active === 'private' ? 600 : 500,
                color: active === 'private' ? 'var(--fg)' : 'var(--fg-dim)',
                background: active === 'private' ? 'var(--panel)' : 'transparent',
                border: active === 'private' ? '1px solid var(--border)' : '1px solid transparent',
                borderRadius: '5px',
                textDecoration: 'none',
                transition: 'color .15s, background .15s',
            }"
        >
            <Icon name="user" :size="12" />
            <span>{{ t('files.scope_private') }}</span>
        </Link>
        <Link
            v-if="showShared"
            :href="customerUrl('/files/company')"
            role="tab"
            :aria-selected="active === 'shared'"
            :style="{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '6px',
                padding: '5px 10px',
                fontSize: '12px',
                fontWeight: active === 'shared' ? 600 : 500,
                color: active === 'shared' ? 'var(--fg)' : 'var(--fg-dim)',
                background: active === 'shared' ? 'var(--panel)' : 'transparent',
                border: active === 'shared' ? '1px solid var(--border)' : '1px solid transparent',
                borderRadius: '5px',
                textDecoration: 'none',
                transition: 'color .15s, background .15s',
            }"
        >
            <Icon name="customer" :size="12" />
            <span>{{ t('files.scope_shared') }}</span>
        </Link>
    </div>
</template>
