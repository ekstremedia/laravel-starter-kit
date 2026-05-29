<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Icon from '@/Components/Command/Icon.vue';
import FileBrowser from '@/Components/Files/FileBrowser.vue';
import FilesToolbar from '@/Components/Files/FilesToolbar.vue';
import FilesUsageBar from '@/Components/Files/FilesUsageBar.vue';
import ConfirmDialog from 'primevue/confirmdialog';
import { useConfirm } from 'primevue/useconfirm';
import CommandDialog from '@/Components/Command/Dialog.vue';
import Field from '@/Components/Command/Field.vue';
import CmdSelect from '@/Components/Command/Select.vue';
import CmdButton from '@/Components/Command/Button.vue';
import { useToast } from 'primevue/usetoast';
import { useWorkspace } from '@/composables/useWorkspace';
import type { PageProps } from '@/types';
import type { FileBrowserItem } from '@/types/files';

defineOptions({ layout: CommandLayout });

interface Breadcrumb { id: number; name: string }

interface PageData {
    items: { data: FileBrowserItem[] };
    breadcrumbs: Breadcrumb[];
    current_folder: { id: number; name: string; uuid: string } | null;
    usage: { used_bytes: number; quota_bytes: number | null; percent: number };
    trashed_count: number;
    search: string | null;
    max_upload_bytes: number;
}

const props = defineProps<PageData>();
const { t } = useI18n();
const { workspaceUrl } = useWorkspace();
const page = usePage<PageProps>();

const viewMode = ref<'grid' | 'list'>((localStorage.getItem('files.viewMode') as 'grid' | 'list') || 'grid');
function setViewMode(mode: 'grid' | 'list') {
    viewMode.value = mode;
    localStorage.setItem('files.viewMode', mode);
}

const searchQuery = ref(props.search ?? '');
const uploadOpen = ref(false);
// FileBrowser is a generic component, so it has no `InstanceType`; type the ref
// by the slice of its exposed surface we call.
const browserRef = ref<{ clearSelection: () => void } | null>(null);

const currentFolderId = computed(() => props.current_folder?.id ?? null);

const perms = computed<string[]>(() => (page.props.auth?.user?.permissions ?? []) as string[]);
const hasPerm = (name: string) => perms.value.includes(name);
const canUpload = computed(() => hasPerm('upload files'));
const canCreateFolder = computed(() => hasPerm('create folders'));
const canRename = computed(() => hasPerm('rename files'));
const canDelete = computed(() => hasPerm('delete files'));
const canShare = computed(() => hasPerm('share files'));

const browserPermissions = computed(() => ({
    upload: canUpload.value,
    rename: canRename.value,
    move: canRename.value,
    delete: canDelete.value,
    shareLink: canShare.value,
}));

// Surface Share-to-Company only when the tenant enables company files and the
// user holds the permission.
const canShareToCompany = computed<boolean>(() => {
    const workspace = page.props.workspace;
    return !!workspace?.company_files_enabled && hasPerm('share files to company');
});

const switcherPermissions = computed(() => {
    const isSuperAdmin = page.props.auth?.user?.is_super_admin === true;
    return { canViewShared: isSuperAdmin || hasPerm('view company files') };
});

// Live patches (queued preview conversions, cross-tab renames) merged over the
// server items so the thumbnail swap animates in place.
const liveItems = reactive<Record<number, Partial<FileBrowserItem>>>({});
const mergedItems = computed(() => (props.items?.data ?? []).map((i) => ({ ...i, ...(liveItems[i.id] ?? {}) })));

let fileChannelUserId: number | null = null;
function joinFilesChannel(userId: number) {
    leaveFilesChannel();
    const echo = (window as any).Echo;
    if (!echo) return;
    fileChannelUserId = userId;
    echo.private(`App.Models.User.${userId}`).listen('.FileItemUpdated', (payload: Partial<FileBrowserItem> & { id: number }) => {
        liveItems[payload.id] = payload;
    });
}
function leaveFilesChannel() {
    if (fileChannelUserId !== null) {
        (window as any).Echo?.leave(`App.Models.User.${fileChannelUserId}`);
        fileChannelUserId = null;
    }
}
watch(
    () => page.props.auth?.user?.id as number | undefined,
    (id) => { if (typeof id === 'number') joinFilesChannel(id); else leaveFilesChannel(); },
    { immediate: true },
);
onUnmounted(() => leaveFilesChannel());

