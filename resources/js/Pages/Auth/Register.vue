<script setup lang="ts">
import { Head, useForm, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import AuthCard from '@/Components/Command/AuthCard.vue';
import Field from '@/Components/Command/Field.vue';
import Icon from '@/Components/Command/Icon.vue';
import type { PageProps } from '@/types';

defineOptions({ layout: AuthLayout });

const { t } = useI18n();
const page = usePage<PageProps>();
const oauthProviders = computed(() => page.props.oauth?.providers ?? []);

// When arriving from a workspace invitation link the email is passed as a
// query param so the invitee registers with the invited address (the invite is
// matched on email, then auto-accepted at the landing page).
const invitedEmail = typeof window !== 'undefined'
    ? (new URLSearchParams(window.location.search).get('email') ?? '')
    : '';

const form = useForm({
    first_name: '',
    last_name: '',
    email: invitedEmail,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head :title="t('nav.register')" />

    <AuthCard
        :eyebrow="t('auth.register_title')"
        :title="t('auth.register_subtitle')"
    >
        <form @submit.prevent="submit" :style="{ display: 'flex', flexDirection: 'column', gap: '14px' }">
            <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px' }">
                <Field
                    v-model="form.first_name"
                    :label="t('auth.first_name')"
                    :placeholder="t('auth.first_name')"
                    :error="form.errors.first_name"
                    autocomplete="given-name"
                    autofocus
                />
                <Field
                    v-model="form.last_name"
                    :label="t('auth.last_name')"
                    :placeholder="t('auth.last_name')"
                    :error="form.errors.last_name"
                    autocomplete="family-name"
                />
            </div>

            <Field
                v-model="form.email"
                type="email"
                :label="t('auth.email')"
                :placeholder="t('auth.email')"
                :error="form.errors.email"
                autocomplete="email"
            />
            <Field
                v-model="form.password"
                type="password"
                :label="t('auth.password')"
                :placeholder="t('auth.password')"
                :error="form.errors.password"
                autocomplete="new-password"
            />
            <Field
                v-model="form.password_confirmation"
                type="password"
                :label="t('auth.password_confirmation')"
                :placeholder="t('auth.password_confirmation')"
                :error="form.errors.password_confirmation"
                autocomplete="new-password"
            />

            <button
                type="submit"
                :disabled="form.processing"
                :style="{
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
                    marginTop: '4px',
                }"
            >
                {{ t('nav.register') }}
                <Icon name="arrow" :size="12" />
            </button>
        </form>

        <div v-if="oauthProviders.length > 0" :style="{ marginTop: '20px' }">
            <div :style="{ display: 'flex', alignItems: 'center', gap: '10px', margin: '0 0 12px' }">
                <div :style="{ flex: 1, height: '1px', background: 'var(--border)' }" />
                <span
                    class="cmd-mono cmd-uc"
                    :style="{ fontSize: '9.5px', color: 'var(--fg-mute)', letterSpacing: '0.08em' }"
                >{{ t('auth.or_continue_with') }}</span>
                <div :style="{ flex: 1, height: '1px', background: 'var(--border)' }" />
            </div>
            <div :style="{ display: 'grid', gap: '8px', gridTemplateColumns: oauthProviders.length > 1 ? '1fr 1fr' : '1fr' }">
                <a
                    v-for="p in oauthProviders"
                    :key="p.name"
                    :href="`/auth/${p.name}/redirect`"
                    :style="{
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        gap: '8px',
                        padding: '8px 12px',
                        background: 'var(--panel2)',
                        border: '1px solid var(--border)',
                        borderRadius: '5px',
                        color: 'var(--fg)',
                        fontSize: '12px',
                        textDecoration: 'none',
                    }"
                >
                    <i :class="['pi', `pi-${p.name}`]" :style="{ fontSize: '14px' }"></i>
                    <span>{{ p.label }}</span>
                </a>
            </div>
        </div>

        <p
            :style="{ marginTop: '20px', textAlign: 'center', fontSize: '12px', color: 'var(--fg-dim)' }"
        >
            {{ t('auth.have_account') }}
            <Link href="/login" :style="{ color: 'var(--accent)', textDecoration: 'none', fontWeight: 500 }">
                {{ t('nav.login') }}
            </Link>
        </p>
    </AuthCard>
</template>
