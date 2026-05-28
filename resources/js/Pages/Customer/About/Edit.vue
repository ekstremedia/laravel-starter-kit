<script setup lang="ts">
/*
 * Customer profile editor — accessible to customer Admins (via the
 * TenantProfilePolicy). Mirrors the editable fields of the user profile
 * (headline / bio-equivalent / location / website) plus the company name.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/CommandLayout.vue';
import Field from '@/Components/Command/Field.vue';
import { useCustomer } from '@/composables/useCustomer';

interface CustomerProfilePayload {
    id: number;
    slug: string;
    name: string;
    headline: string | null;
    about: string | null;
    location: string | null;
    website: string | null;
}

interface Props {
    profile: CustomerProfilePayload;
}

const props = defineProps<Props>();
const { t } = useI18n();
const { customerUrl } = useCustomer();

const form = useForm({
    name: props.profile.name,
    headline: props.profile.headline ?? '',
    about: props.profile.about ?? '',
    location: props.profile.location ?? '',
    website: props.profile.website ?? '',
});

function save() {
    // No client toast on success: the controller redirects with
    // flash('success', __('flash.profile.updated')) and the global
    // useFlashToast() in CommandLayout surfaces it. Adding one here would
    // double-toast and use a different label than the backend message.
    form.put(customerUrl('/about'), { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('customer.about.edit_title')" />

        <div :style="{ maxWidth: '720px', margin: '0 auto', padding: '24px 20px' }">
            <div :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '14px' }">
                <h1 :style="{ margin: 0, fontSize: '18px', fontWeight: 600, color: 'var(--fg)' }">{{ t('customer.about.edit_title') }}</h1>
                <Link
                    :href="customerUrl('/about')"
                    :style="{ fontSize: '12px', color: 'var(--fg-mute)', textDecoration: 'none' }"
                >{{ t('common.cancel') }}</Link>
            </div>

            <form
                @submit.prevent="save"
                class="cmd-card"
                :style="{ padding: '20px', display: 'flex', flexDirection: 'column', gap: '14px' }"
            >
                <Field
                    v-model="form.name"
                    :label="t('customer.about.name_label')"
                    :error="form.errors.name"
                />
                <Field
                    v-model="form.headline"
                    :label="t('customer.about.headline_label')"
                    :placeholder="t('customer.about.headline_placeholder')"
                    :error="form.errors.headline"
                />

                <div>
                    <label :style="{ display: 'block', fontSize: '11px', color: 'var(--fg-dim)', marginBottom: '4px', fontWeight: 500 }">
                        {{ t('customer.about.about_label') }}
                    </label>
                    <textarea
                        v-model="form.about"
                        rows="6"
                        :maxlength="2000"
                        :placeholder="t('customer.about.about_placeholder')"
                        :style="{
                            width: '100%',
                            background: 'var(--panel2)',
                            border: '1px solid var(--border)',
                            borderRadius: '5px',
                            padding: '8px 10px',
                            fontSize: '12.5px',
                            fontFamily: 'inherit',
                            color: 'var(--fg)',
                            resize: 'vertical',
                            minHeight: '120px',
                        }"
                    />
                    <div v-if="form.errors.about" :style="{ color: 'var(--danger)', fontSize: '11px', marginTop: '3px' }">{{ form.errors.about }}</div>
                </div>

                <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px' }">
                    <Field
                        v-model="form.location"
                        :label="t('customer.about.location_label')"
                        :error="form.errors.location"
                    />
                    <Field
                        v-model="form.website"
                        type="url"
                        :label="t('customer.about.website_label')"
                        placeholder="https://example.com"
                        :error="form.errors.website"
                    />
                </div>

                <div :style="{ display: 'flex', justifyContent: 'flex-end', gap: '8px' }">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        :style="{
                            background: 'var(--accent)',
                            color: '#fff',
                            border: 'none',
                            padding: '7px 14px',
                            borderRadius: '5px',
                            fontSize: '12px',
                            fontWeight: 500,
                            cursor: form.processing ? 'not-allowed' : 'pointer',
                            opacity: form.processing ? 0.6 : 1,
                            fontFamily: 'inherit',
                        }"
                    >{{ t('profile.save') }}</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