const confirm = useConfirm();
const toast = useToast();

function onSearch() {
    router.get(
        workspaceUrl(currentFolderId.value ? `/files/${currentFolderId.value}` : '/files'),
        { q: searchQuery.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

// ── Delete (single) ──────────────────────────────────────────────────
function confirmDelete(item: FileBrowserItem) {
    confirm.require({
        group: 'files',
        message: t('files.confirm_delete', { name: item.name }),
        header: t('files.delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('files.delete'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(workspaceUrl(`/files/${item.id}`), { preserveScroll: true }),
    });
}

// ── Share to company ─────────────────────────────────────────────────
function shareToCompany(item: FileBrowserItem) {
    router.post(workspaceUrl(`/files/${item.id}/share-to-company`), {}, {
        preserveScroll: true,
        onError: () => toast.add({ severity: 'error', summary: t('files.share_to_company'), detail: t('files.share_failed'), life: 4000 }),
    });
}
function unshareFromCompany(item: FileBrowserItem) {
    router.delete(workspaceUrl(`/files/${item.id}/share-to-company`), {
        preserveScroll: true,
        onError: () => toast.add({ severity: 'error', summary: t('files.unshare_from_company'), detail: t('files.share_failed'), life: 4000 }),
    });
}

// ── Share link dialog ────────────────────────────────────────────────
const shareDialogFile = ref<FileBrowserItem | null>(null);
const sharePassword = ref('');
const shareHours = ref(24);
const shareCreating = ref(false);
const shareResultUrl = ref<string | null>(null);

function openShareDialog(item: FileBrowserItem) {
    shareDialogFile.value = item;
    sharePassword.value = '';
    shareHours.value = 24;
    shareResultUrl.value = null;
}
function shareErrorToast() {
    toast.add({ severity: 'error', summary: t('files.share'), detail: t('files.share_failed'), life: 4000 });
}
async function createShare() {
    if (!shareDialogFile.value) return;
    shareCreating.value = true;
    try {
        const res = await fetch(workspaceUrl(`/files/${shareDialogFile.value.id}/shares`), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? ''),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ expires_in_hours: shareHours.value, password: sharePassword.value || null }),
        });
        if (!res.ok) { shareErrorToast(); return; }
        const data = await res.json();
        shareResultUrl.value = data.url;
        await navigator.clipboard?.writeText(data.url).catch(() => undefined);
    } catch {
        shareErrorToast();
    } finally {
        shareCreating.value = false;
    }
}
async function quickShare(item: FileBrowserItem) {
    if (item.type !== 'file') return openShareDialog(item);
    try {
        const res = await fetch(workspaceUrl(`/files/${item.id}/shares/signed`), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? ''),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ hours: 24 }),
        });
        if (!res.ok) { shareErrorToast(); return; }
        const data = await res.json();
        await navigator.clipboard?.writeText(data.url).catch(() => undefined);
        shareResultUrl.value = data.url;
        shareDialogFile.value = item;
    } catch {
        shareErrorToast();
    }
}

// ── Bulk actions (selection lives in FileBrowser; it hands us the ids) ──
function bulkDownload(ids: number[]) {
    if (!ids.length) return;
    window.location.href = workspaceUrl(`/files/bulk/zip?ids=${ids.join(',')}`);
}
function confirmBulkDelete(ids: number[]) {
    if (!ids.length) return;
    confirm.require({
        group: 'files',
        message: t('files.bulk.confirm_delete', { count: ids.length }),
        header: t('files.bulk.delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('files.delete'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.post(
            workspaceUrl('/files/bulk/delete'),
            { ids },
            { preserveScroll: true, onSuccess: () => browserRef.value?.clearSelection() },
        ),
    });
}

const moveDialogOpen = ref(false);
const moveIds = ref<number[]>([]);
const moveFolders = ref<{ id: number; name: string; parent_id: number | null }[]>([]);
const moveTargetId = ref<number>(0);

