<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from 'primevue/useconfirm';
import ConfirmDialog from 'primevue/confirmdialog';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CommandDialog from '@/Components/Command/Dialog.vue';
import CmdButton from '@/Components/Command/Button.vue';
import Field from '@/Components/Command/Field.vue';
import Icon, { type IconName } from '@/Components/Command/Icon.vue';
import Tabs from '@/Components/Command/Tabs.vue';
import EntityFiles, { type FileRow } from '@/Components/Files/EntityFiles.vue';
import { useWorkspace } from '@/composables/useWorkspace';

defineOptions({ layout: CommandLayout });

interface Equipment {
    id: number;
    name: string;
    category: string | null;
    serial: string | null;
    notes: string | null;
    cover_file_item_id: number | null;
}

interface ActivityRow {
    id: number;
    event: string | null;
    description: string | null;
    created_at: string | null;
    causer: { id: number; name: string | null } | null;
}

const props = defineProps<{
    equipment: Equipment;
    owner: { type: string; id: number };
    files: { data: FileRow[] };
    breadcrumbs: { id: number; name: string }[];
    current_folder: { id: number; name: string } | null;
    activities: ActivityRow[];
    can_manage: boolean;
}>();

const { t, locale } = useI18n();
const { workspaceUrl } = useWorkspace();
const confirm = useConfirm();

