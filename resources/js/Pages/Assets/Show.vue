<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from 'primevue/useconfirm';
import ConfirmDialog from 'primevue/confirmdialog';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CommandDialog from '@/Components/Command/Dialog.vue';
import CmdButton from '@/Components/Command/Button.vue';
import Field from '@/Components/Command/Field.vue';
import Icon from '@/Components/Command/Icon.vue';
import EntityFiles, { type FileRow } from '@/Components/Files/EntityFiles.vue';
import { useCustomer } from '@/composables/useCustomer';

defineOptions({ layout: CommandLayout });

interface Asset {
    id: number;
    name: string;
    category: string | null;
    serial: string | null;
    notes: string | null;
    file_quota_bytes: number | null;
}

const props = defineProps<{
    asset: Asset;
    owner: { type: string; id: number };
    files: { data: FileRow[] };
    breadcrumbs: { id: number; name: string }[];
    current_folder: { id: number; name: string } | null;
    usage: { used_bytes: number; quota_bytes: number | null; percent: number };
    can_manage: boolean;
}>();

const { t } = useI18n();
const { customerUrl } = useCustomer();
const confirm = useConfirm();

function folderUrl(folderId: number | null): string {
    return folderId === null
        ? customerUrl(`/assets/${props.asset.id}`)
        : customerUrl(`/assets/${props.asset.id}/folders/${folderId}`);
}

const editOpen = ref(false);
const form = useForm({
    name: props.asset.name,
    category: props.asset.category ?? '',
    serial: props.asset.serial ?? '',
    notes: props.asset.notes ?? '',
    file_quota_bytes: props.asset.file_quota_bytes,
});

function openEdit() {
    form.name = props.asset.name;
    form.category = props.asset.category ?? '';
    form.serial = props.asset.serial ?? '';
    form.notes = props.asset.notes ?? '';
    form.file_quota_bytes = props.asset.file_quota_bytes;
    form.clearErrors();
    editOpen.value = true;
}

function submitEdit() {
    form.put(customerUrl(`/assets/${props.asset.id}`), {
        preserveScroll: true,
        onSuccess: () => { editOpen.value = false; },
    });
}

function confirmDelete() {
    confirm.require({
        group: 'asset-show',
        message: t('assets.confirm_delete', { name: props.asset.name }),
        header: t('assets.delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('assets.delete'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(customerUrl(`/assets/${props.asset.id}`)),
    });
}
</script>

<template>
    <div>
        <Head :title="asset.name" />
        <ConfirmDialog group="asset-show" />

        <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '12px', marginBottom: '6px' }">
            <Link
                :href="customerUrl('/assets')"
                :style="{ fontSize: '11.5px', color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '5px' }"
            >
                <Icon name="chevR" :size="10" :style="{ transform: 'rotate(180deg)' }" />
                {{ t('assets.back_to_assets') }}
            </Link>
            <div v-if="can_manage" :style="{ display: 'flex', gap: '6px' }">
                <CmdButton variant="ghost" size="sm" @click="openEdit">
                    <template #icon><Icon name="edit" :size="12" /></template>
                    {{ t('common.edit') }}
                </CmdButton>
                <CmdButton variant="danger" size="sm" @click="confirmDelete">
                    <template #icon><Icon name="trash" :size="12" /></template>
                    {{ t('assets.delete') }}
                </CmdButton>
            </div>
        </div>

        <!-- Asset header -->
        <div class="cmd-card" :style="{ padding: '18px 20px', marginBottom: '20px' }">
            <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">
                {{ asset.name }}
            </h1>
            <div :style="{ display: 'flex', flexWrap: 'wrap', gap: '20px', marginTop: '12px' }">
                <div>
                    <div class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em', marginBottom: '3px' }">
                        {{ t('assets.category') }}
                    </div>
                    <div :style="{ fontSize: '13px', color: asset.category ? 'var(--fg)' : 'var(--fg-mute)' }">
                        {{ asset.category || t('assets.no_category') }}
                    </div>
                </div>
                <div>
                    <div class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em', marginBottom: '3px' }">
                        {{ t('assets.serial') }}
                    </div>
                    <div class="cmd-mono" :style="{ fontSize: '13px', color: asset.serial ? 'var(--fg)' : 'var(--fg-mute)' }">
                        {{ asset.serial || '—' }}
                    </div>
                </div>
            </div>
            <div v-if="asset.notes" :style="{ marginTop: '14px' }">
                <div class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em', marginBottom: '4px' }">
                    {{ t('assets.notes') }}
                </div>
                <p :style="{ margin: 0, fontSize: '13px', color: 'var(--fg-dim)', lineHeight: 1.5, whiteSpace: 'pre-wrap' }">{{ asset.notes }}</p>
            </div>
        </div>

        <!-- Documents -->
        <h2 :style="{ margin: '0 0 12px', fontSize: '14px', fontWeight: 600, color: 'var(--fg)', display: 'flex', alignItems: 'center', gap: '7px' }">
            <i class="pi pi-folder" :style="{ fontSize: '13px', color: 'var(--accent)' }" />
            {{ t('assets.documents') }}
        </h2>

        <EntityFiles
            :owner-type="owner.type"
            :owner-id="owner.id"
            :files="files"
            :breadcrumbs="breadcrumbs"
            :current-folder="current_folder"
            :usage="usage"
            :can-manage="can_manage"
            :folder-url="folderUrl"
        />

        <!-- Edit dialog -->
        <CommandDialog
            v-model:visible="editOpen"
            :title="t('assets.edit_asset')"
            width="480px"
        >
            <form
                @submit.prevent="submitEdit"
                :style="{ display: 'flex', flexDirection: 'column', gap: '12px' }"
            >
                <Field
                    v-model="form.name"
                    :label="t('assets.name')"
                    :error="form.errors.name"
                    required
                    autofocus
                />
                <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px' }">
                    <Field
                        v-model="form.category"
                        :label="t('assets.category')"
                        :error="form.errors.category"
                    />
                    <Field
                        v-model="form.serial"
                        :label="t('assets.serial')"
                        :error="form.errors.serial"
                    />
                </div>
                <div>
                    <label
                        class="cmd-mono cmd-uc"
                        :style="{ display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em', fontWeight: 500 }"
                    >{{ t('assets.notes') }}</label>
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        :placeholder="t('assets.notes_placeholder')"
                        :style="{ width: '100%', background: 'var(--panel2)', border: `1px solid ${form.errors.notes ? 'var(--danger)' : 'var(--border)'}`, borderRadius: '5px', padding: '8px 10px', color: 'var(--fg)', fontSize: '13px', outline: 'none', fontFamily: 'inherit', resize: 'vertical' }"
                    />
                    <div v-if="form.errors.notes" :style="{ color: 'var(--danger)', fontSize: '11px', marginTop: '4px' }">
                        {{ form.errors.notes }}
                    </div>
                </div>
                <Field
                    v-model="form.file_quota_bytes"
                    type="number"
                    :label="t('assets.storage_quota')"
                    :error="form.errors.file_quota_bytes"
                    :min="-1"
                    numeric
                />
                <p :style="{ margin: 0, fontSize: '11px', color: 'var(--fg-mute)' }">{{ t('assets.storage_quota_help') }}</p>
            </form>
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="editOpen = false">
                    {{ t('common.cancel') }}
                </CmdButton>
                <CmdButton variant="primary" size="sm" :loading="form.processing" @click="submitEdit">
                    <template #icon><Icon name="disk" :size="12" /></template>
                    {{ t('common.save') }}
                </CmdButton>
            </template>
        </CommandDialog>
    </div>
</template>