async function openMoveDialog(ids: number[]) {
    if (!ids.length) return;
    moveIds.value = ids;
    moveTargetId.value = 0;
    try {
        const res = await fetch(workspaceUrl('/files/folders'), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const data = await res.json();
        const selected = new Set(ids);
        moveFolders.value = (data.folders ?? []).filter((f: { id: number }) => !selected.has(f.id));
    } catch {
        moveFolders.value = [];
    }
    moveDialogOpen.value = true;
}

const moveFolderOptions = computed(() => {
    const byId = new Map(moveFolders.value.map((f) => [f.id, f]));
    function depth(f: { parent_id: number | null }): number {
        let d = 0;
        let cur = f.parent_id;
        while (cur !== null && byId.has(cur)) { d++; cur = byId.get(cur)!.parent_id; }
        return d;
    }
    const opts: { value: number; label: string }[] = [{ value: 0, label: t('files.root') }];
    for (const f of moveFolders.value) opts.push({ value: f.id, label: `${'  '.repeat(depth(f))}${f.name}` });
    return opts;
});

function submitMove() {
    router.post(
        workspaceUrl('/files/bulk/move'),
        { ids: moveIds.value, parent_id: moveTargetId.value || null },
        {
            preserveScroll: true,
            onSuccess: () => { moveDialogOpen.value = false; browserRef.value?.clearSelection(); },
        },
    );
}

// ── New folder ───────────────────────────────────────────────────────
const newFolderOpen = ref(false);
const newFolderName = ref('');
function createFolder() {
    newFolderName.value = '';
    newFolderOpen.value = true;
}
function submitNewFolder() {
    const name = newFolderName.value.trim();
    if (!name) return;
    router.post(
        workspaceUrl('/files/folder'),
        { name, parent_id: currentFolderId.value },
        { preserveScroll: true, onSuccess: () => { newFolderOpen.value = false; } },
    );
}

const maxUploadMb = computed(() => props.max_upload_bytes / (1024 * 1024));

// Close the page-owned dialogs on Escape (FileBrowser handles its own overlays).
function onKey(e: KeyboardEvent) {
    if (e.key !== 'Escape') return;
    uploadOpen.value = false;
    shareDialogFile.value = null;
}
onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <div>
        <Head :title="t('files.title')" />
        <ConfirmDialog group="files" />

        <div class="cmd-files-page">
            <FilesToolbar
                scope="private"
                base-path="/files"
                :breadcrumbs="breadcrumbs ?? []"
                :root-label="t('files.root')"
                v-model:search="searchQuery"
                :view-mode="viewMode"
                @update:viewMode="setViewMode"
                @submit-search="onSearch"
                @upload="uploadOpen = true"
                @new-folder="createFolder"
                :permissions="{
                    upload: canUpload,
                    createFolder: canCreateFolder,
                    canViewShared: switcherPermissions.canViewShared,
                }"
            >
                <template #afterActions>
                    <Link :href="workspaceUrl('/files/trash')" class="cmd-ghost-btn" :style="{ position: 'relative' }">
                        <i class="pi pi-trash" :style="{ fontSize: '11px' }" />
                        <span>{{ t('files.trash') }}</span>
                        <span
                            v-if="(props.trashed_count ?? 0) > 0"
                            :style="{ marginLeft: '4px', minWidth: '18px', display: 'inline-flex', justifyContent: 'center', background: 'rgba(255, 138, 138, 0.15)', color: 'var(--danger)', borderRadius: '9px', padding: '1px 6px', fontSize: '10px', fontWeight: 600 }"
                        >{{ props.trashed_count }}</span>
                    </Link>
                </template>
            </FilesToolbar>

            <FilesUsageBar :used-bytes="props.usage.used_bytes" :quota-bytes="props.usage.quota_bytes" />

            <FileBrowser
                ref="browserRef"
                v-model:upload-open="uploadOpen"
                :items="mergedItems"
                :view-mode="viewMode"
                scope="private"
                base-path="/files"
                :current-folder-id="currentFolderId"
                :permissions="browserPermissions"
                :can-share-to-company="canShareToCompany"
                :selectable="true"
                :can-bulk-delete="canDelete"
                :can-bulk-move="canRename"
                :max-upload-mb="maxUploadMb"
                @delete="confirmDelete"
                @share-link="openShareDialog"
                @share-to-company="shareToCompany"
                @unshare-from-company="unshareFromCompany"
                @bulk-download="bulkDownload"
                @bulk-move="openMoveDialog"
                @bulk-delete="confirmBulkDelete"
            />
        </div>

        <!-- Bulk move dialog -->
        <CommandDialog v-model:visible="moveDialogOpen" :title="t('files.bulk.move_title', { count: moveIds.length })" width="420px">
            <CmdSelect v-model="moveTargetId" :label="t('files.bulk.move_to')" :options="moveFolderOptions" />
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="moveDialogOpen = false">{{ t('common.cancel') }}</CmdButton>
                <CmdButton variant="primary" size="sm" @click="submitMove">
                    <template #icon><i class="pi pi-folder-open" :style="{ fontSize: '11px' }" /></template>
                    {{ t('files.bulk.move') }}
                </CmdButton>
            </template>
        </CommandDialog>

        <!-- New folder dialog -->
        <CommandDialog v-model:visible="newFolderOpen" :title="t('files.new_folder')" width="380px">
            <form @submit.prevent="submitNewFolder">
                <Field v-model="newFolderName" :label="t('common.name')" :placeholder="t('files.new_folder')" autofocus @keyup.enter="submitNewFolder" />
            </form>
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="newFolderOpen = false">{{ t('common.cancel') }}</CmdButton>
                <CmdButton variant="primary" size="sm" @click="submitNewFolder">
                    <template #icon><Icon name="disk" :size="12" /></template>
                    {{ t('files.new_folder') }}
                </CmdButton>
            </template>
        </CommandDialog>

        <!-- Share link dialog -->
        <CommandDialog
            :visible="!!shareDialogFile"
            width="420px"
            @update:visible="(v: boolean) => { if (!v) shareDialogFile = null; }"
        >
            <template #header>
                <h2 :style="{ display: 'flex', alignItems: 'center', gap: '8px', fontSize: '13px', fontWeight: 600, color: 'var(--fg)', margin: 0 }">
                    <i class="pi pi-share-alt" :style="{ color: 'var(--accent)', fontSize: '13px' }" />
                    <span>{{ shareDialogFile ? t('files.share_title', { name: shareDialogFile.name }) : '' }}</span>
                </h2>
            </template>

            <div v-if="!shareResultUrl" :style="{ display: 'flex', flexDirection: 'column', gap: '12px' }">
                <CmdSelect
                    v-model="shareHours"
                    :label="t('files.share_expiry')"
                    :options="[
                        { value: 1, label: t('files.share_expiry_hours', { count: 1 }) },
                        { value: 24, label: t('files.share_expiry_hours', { count: 24 }) },
                        { value: 72, label: t('files.share_expiry_days', { count: 3 }) },
                        { value: 168, label: t('files.share_expiry_days', { count: 7 }) },
                    ]"
                />
                <Field
                    v-model="sharePassword"
                    type="password"
                    autocomplete="new-password"
                    :label="t('files.share_password_optional')"
                    :placeholder="t('files.share_password_placeholder')"
                />
            </div>

            <div v-else :style="{ display: 'flex', flexDirection: 'column', gap: '8px', fontSize: '12px' }">
                <p :style="{ color: 'var(--success)', display: 'flex', alignItems: 'center', gap: '5px', margin: 0 }">
                    <i class="pi pi-check-circle" />
                    <span>{{ t('files.share_created_copied') }}</span>
                </p>
                <div class="cmd-mono" :style="{ background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: '4px', padding: '6px 10px', fontSize: '11px', color: 'var(--fg-dim)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">{{ shareResultUrl }}</div>
            </div>

            <template #footer>
                <CmdButton v-if="shareDialogFile?.type === 'file' && !shareResultUrl" variant="ghost" size="sm" @click="quickShare(shareDialogFile)">{{ t('files.quick_link') }}</CmdButton>
                <CmdButton variant="ghost" size="sm" @click="shareDialogFile = null">{{ t('common.close') }}</CmdButton>
                <CmdButton v-if="!shareResultUrl" variant="primary" size="sm" :loading="shareCreating" @click="createShare">
                    <template #icon><i class="pi pi-link" :style="{ fontSize: '11px' }" /></template>
                    {{ t('files.create_share') }}
                </CmdButton>
            </template>
        </CommandDialog>
    </div>
</template>

<style scoped>
.cmd-files-page {
    position: relative;
    max-width: 1200px;
    margin: 0 auto;
}
.cmd-ghost-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--panel2);
    color: var(--fg);
    border: 1px solid var(--border);
    border-radius: 5px;
    padding: 5px 10px;
    font-size: 12px;
    font-family: inherit;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.12s, border-color 0.12s;
}
.cmd-ghost-btn:hover {
    background: var(--panel);
    border-color: var(--accent-border);
}
</style>
