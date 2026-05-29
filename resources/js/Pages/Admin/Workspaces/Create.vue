<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Field from '@/Components/Command/Field.vue';
import Icon from '@/Components/Command/Icon.vue';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();

const form = useForm({ name: '', slug: '' });

function submit() {
    form.post('/admin/workspaces');
}

function slugify(value: string): string {
    return value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 63);
}

const effectiveSlug = computed(() => form.slug || slugify(form.name));

function onNameBlur() {
    if (!form.slug && form.name) form.slug = slugify(form.name);
}
</script>

<template>
    <div>
    <Head :title="t('admin.workspaces.new_workspace') + ' · Admin'" />

    <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: '18px' }">
        <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">
            {{ t('admin.workspaces.new_workspace') }}
        </h1>
        <Link
            href="/admin/workspaces"
            :style="{ fontSize: '11.5px', color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '5px' }"
        >
            <Icon name="chevR" :size="10" :style="{ transform: 'rotate(180deg)' }" />
            {{ t('common.back') }}
        </Link>
    </div>

    <form
        @submit.prevent="submit"
        class="cmd-card"
        :style="{ maxWidth: '560px', padding: '24px', display: 'flex', flexDirection: 'column', gap: '14px' }"
    >
        <div @focusout="onNameBlur">
            <Field
                v-model="form.name"
                :label="t('common.name')"
                :error="form.errors.name"
                placeholder="Acme Corp"
                autofocus
            />
        </div>
        <div>
            <Field
                v-model="form.slug"
                :label="t('admin.workspaces.slug')"
                :error="form.errors.slug"
                placeholder="acme"
            />
            <p
                class="cmd-mono"
                :style="{ fontSize: '10.5px', color: 'var(--fg-mute)', marginTop: '5px', display: 'flex', alignItems: 'center', gap: '6px' }"
            >
                <span>→</span>
                <code :style="{ background: 'var(--panel2)', border: '1px solid var(--border)', padding: '1px 6px', borderRadius: '3px', color: 'var(--fg-dim)' }">/w/{{ effectiveSlug || 'slug' }}</code>
            </p>
            <p :style="{ fontSize: '11px', color: 'var(--fg-mute)', marginTop: '5px' }">{{ t('admin.workspaces.slug_help') }}</p>
        </div>

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
                {{ t('admin.workspaces.create') }}
            </button>
            <Link
                href="/admin/workspaces"
                :style="{ background: 'transparent', color: 'var(--fg-dim)', border: '1px solid var(--border)', padding: '7px 12px', borderRadius: '5px', fontSize: '12px', textDecoration: 'none', fontFamily: 'inherit' }"
            >{{ t('common.cancel') }}</Link>
        </div>
    </form>
    </div>
</template>
