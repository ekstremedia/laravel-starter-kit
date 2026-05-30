<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CommandDialog from '@/Components/Command/Dialog.vue';
import CmdButton from '@/Components/Command/Button.vue';
import FilesToolbar from '@/Components/Files/FilesToolbar.vue';
import FilesUsageBar from '@/Components/Files/FilesUsageBar.vue';
import FileBrowser from '@/Components/Files/FileBrowser.vue';
import type { PageProps } from '@/types';
import type { FileBrowserItem } from '@/types/files';
import { useCommandToasts } from '@/composables/useCommandToasts';
import { useWorkspace } from '@/composables/useWorkspace';

defineOptions({ layout: CommandLayout });

interface Breadcrumb { id: number; name: string }

const props = defineProps<{
    items: FileBrowserItem[];
    breadcrumbs: Breadcrumb[];
    current_folder: { id: number; name: string; uuid: string } | null;
    usage: { used_bytes: number; quota_bytes: number | null; quota_unlimited: boolean; percent: number };
    can_manage: boolean;
    permissions: { upload: boolean; create_folder: boolean; manage: boolean };
    search: string | null;
    realtime_version: number;
}>();

const { t } = useI18n();
const { workspaceUrl, workspace } = useWorkspace();
const { push } = useCommandToasts();
const page = usePage<PageProps>();

const parentId = computed(() => props.current_folder?.id ?? null);

const viewMode = ref<'grid' | 'list'>(
    (typeof localStorage !== 'undefined' && (localStorage.getItem('files.viewMode') as 'grid' | 'list')) || 'grid',
);
function setViewMode(m: 'grid' | 'list') {
    viewMode.value = m;
    if (typeof localStorage === 'undefined') return;
    localStorage.setItem('files.viewMode', m);
}

const searchQuery = ref(props.search ?? '');
function onSearch() {
    router.get(workspaceUrl(parentId.value ? `/files/company/${parentId.value}` : '/files/company'), {
        q: searchQuery.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true, only: ['items', 'realtime_version'] });
}

const perms = computed<string[]>(() => (page.props.auth?.user?.permissions ?? []) as string[]);
const isSuperAdmin = computed(() => page.props.auth?.user?.is_super_admin === true);
const canRename = computed(() => isSuperAdmin.value || perms.value.includes('rename files'));
const switcherPermissions = computed(() => ({
    canViewShared: isSuperAdmin.value || perms.value.includes('view company files'),
}));

// FileBrowser gates rename/delete/move per-item on `can_manage` (owner/admin)
// and never offers them on `linked` rows; the server re-checks on every call.
const browserPermissions = computed(() => ({
    upload: props.permissions.upload,
    rename: canRename.value,
    move: canRename.value,
    delete: true,
    shareLink: false,
}));

// ── Websocket live updates ───────────────────────────────────────────
let lastVersion = 0;
let channelName: string | null = null;
function handleRealtime(payload: { workspace_id: number; reason: string; version: number; folder_id: number | null }) {
    if (payload.version <= lastVersion) return;
    lastVersion = payload.version;
    if (payload.folder_id !== null && payload.folder_id !== parentId.value) return;
    router.reload({ only: ['items', 'usage', 'realtime_version'] });
}
onMounted(() => {
    lastVersion = props.realtime_version ?? 0;
    const workspaceId = workspace.value?.id;
    const echo = (window as { Echo?: { private: (name: string) => { listen: (e: string, cb: (p: unknown) => void) => void } } }).Echo;
    if (!workspaceId || !echo) return;
    channelName = `workspace.${workspaceId}.files`;
    echo.private(channelName).listen('.CompanyFilesChanged', handleRealtime as unknown as (p: unknown) => void);
});
onUnmounted(() => {
    if (channelName) (window as { Echo?: { leave: (n: string) => void } }).Echo?.leave(channelName);
});

// ── Upload (handled by FileBrowser's UploadDialog → POST /files/company) ──
const uploadOpen = ref(false);

// ── Folder create ────────────────────────────────────────────────────
const newFolderOpen = ref(false);
const newFolderName = ref('');
function createFolder() {
    if (!newFolderName.value.trim()) return;
    router.post(workspaceUrl('/files/company/folder'), {
        name: newFolderName.value.trim(),
        parent_id: parentId.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            push(t('files.folder_created', { name: newFolderName.value }), 'success');
            newFolderOpen.value = false;
            newFolderName.value = '';
        },
        onError: (errors) => {
            const first = Object.values(errors)[0];
            push(typeof first === 'string' ? first : t('common.error'), 'danger');
        },
    });
}

// ── Delete / unshare dialog (admin can also notify the owner) ─────────
const deleteDialogItem = ref<FileBrowserItem | null>(null);
const notifyInApp = ref(true);
const notifyByEmail = ref(false);

