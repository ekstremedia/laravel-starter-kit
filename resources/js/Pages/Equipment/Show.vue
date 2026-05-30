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
import { relativeTime, absoluteTime } from '@/utils/time';

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

// ── Tabs: Details (default) + Files + Activity. Initialised from ?tab, and
// forced to Files when we're inside a folder so document navigation keeps its tab.
const tabs = computed<{ key: string; label: string; icon?: IconName }[]>(() => [
    { key: 'details', label: t('equipment.tab_details'), icon: 'box' },
    { key: 'files', label: t('equipment.tab_files'), icon: 'disk' },
    { key: 'activity', label: t('equipment.tab_log'), icon: 'log' },
]);
function initialTab(): string {
    if (typeof window !== 'undefined') {
        const tab = new URLSearchParams(window.location.search).get('tab');
        if (tab && ['details', 'files', 'activity'].includes(tab)) return tab;
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

// Human timeline helpers: initials + a deterministic avatar colour from the
// name, and relative time with the full timestamp on hover.
function initials(name: string | null): string {
    if (!name) return '';
    const parts = name.trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase();
}
function avatarColor(name: string | null): string {
    let h = 0;
    for (const ch of name ?? 'system') h = (h * 31 + ch.charCodeAt(0)) % 360;
    return `hsl(${h}, 42%, 46%)`;
}
function relTime(iso: string | null): string {
    return relativeTime(iso, locale.value);
}
function absTime(iso: string | null): string {
    return absoluteTime(iso, locale.value);
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
        </div>

        <!-- Activity tab -->
        <div v-show="activeTab === 'activity'">
            <div v-if="!activities.length" class="cmd-card" :style="{ padding: '44px 16px', textAlign: 'center' }">
                <i class="pi pi-history" :style="{ fontSize: '24px', color: 'var(--fg-mute)' }" />
                <p :style="{ margin: '12px 0 0', fontSize: '13px', color: 'var(--fg-mute)' }">{{ t('equipment.activity_empty') }}</p>
            </div>
            <div v-else class="cmd-card" :style="{ padding: '20px 22px' }">
                <div v-for="(a, idx) in activities" :key="a.id" :style="{ display: 'flex', gap: '13px' }">
                    <div :style="{ display: 'flex', flexDirection: 'column', alignItems: 'center', flexShrink: 0 }">
                        <span
                            :style="{ width: '30px', height: '30px', borderRadius: '50%', background: a.causer ? avatarColor(a.causer.name) : 'var(--panel2)', color: 'var(--accent-fg)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontSize: '11px', fontWeight: 600 }"
                        >
                            <template v-if="a.causer">{{ initials(a.causer.name) }}</template>
                            <Icon v-else name="cog" :size="13" :style="{ color: 'var(--fg-mute)' }" />
                        </span>
                        <span v-if="idx < activities.length - 1" :style="{ flex: 1, width: '2px', background: 'var(--border)', minHeight: '12px', marginTop: '4px' }" />
                    </div>
                    <div :style="{ flex: 1, minWidth: 0, paddingBottom: idx < activities.length - 1 ? '16px' : '0' }">
                        <div :style="{ fontSize: '13px', color: 'var(--fg)', lineHeight: 1.45 }">
                            <strong v-if="a.causer">{{ a.causer.name }}</strong>
                            <strong v-else>{{ t('equipment.activity_system') }}</strong>
                            {{ ' ' }}{{ activityLabel(a) }}
                        </div>
                        <div :style="{ fontSize: '11.5px', color: 'var(--fg-mute)', marginTop: '3px' }" :title="absTime(a.created_at)">
                            {{ relTime(a.created_at) }}
                        </div>
                    </div>
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
