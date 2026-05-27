<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import AuthCard from '@/Components/Command/AuthCard.vue';
import Icon from '@/Components/Command/Icon.vue';
import Dot from '@/Components/Command/Dot.vue';
import type { PageProps } from '@/types';

defineOptions({ layout: AuthLayout });

const { t } = useI18n();
const page = usePage<PageProps>();

const form = useForm({});
// Fortify's resend sets the session `status` key to 'verification-link-sent'.
const sent = computed(() => page.props.flash?.status === 'verification-link-sent');

function resend() {
    form.post('/email/verification-notification');
}
</script>

<template>
    <Head :title="t('auth.verify_title')" />

    <AuthCard
        :eyebrow="t('auth.verify_title')"
        :title="t('auth.verify_subtitle')"
        :subtitle="t('auth.verify_check_spam')"
    >
        <div
            v-if="sent"
            :style="{
                display: 'flex',
                alignItems: 'center',
                gap: '8px',
                padding: '9px 12px',
                marginBottom: '14px',
                borderRadius: '5px',
                background: 'rgba(94,229,154,0.12)',
                border: '1px solid rgba(94,229,154,0.33)',
                color: 'var(--fg)',
                fontSize: '12px',
            }"
        >
            <Dot color="var(--success)" :size="6" />
            <span>{{ t('auth.verify_resent') }}</span>
        </div>

        <form @submit.prevent="resend">
            <button
                type="submit"
                :disabled="form.processing"
                :style="{
                    width: '100%',
                    background: 'var(--accent)',
                    color: '#fff',
                    border: 'none',
                    padding: '10px 14px',
                    borderRadius: '5px',
                    fontSize: '12.5px',
                    fontWeight: 500,
                    cursor: form.processing ? 'not-allowed' : 'pointer',
                    opacity: form.processing ? 0.6 : 1,
                    fontFamily: 'inherit',
                    display: 'inline-flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    gap: '6px',
                }"
            >
                <Icon name="mail" :size="12" />
                {{ t('auth.verify_resend') }}
            </button>
        </form>
    </AuthCard>
</template>