function openDelete(item: FileBrowserItem) {
    if (!item.can_manage) return;
    deleteDialogItem.value = item;
    notifyInApp.value = true;
    notifyByEmail.value = false;
}
function closeDelete() {
    deleteDialogItem.value = null;
}
function selfIsOwner(item: FileBrowserItem): boolean {
    const authId = page.props.auth?.user?.id;
    return authId !== undefined && !!item.owner && item.owner.id === authId;
}
function confirmDelete() {
    const item = deleteDialogItem.value;
    if (!item) return;
    const endpoint = item.linked && item.link_id
        ? workspaceUrl(`/files/company/links/${item.link_id}`)
        : workspaceUrl(`/files/company/${item.id}`);
    const showNotify = props.can_manage && !!item.owner && !selfIsOwner(item);
    const payload: Record<string, boolean> = showNotify
        ? { notify_in_app: notifyInApp.value, notify_email: notifyByEmail.value }
        : {};
    router.delete(endpoint, {
        data: payload,
        preserveScroll: true,
        onSuccess: () => closeDelete(),
        onError: (errors) => {
            const first = Object.values(errors)[0];
            push(typeof first === 'string' ? first : t('common.error'), 'danger');
        },
    });
}
</script>

<template>
    <div class="cmd-files-page">
        <Head :title="t('files.company_title')" />

        <FilesToolbar
            scope="shared"
            base-path="/files/company"
            :breadcrumbs="breadcrumbs"
            :root-label="t('files.breadcrumb_root')"
            v-model:search="searchQuery"
            :view-mode="viewMode"
            @update:viewMode="setViewMode"
            @submit-search="onSearch"
            @upload="uploadOpen = true"
            @new-folder="newFolderOpen = true"
            :permissions="{
                upload: permissions.upload,
                createFolder: permissions.create_folder,
                canViewShared: switcherPermissions.canViewShared,
            }"
        />

        <FilesUsageBar
            :used-bytes="usage.used_bytes"
            :quota-bytes="usage.quota_bytes"
            :quota-unlimited="usage.quota_unlimited"
        />

        <FileBrowser
            v-model:upload-open="uploadOpen"
            :items="items"
            :view-mode="viewMode"
            scope="shared"
            base-path="/files/company"
            :current-folder-id="parentId"
            :permissions="browserPermissions"
            @delete="openDelete"
        />

        <!-- New folder dialog -->
        <CommandDialog :visible="newFolderOpen" @update:visible="(v: boolean) => (newFolderOpen = v)" :title="t('files.new_folder')">
            <div :style="{ display: 'flex', flexDirection: 'column', gap: '12px', padding: '12px' }">
                <input
                    v-model="newFolderName"
                    :placeholder="t('files.new_folder')"
                    autofocus
                    @keydown.enter="createFolder"
                    :style="{ background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: '5px', padding: '8px 10px', color: 'var(--fg)', fontSize: '13px', outline: 'none' }"
                />
                <div :style="{ display: 'flex', justifyContent: 'flex-end', gap: '8px' }">
                    <CmdButton @click="newFolderOpen = false" variant="ghost">{{ t('common.cancel') }}</CmdButton>
                    <CmdButton @click="createFolder" variant="primary">{{ t('common.create') }}</CmdButton>
                </div>
            </div>
        </CommandDialog>

        <!-- Delete / unshare dialog -->
        <CommandDialog
            :visible="deleteDialogItem !== null"
            @update:visible="(v: boolean) => !v && closeDelete()"
            :title="deleteDialogItem?.linked ? t('files.company_unlink_title') : t('files.company_delete_title')"
        >
            <div v-if="deleteDialogItem" :style="{ padding: '12px', display: 'flex', flexDirection: 'column', gap: '12px' }">
                <p :style="{ margin: 0, fontSize: '13px', color: 'var(--fg)' }">
                    <template v-if="deleteDialogItem.linked">{{ t('files.company_unlink_body', { file: deleteDialogItem.name }) }}</template>
                    <template v-else>{{ t('files.company_delete_body', { file: deleteDialogItem.name }) }}</template>
                </p>

                <div v-if="can_manage && deleteDialogItem.owner && !selfIsOwner(deleteDialogItem)" :style="{ display: 'flex', flexDirection: 'column', gap: '6px', background: 'var(--panel2)', padding: '10px', borderRadius: '5px', border: '1px solid var(--border)' }">
                    <div :style="{ fontSize: '11px', color: 'var(--fg-mute)', fontWeight: 500 }">
                        {{ t('files.notify_owner', { name: deleteDialogItem.owner.name }) }}
                    </div>
                    <label :style="{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: 'var(--fg)' }">
                        <input type="checkbox" v-model="notifyInApp" />
                        {{ t('files.notify_in_app') }}
                    </label>
                    <label :style="{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: 'var(--fg)' }">
                        <input type="checkbox" v-model="notifyByEmail" />
                        {{ t('files.notify_by_email') }}
                    </label>
                </div>

                <div :style="{ display: 'flex', justifyContent: 'flex-end', gap: '8px' }">
                    <CmdButton @click="closeDelete" variant="ghost">{{ t('common.cancel') }}</CmdButton>
                    <CmdButton @click="confirmDelete" variant="danger">
                        {{ deleteDialogItem.linked ? t('files.company_unlink_confirm') : t('files.delete') }}
                    </CmdButton>
                </div>
            </div>
        </CommandDialog>
    </div>
</template>

<style scoped>
/* Mirror the personal Files wrapper so toggling between Private and Shared
 * doesn't snap the content width. */
.cmd-files-page {
    position: relative;
    max-width: 1200px;
    margin: 0 auto;
}
</style>
