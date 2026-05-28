<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Field from '@/Components/Command/Field.vue';
import Icon from '@/Components/Command/Icon.vue';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();

const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/admin/users');
}
</script>

<template>
    <div>
    <Head :title="t('admin.users.new_user') + ' · Admin'" />

    <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: '18px' }">
        <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">
            {{ t('admin.users.new_user') }}
        </h1>
        <Link
            href="/admin/users"
            :style="{ fontSize: '11.5px', color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '5px' }"
        >
            <Icon name="chevR" :size="10" :style="{ transform: 'rotate(180deg)' }" />
            {{ t('common.back') }}
        </Link>
    </div>

    <form
        @submit.prevent="submit"
        class="cmd-card"
        :style="{ maxWidth: '680px', padding: '24px', display: 'flex', flexDirection: 'column', gap: '14px' }"
    >
        <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px' }">
            <Field
                v-model="form.first_name"
                :label="t('admin.users.first_name')"
                :error="form.errors.first_name"
                autocomplete="given-name"
                autofocus
            />
            <Field
                v-model="form.last_name"
                :label="t('admin.users.last_name')"
                :error="form.errors.last_name"
                autocomplete="family-name"
            />
        </div>
        <Field
            v-model="form.email"
            type="email"
            :label="t('admin.users.email')"
            :error="form.errors.email"
            autocomplete="email"
        />
        <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px' }">
            <Field
                v-model="form.password"
                type="password"
                :label="t('admin.users.password')"
                :error="form.errors.password"
                autocomplete="new-password"
            />
            <Field
                v-model="form.password_confirmation"
                type="password"
                :label="t('admin.users.confirm_password')"
                autocomplete="new-password"
            />
        </div>

        <div
            :style="{ fontSize: '11.5px', color: 'var(--fg-dim)', lineHeight: 1.5, padding: '10px 12px', borderRadius: '6px', background: 'var(--accent-soft)', border: '1px solid var(--accent-border)' }"
        >{{ t('admin.users.roles_are_customer_scoped') }}</div>

        <div :style="{ display: 'flex', gap: '6px', paddingTop: '4px' }">
            <button
                type="submit"
                :disabled="form.processing"
                :style="{
                    background: 'var(--accent)',
                    color: '#fff',
                    border: 'none',
                    padding: '7px 12px',
                    borderRadius: '5px',
                    fontSize: '12px',
                    fontWeight: 500,
                    cursor: form.processing ? 'not-allowed' : 'pointer',
                    opacity: form.processing ? 0.6 : 1,
                    fontFamily: 'inherit',
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: '6px',
                }"
            >
                <Icon name="plus" :size="12" />
                {{ t('admin.users.create_user') }}
            </button>
            <Link
                href="/admin/users"
                :style="{ background: 'transparent', color: 'var(--fg-dim)', border: '1px solid var(--border)', padding: '7px 12px', borderRadius: '5px', fontSize: '12px', textDecoration: 'none', fontFamily: 'inherit' }"
            >{{ t('common.cancel') }}</Link>
        </div>
    </form>
    </div>
</template>