// ── Tabs: Details (default) + Files. Initialised from ?tab, and forced to
// Files when we're inside a folder so document navigation keeps its tab.
const tabs = computed<{ key: string; label: string; icon?: IconName }[]>(() => [
    { key: 'details', label: t('equipment.tab_details'), icon: 'box' },
    { key: 'files', label: t('equipment.tab_files'), icon: 'disk' },
]);
function initialTab(): string {
    if (typeof window !== 'undefined') {
        const tab = new URLSearchParams(window.location.search).get('tab');
        if (tab === 'files' || tab === 'details') return tab;
    }
    return props.current_folder ? 'files' : 'details';
}
const activeTab = ref<string>(initialTab());
watch(activeTab, (v) => {
    if (typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    url.searchParams.set('tab', v);
    window.history.replaceState({}, '', url.toString());
});

function folderUrl(folderId: number | null): string {
    const base = folderId === null
        ? `/equipment/${props.equipment.id}`
        : `/equipment/${props.equipment.id}/folders/${folderId}`;
    // Keep the Files tab active across folder navigation (a full Inertia visit).
    return `${workspaceUrl(base)}?tab=files`;
}

function setCover(fileId: number) {
    router.patch(
        workspaceUrl(`/equipment/${props.equipment.id}/cover`),
        { file_item_id: fileId },
        { preserveScroll: true },
    );
}

const editOpen = ref(false);
const form = useForm({
    name: props.equipment.name,
    category: props.equipment.category ?? '',
    serial: props.equipment.serial ?? '',
    notes: props.equipment.notes ?? '',
});

function openEdit() {
    form.name = props.equipment.name;
    form.category = props.equipment.category ?? '';
    form.serial = props.equipment.serial ?? '';
    form.notes = props.equipment.notes ?? '';
    form.clearErrors();
    editOpen.value = true;
}

function submitEdit() {
    form.put(workspaceUrl(`/equipment/${props.equipment.id}`), {
        preserveScroll: true,
        onSuccess: () => { editOpen.value = false; },
    });
}

function confirmDelete() {
    confirm.require({
        group: 'equipment-show',
        message: t('equipment.confirm_delete', { name: props.equipment.name }),
        header: t('equipment.delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('equipment.delete'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(workspaceUrl(`/equipment/${props.equipment.id}`)),
    });
}

function activityLabel(a: ActivityRow): string {
    if (a.event === 'created') return t('equipment.activity_created');
    if (a.event === 'deleted') return t('equipment.activity_deleted');
    return t('equipment.activity_updated');
}

function activityTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString(locale.value) : '';
}
</script>

<template>
    <div>
        <Head :title="equipment.name" />
        <ConfirmDialog group="equipment-show" />

        <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '12px', marginBottom: '14px' }">
            <Link
                :href="workspaceUrl('/equipment')"
                :style="{ fontSize: '11.5px', color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '5px' }"
            >
                <Icon name="chevR" :size="10" :style="{ transform: 'rotate(180deg)' }" />
                {{ t('equipment.back_to_assets') }}
            </Link>
            <div v-if="can_manage" :style="{ display: 'flex', gap: '6px' }">
                <CmdButton variant="ghost" size="sm" @click="openEdit">
                    <template #icon><Icon name="edit" :size="12" /></template>
                    {{ t('common.edit') }}
                </CmdButton>
                <CmdButton variant="danger" size="sm" @click="confirmDelete">
                    <template #icon><Icon name="trash" :size="12" /></template>
                    {{ t('equipment.delete') }}
                </CmdButton>
            </div>
        </div>

        <h1 :style="{ margin: '0 0 16px', fontSize: '22px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">
            {{ equipment.name }}
        </h1>

        <Tabs v-model="activeTab" :tabs="tabs" />

        <!-- Details tab -->
        <div v-show="activeTab === 'details'">
            <div class="cmd-card" :style="{ padding: '18px 20px', marginBottom: '20px' }">
                <div :style="{ display: 'flex', flexWrap: 'wrap', gap: '20px' }">
                    <div>
                        <div class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em', marginBottom: '3px' }">
                            {{ t('equipment.category') }}
                        </div>
                        <div :style="{ fontSize: '13px', color: equipment.category ? 'var(--fg)' : 'var(--fg-mute)' }">
                            {{ equipment.category || t('equipment.no_category') }}
                        </div>
                    </div>
                    <div>
                        <div class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em', marginBottom: '3px' }">
                            {{ t('equipment.serial') }}
                        </div>
                        <div class="cmd-mono" :style="{ fontSize: '13px', color: equipment.serial ? 'var(--fg)' : 'var(--fg-mute)' }">
                            {{ equipment.serial || '—' }}
                        </div>
                    </div>
                </div>
                <div v-if="equipment.notes" :style="{ marginTop: '14px' }">
                    <div class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em', marginBottom: '4px' }">
                        {{ t('equipment.notes') }}
                    </div>
                    <p :style="{ margin: 0, fontSize: '13px', color: 'var(--fg-dim)', lineHeight: 1.5, whiteSpace: 'pre-wrap' }">{{ equipment.notes }}</p>
                </div>
            </div>

            <!-- Activity timeline -->
            <h2 :style="{ margin: '24px 0 12px', fontSize: '14px', fontWeight: 600, color: 'var(--fg)', display: 'flex', alignItems: 'center', gap: '7px' }">
                <i class="pi pi-history" :style="{ fontSize: '13px', color: 'var(--accent)' }" />
                {{ t('equipment.activity') }}
            </h2>
            <div class="cmd-card" :style="{ padding: '6px 4px' }">
                <p v-if="!activities.length" :style="{ margin: 0, padding: '16px', fontSize: '12px', color: 'var(--fg-mute)', textAlign: 'center' }">
                    {{ t('equipment.activity_empty') }}
                </p>
                <div
                    v-for="a in activities"
                    :key="a.id"
                    :style="{ display: 'flex', alignItems: 'center', gap: '10px', padding: '8px 14px', borderBottom: '1px solid var(--border)' }"
                >
                    <span :style="{ flexShrink: 0, width: '24px', height: '24px', borderRadius: '50%', background: 'var(--panel2)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', color: 'var(--fg-mute)' }">
                        <Icon :name="a.event === 'created' ? 'plus' : a.event === 'deleted' ? 'trash' : 'edit'" :size="11" />
                    </span>
                    <div :style="{ flex: 1, minWidth: 0 }">
                        <span :style="{ fontSize: '12.5px', color: 'var(--fg)' }">
                            <strong v-if="a.causer">{{ a.causer.name }}</strong>
                            {{ ' ' }}{{ activityLabel(a) }}
                        </span>
                    </div>
                    <span class="cmd-mono" :style="{ fontSize: '10.5px', color: 'var(--fg-mute)', flexShrink: 0 }">{{ activityTime(a.created_at) }}</span>
                </div>
            </div>
        </div>

        <!-- Files tab -->
        <div v-show="activeTab === 'files'">
            <EntityFiles
                :owner-type="owner.type"
                :owner-id="owner.id"
                :files="files"
                :breadcrumbs="breadcrumbs"
                :current-folder="current_folder"
                :can-manage="can_manage"
                :allow-set-cover="can_manage"
                :cover-file-item-id="equipment.cover_file_item_id"
                :folder-url="folderUrl"
                @set-cover="setCover"
            />
        </div>

        <!-- Edit dialog -->
        <CommandDialog v-model:visible="editOpen" :title="t('equipment.edit_asset')" width="480px">
            <form @submit.prevent="submitEdit" :style="{ display: 'flex', flexDirection: 'column', gap: '12px' }">
                <Field v-model="form.name" :label="t('equipment.name')" :error="form.errors.name" required autofocus />
                <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px' }">
                    <Field v-model="form.category" :label="t('equipment.category')" :error="form.errors.category" />
                    <Field v-model="form.serial" :label="t('equipment.serial')" :error="form.errors.serial" />
                </div>
                <div>
                    <label
                        class="cmd-mono cmd-uc"
                        :style="{ display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em', fontWeight: 500 }"
                    >{{ t('equipment.notes') }}</label>
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        :placeholder="t('equipment.notes_placeholder')"
                        :style="{ width: '100%', background: 'var(--panel2)', border: `1px solid ${form.errors.notes ? 'var(--danger)' : 'var(--border)'}`, borderRadius: '5px', padding: '8px 10px', color: 'var(--fg)', fontSize: '13px', outline: 'none', fontFamily: 'inherit', resize: 'vertical' }"
                    />
                    <div v-if="form.errors.notes" :style="{ color: 'var(--danger)', fontSize: '11px', marginTop: '4px' }">{{ form.errors.notes }}</div>
                </div>
                <button type="submit" :style="{ display: 'none' }" aria-hidden="true" />
            </form>
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="editOpen = false">{{ t('common.cancel') }}</CmdButton>
                <CmdButton variant="primary" size="sm" :loading="form.processing" @click="submitEdit">
                    <template #icon><Icon name="disk" :size="12" /></template>
                    {{ t('common.save') }}
                </CmdButton>
            </template>
        </CommandDialog>
    </div>
</template>
