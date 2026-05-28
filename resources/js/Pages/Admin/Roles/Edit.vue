<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Field from '@/Components/Command/Field.vue';
import Icon from '@/Components/Command/Icon.vue';
import { useCommandToasts } from '@/composables/useCommandToasts';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();
const { push } = useCommandToasts();

interface Props {
    role: { id: number; name: string; permissions: string[] } | null;
    permissions: { id: number; name: string }[];
}
const props = defineProps<Props>();

const form = useForm({
    name: props.role?.name ?? '',
    permissions: props.role?.permissions ? [...props.role.permissions] : [] as string[],
});

function togglePermission(name: string) {
    const idx = form.permissions.indexOf(name);
    if (idx >= 0) form.permissions.splice(idx, 1);
    else form.permissions.push(name);
}

function submit() {
    // Server flashes flash.roles.{updated,created} via useFlashToast —
    // no client-side push needed.
    if (props.role) {
        form.put(`/admin/roles/${props.role.id}`, { preserveScroll: true });
    } else {
        form.post('/admin/roles');
    }
}
</script>

<template>
    <div>
    <Head :title="(role ? t('admin.roles.edit_role') : t('admin.roles.new_role')) + ' · Admin'" />

    <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: '18px' }">
        <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">
            {{ role ? t('admin.roles.edit_role') : t('admin.roles.new_role') }}
        </h1>
        <Link
            href="/admin/roles"
            :style="{ fontSize: '11.5px', color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '5px' }"
        >
            <Icon name="chevR" :size="10" :style="{ transform: 'rotate(180deg)' }" />
            {{ t('common.back') }}
        </Link>
    </div>

    <form
        @submit.prevent="submit"
        class="cmd-card"
        :style="{ maxWidth: '680px', padding: '24px', display: 'flex', flexDirection: 'column', gap: '16px' }"
    >
        <Field
            v-model="form.name"
            :label="t('common.name')"
            :error="form.errors.name"
            autofocus
        />

        <div>
            <div
                class="cmd-mono cmd-uc"
                :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '8px', fontWeight: 500, letterSpacing: '0.06em' }"
            >{{ t('admin.roles.permissions') }}</div>
            <div
                :style="{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))',
                    gap: '1px',
                    background: 'var(--border)',
                    border: '1px solid var(--border)',
                    borderRadius: '5px',
                    overflow: 'hidden',
                }"
            >
                <label
                    v-for="p in permissions"
                    :key="p.id"
                    :style="{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '8px',
                        padding: '8px 10px',
                        background: form.permissions.includes(p.name) ? 'var(--accent-soft)' : 'var(--panel)',
                        cursor: 'pointer',
                        fontSize: '12px',
                        color: form.permissions.includes(p.name) ? 'var(--fg)' : 'var(--fg-dim)',
                    }"
                >
                    <input
                        type="checkbox"
                        :checked="form.permissions.includes(p.name)"
                        @change="togglePermission(p.name)"
                        :style="{ accentColor: 'var(--accent)' }"
                    />
                    <span class="cmd-mono" :style="{ fontSize: '11px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">
                        {{ p.name }}
                    </span>
                </label>
            </div>
            <p
                class="cmd-mono"
                :style="{ fontSize: '10.5px', color: 'var(--fg-mute)', marginTop: '6px' }"
            >{{ t('common.n_selected', { n: form.permissions.length }) }}</p>
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
                <Icon name="arrow" :size="12" />
                {{ t('common.save') }}
            </button>
            <Link
                href="/admin/roles"
                :style="{ background: 'transparent', color: 'var(--fg-dim)', border: '1px solid var(--border)', padding: '7px 12px', borderRadius: '5px', fontSize: '12px', textDecoration: 'none', fontFamily: 'inherit' }"
            >{{ t('common.cancel') }}</Link>
        </div>
    </form>
    </div>
</template>
