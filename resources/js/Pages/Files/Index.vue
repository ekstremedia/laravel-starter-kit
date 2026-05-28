<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Icon from '@/Components/Command/Icon.vue';
import UploadDialog from '@/Components/Files/UploadDialog.vue';
import ImageLightbox from '@/Components/Files/ImageLightbox.vue';
import ItemActionsMenu from '@/Components/Files/ItemActionsMenu.vue';
import FileDetailsDialog from '@/Components/Files/FileDetailsDialog.vue';
import TextPreviewDialog from '@/Components/Files/TextPreviewDialog.vue';
import BulkActionBar from '@/Components/Files/BulkActionBar.vue';
import FilesToolbar from '@/Components/Files/FilesToolbar.vue';
import FilesUsageBar from '@/Components/Files/FilesUsageBar.vue';
import { humanBytes as formatBytes } from '@/utils/bytes';
import ConfirmDialog from 'primevue/confirmdialog';
import { useConfirm } from 'primevue/useconfirm';
import CommandDialog from '@/Components/Command/Dialog.vue';
import Field from '@/Components/Command/Field.vue';
import CmdSelect from '@/Components/Command/Select.vue';
import CmdButton from '@/Components/Command/Button.vue';
import { useToast } from 'primevue/usetoast';
import { useCustomer } from '@/composables/useCustomer';
import { useFileMedia } from '@/composables/useFileMedia';
import type { PageProps } from '@/types';

defineOptions({ layout: CommandLayout });

interface FileItem {
    id: number;
    uuid: string;
    type: 'folder' | 'file';
    name: string;
    mime_type: string | null;
    size: number;
    parent_id: number | null;
    is_image: boolean;
    is_video?: boolean;
    is_audio?: boolean;
    is_text?: boolean;
    is_markdown?: boolean;
    image_processing?: boolean;
    video_processing?: boolean;
    video_ready?: boolean;
    preview_processing?: boolean;
    has_doc_preview?: boolean;
    // Marks a file as currently linked into the active customer's Company
    // Files. Purely informational on personal items — the file itself is
    // unchanged either way.
    shared_to_company?: boolean;
    company_link_id?: number | null;
    thumbnail_url: string | null;
    preview_url: string | null;
    original_url: string | null;
    video_web_url?: string | null;
    video_poster_url?: string | null;
    available_sizes: Record<string, { url: string; width: number; height: number }> | null;
    created_at: string | null;
    updated_at: string | null;
}

interface Breadcrumb {
    id: number;
    name: string;
}

interface PageData {
    items: { data: FileItem[] };
    breadcrumbs: Breadcrumb[];
    current_folder: { id: number; name: string; uuid: string } | null;
    usage: { used_bytes: number; quota_bytes: number | null; percent: number };
    trashed_count: number;
    search: string | null;
    max_upload_bytes: number;
}

const props = defineProps<PageData>();
const { t } = useI18n();
const { customerUrl } = useCustomer();
const page = usePage<PageProps>();

const viewMode = ref<'grid' | 'list'>((localStorage.getItem('files.viewMode') as 'grid' | 'list') || 'grid');
const uploadOpen = ref(false);
const uploadDialogRef = ref<InstanceType<typeof UploadDialog> | null>(null);
const externalDragOver = ref(false);
let externalDragCounter = 0;
// Doc-preview modal is personal-Files-specific; the lightbox/details/text
// state comes from the shared useFileMedia composable (see below).
const docPreviewItem = ref<FileItem | null>(null);
const searchQuery = ref(props.search ?? '');
const renamingId = ref<number | null>(null);
const renameValue = ref('');
const renameInputRefs = ref<Record<number, HTMLInputElement | null>>({});

function registerRenameInput(id: number, el: unknown): void {
    // `el` arrives as HTMLElement | ComponentPublicInstance | null.
    renameInputRefs.value[id] = el instanceof HTMLInputElement ? el : null;
}
const draggingId = ref<number | null>(null);
const dragOverId = ref<number | null>(null);

const newFolderOpen = ref(false);
const newFolderName = ref('');
const confirm = useConfirm();
const toast = useToast();

const shareDialogFile = ref<FileItem | null>(null);
const sharePassword = ref('');
const shareHours = ref(24);
const shareCreating = ref(false);
const shareResultUrl = ref<string | null>(null);

// Surface the Share-to-Company action only when (a) the tenant has the
// feature on and (b) the user has the permission. Permissions are a flat
// list of names on auth.user.permissions (shared by HandleInertiaRequests).
const canShareToCompany = computed<boolean>(() => {
    const customer = page.props.customer;
    const perms = (page.props.auth?.user as { permissions?: string[] } | undefined)?.permissions ?? [];
    return !!customer?.company_files_enabled && perms.includes('share files to company');
});

// Scope-switcher pill — shown above the breadcrumbs on every personal
// Files page. The Shared tab is hidden client-side when the viewer
// lacks the permission (we still double-check server-side on every
// company-files endpoint).
const switcherPermissions = computed(() => {
    const perms = (page.props.auth?.user as { permissions?: string[] } | undefined)?.permissions ?? [];
    const isSuperAdmin = (page.props.auth?.user as { is_super_admin?: boolean } | undefined)?.is_super_admin === true;
    return { canViewShared: isSuperAdmin || perms.includes('view company files') };
});

function shareToCompany(item: FileItem) {
    // Folders are valid here — the backend dispatches ShareFolderToCompany
    // on the queue and flashes files.shared_to_company_queued. Success
    // toast comes from the server flash via useFlashToast.
    router.post(customerUrl(`/files/${item.id}/share-to-company`), {}, {
        preserveScroll: true,
        onError: () => toast.add({
            severity: 'error', summary: t('files.share_to_company'), detail: t('files.share_failed'), life: 4000,
        }),
    });
}

function unshareFromCompany(item: FileItem) {
    router.delete(customerUrl(`/files/${item.id}/share-to-company`), {
        preserveScroll: true,
        // Server flashes files.unshared_from_company; only handle errors
        // client-side so a 403/5xx doesn't silently "succeed".
        onError: () => toast.add({
            severity: 'error', summary: t('files.unshare_from_company'), detail: t('files.share_failed'), life: 4000,
        }),
    });
}

function openShareDialog(item: FileItem) {
    shareDialogFile.value = item;
    sharePassword.value = '';
    shareHours.value = 24;
    shareResultUrl.value = null;
}

function shareErrorToast() {
    toast.add({
        severity: 'error',
        summary: t('files.share'),
        detail: t('files.share_failed'),
        life: 4000,
    });
}

async function createShare() {
    if (!shareDialogFile.value) return;
    shareCreating.value = true;
    try {
        const res = await fetch(customerUrl(`/files/${shareDialogFile.value.id}/shares`), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? ''),
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                expires_in_hours: shareHours.value,
                password: sharePassword.value || null,
            }),
        });
        if (!res.ok) {
            shareErrorToast();
            return;
        }
        const data = await res.json();
        shareResultUrl.value = data.url;
        await navigator.clipboard?.writeText(data.url).catch(() => undefined);
    } catch {
        shareErrorToast();
    } finally {
        shareCreating.value = false;
    }
}

async function quickShare(item: FileItem) {
    if (item.type !== 'file') return openShareDialog(item);
    try {
        const res = await fetch(customerUrl(`/files/${item.id}/shares/signed`), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? ''),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ hours: 24 }),
        });
        if (!res.ok) {
            shareErrorToast();
            return;
        }
        const data = await res.json();
        await navigator.clipboard?.writeText(data.url).catch(() => undefined);
        shareResultUrl.value = data.url;
        shareDialogFile.value = item;
    } catch {
        shareErrorToast();
    }
}

// ── Multi-select + bulk actions ────────────────────────────────────
const selectedIds = ref<Set<number>>(new Set());
const lastClickedId = ref<number | null>(null);
const selectedCount = computed(() => selectedIds.value.size);

function isSelected(id: number): boolean {
    return selectedIds.value.has(id);
}

function toggleSelect(item: FileItem, event?: MouseEvent) {
    const next = new Set(selectedIds.value);
    // Shift-click selects the contiguous range from the last clicked item.
    if (event?.shiftKey && lastClickedId.value !== null) {
        const ids = mergedItems.value.map((i) => i.id);
        const a = ids.indexOf(lastClickedId.value);
        const b = ids.indexOf(item.id);
        if (a >= 0 && b >= 0) {
            const [lo, hi] = a < b ? [a, b] : [b, a];
            for (let i = lo; i <= hi; i++) next.add(ids[i]);
            selectedIds.value = next;
            lastClickedId.value = item.id;
            return;
        }
    }
    if (next.has(item.id)) next.delete(item.id);
    else next.add(item.id);
    selectedIds.value = next;
    lastClickedId.value = item.id;
}

function clearSelection() {
    selectedIds.value = new Set();
    lastClickedId.value = null;
}

function bulkDownload() {
    if (!selectedIds.value.size) return;
    const ids = Array.from(selectedIds.value).join(',');
    window.location.href = customerUrl(`/files/bulk/zip?ids=${ids}`);
}

function confirmBulkDelete() {
    if (!selectedIds.value.size) return;
    confirm.require({
        group: 'files',
        message: t('files.bulk.confirm_delete', { count: selectedIds.value.size }),
        header: t('files.bulk.delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('files.delete'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => {
            router.post(
                customerUrl('/files/bulk/delete'),
                { ids: Array.from(selectedIds.value) },
                { preserveScroll: true, onSuccess: () => clearSelection() },
            );
        },
    });
}

// Move dialog
const moveDialogOpen = ref(false);
const moveFolders = ref<{ id: number; name: string; parent_id: number | null }[]>([]);
// 0 is the "Root (top level)" sentinel — folder ids are always positive, so it
// can't collide. Converted to null (= root) when submitting.
const moveTargetId = ref<number>(0);

async function openMoveDialog() {
    if (!selectedIds.value.size) return;
    moveTargetId.value = 0;
    try {
        const res = await fetch(customerUrl('/files/folders'), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const data = await res.json();
        // Exclude folders that are part of the selection (can't move into self).
        moveFolders.value = (data.folders ?? []).filter((f: { id: number }) => !selectedIds.value.has(f.id));
    } catch {
        moveFolders.value = [];
    }
    moveDialogOpen.value = true;
}

// Indented label list for the destination <select>: Root first, then folders.
const moveFolderOptions = computed(() => {
    const byId = new Map(moveFolders.value.map((f) => [f.id, f]));
    function depth(f: { parent_id: number | null }): number {
        let d = 0;
        let cur = f.parent_id;
        while (cur !== null && byId.has(cur)) {
            d++;
            cur = byId.get(cur)!.parent_id;
        }
        return d;
    }
    const opts: { value: number; label: string }[] = [{ value: 0, label: t('files.root') }];
    for (const f of moveFolders.value) {
        opts.push({ value: f.id, label: `${'  '.repeat(depth(f))}${f.name}` });
    }
    return opts;
});

function submitMove() {
    router.post(
        customerUrl('/files/bulk/move'),
        { ids: Array.from(selectedIds.value), parent_id: moveTargetId.value || null },
        {
            preserveScroll: true,
            onSuccess: () => {
                moveDialogOpen.value = false;
                clearSelection();
            },
        },
    );
}

const currentFolderId = computed(() => props.current_folder?.id ?? null);

const perms = computed<string[]>(() => (page.props.auth?.user?.permissions ?? []) as string[]);
function hasPerm(name: string): boolean {
    return perms.value.includes(name);
}
const canUpload = computed(() => hasPerm('upload files'));
const canCreateFolder = computed(() => hasPerm('create folders'));
const canRename = computed(() => hasPerm('rename files'));
const canDelete = computed(() => hasPerm('delete files'));
const canShare = computed(() => hasPerm('share files'));

const mergedItems = computed(() => (props.items?.data ?? []).map((i) => ({ ...i, ...(liveItems[i.id] ?? {}) })));

// Shared file open/preview behaviour (lightbox, details, text) — the same
// composable powers the entity-file browser on Asset pages.
const {
    lightboxIndex,
    detailsItem,
    textItem,
    lightboxItems,
    openFile,
    openDetails,
    openDetailsById,
    openInNewTab,
    copyLink,
} = useFileMedia<FileItem>({
    items: mergedItems,
    downloadUrl: (i) => customerUrl(`/files/${i.id}/download`),
    onFolder: (i) => router.visit(customerUrl(`/files/${i.id}`)),
    // Personal Files renders documents in its own modal.
    onDocPreview: (i) => {
        docPreviewItem.value = i;
        return true;
    },
});

function openItem(item: FileItem) {
    if (renamingId.value !== null) return;
    openFile(item);
}

function confirmDelete(item: FileItem) {
    confirm.require({
        group: 'files',
        message: t('files.confirm_delete', { name: item.name }),
        header: t('files.delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('files.delete'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(customerUrl(`/files/${item.id}`), { preserveScroll: true }),
    });
}

function startRename(item: FileItem) {
    renamingId.value = item.id;
    renameValue.value = item.name;
    // The input is v-if'd in, so wait for the DOM update before focusing.
    // Select the filename stem (everything before the extension) so typing
    // replaces the name but keeps the extension by default.
    nextTick(() => {
        const el = renameInputRefs.value[item.id];
        if (!el) return;
        el.focus();
        const dot = item.name.lastIndexOf('.');
        if (dot > 0 && item.type === 'file') {
            el.setSelectionRange(0, dot);
        } else {
            el.select();
        }
    });
}

function submitRename(item: FileItem) {
    const name = renameValue.value.trim();
    renamingId.value = null;
    if (!name || name === item.name) return;
    router.patch(customerUrl(`/files/${item.id}`), { name }, { preserveScroll: true });
}

function createFolder() {
    newFolderName.value = '';
    newFolderOpen.value = true;
}

function submitNewFolder() {
    const name = newFolderName.value.trim();
    if (!name) return;
    router.post(
        customerUrl('/files/folder'),
        { name, parent_id: currentFolderId.value },
        {
            preserveScroll: true,
            onSuccess: () => { newFolderOpen.value = false; },
        },
    );
}

function onDragStart(item: FileItem, event: DragEvent) {
    draggingId.value = item.id;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(item.id));
    }
}

function onDragEnd() {
    draggingId.value = null;
    dragOverId.value = null;
}

function onDragOverFolder(item: FileItem, event: DragEvent) {
    if (item.type !== 'folder' || draggingId.value === null || draggingId.value === item.id) return;
    event.preventDefault();
    dragOverId.value = item.id;
}

function onDropOnFolder(target: FileItem | null, event: DragEvent) {
    event.preventDefault();
    event.stopPropagation();
    dragOverId.value = null;
    externalDragOver.value = false;
    externalDragCounter = 0;

    // Internal drag wins: if we initiated a drag from inside the app, treat it
    // as a move even when the browser also reports a "Files" dataTransfer type
    // (some browsers/OS combos surface both for native HTML5 drags).
    const internalId = draggingId.value;

    if (internalId === null && hasExternalFiles(event)) {
        const files = event.dataTransfer?.files;
        if (files && files.length > 0 && canUpload.value) {
            openUploadWithFiles(Array.from(files));
        }
        return;
    }

    const id = internalId;
    draggingId.value = null;
    if (id === null) return;
    if (target && target.type !== 'folder') return;
    // No target = dropped on empty space. Stay in the current folder rather
    // than yanking the file to root (classic file-manager behaviour).
    const targetId = target?.id ?? currentFolderId.value;
    if (id === targetId) return;
    const moving = mergedItems.value.find((i) => i.id === id);
    // No-op when already in that folder (drop on blank inside current folder).
    if (moving && moving.parent_id === targetId) return;
    if (!canRename.value) return;
    router.patch(customerUrl(`/files/${id}`), { parent_id: targetId }, { preserveScroll: true });
}

function hasExternalFiles(event: DragEvent): boolean {
    const types = event.dataTransfer?.types;
    if (!types) return false;
    // DataTransferItemList exposes a `Files` type when the drag contains OS files.
    return Array.from(types).includes('Files');
}

function onAreaDragEnter(event: DragEvent) {
    if (draggingId.value !== null) return;
    if (!hasExternalFiles(event)) return;
    externalDragCounter++;
    externalDragOver.value = true;
}

function onAreaDragLeave(event: DragEvent) {
    if (draggingId.value !== null) return;
    if (!hasExternalFiles(event)) return;
    externalDragCounter = Math.max(0, externalDragCounter - 1);
    if (externalDragCounter === 0) externalDragOver.value = false;
}

async function openUploadWithFiles(files: File[]) {
    uploadOpen.value = true;
    // Wait for the dialog's `watch(open)` reset to run, then forward the files.
    await nextTick();
    await nextTick();
    uploadDialogRef.value?.handleFiles(files);
}

function onSearch() {
    router.get(
        customerUrl(currentFolderId.value ? `/files/${currentFolderId.value}` : '/files'),
        { q: searchQuery.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function setViewMode(mode: 'grid' | 'list') {
    viewMode.value = mode;
    localStorage.setItem('files.viewMode', mode);
}

// `formatBytes` is now `humanBytes` from @/utils/bytes (imported at
// the top of the file as `formatBytes` for backwards-compat with the
// many existing call sites).

function iconFor(item: FileItem): string {
    if (item.type === 'folder') return 'pi-folder';
    if (item.is_image) return 'pi-image';
    if (item.mime_type === 'application/pdf') return 'pi-file-pdf';
    if (item.is_video || item.mime_type?.startsWith('video/')) return 'pi-video';
    if (item.mime_type?.startsWith('audio/')) return 'pi-volume-up';
    return 'pi-file';
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        renamingId.value = null;
        uploadOpen.value = false;
        shareDialogFile.value = null;
        docPreviewItem.value = null;
        detailsItem.value = null;
        textItem.value = null;
        if (selectedIds.value.size) clearSelection();
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => {
    window.removeEventListener('keydown', onKey);
    leaveFilesChannel();
});

// Live patches from the server — dispatched when a queued preview conversion
// finishes or a file is renamed/moved/deleted in another tab. Patch in place
// so the Vue transition animates the thumbnail swap.
const liveItems = reactive<Record<number, Partial<FileItem>>>({});
let fileChannelUserId: number | null = null;

function joinFilesChannel(userId: number) {
    leaveFilesChannel();
    const echo = (window as any).Echo;
    if (!echo) return;
    fileChannelUserId = userId;
    echo.private(`App.Models.User.${userId}`).listen('.FileItemUpdated', (payload: Partial<FileItem> & { id: number }) => {
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
    (id) => {
        if (typeof id === 'number') joinFilesChannel(id);
        else leaveFilesChannel();
    },
    { immediate: true },
);

const uploadUrl = computed(() => customerUrl('/files'));
const extraUploadData = computed(() => ({ parent_id: currentFolderId.value }));
// UploadDialog's max-file-size prop is in MB; the server sends a byte ceiling.
const maxUploadMb = computed(() => props.max_upload_bytes / (1024 * 1024));
// FilesUsageBar formats its own "used / quota" label (including the
// "Disabled" branch for quota === 0), so the page no longer needs a
// computed wrapper.
</script>

<template>
    <div>
        <Head :title="t('files.title')" />
        <ConfirmDialog group="files" />

        <div
            class="cmd-files-page"
            @dragenter="onAreaDragEnter"
            @dragleave="onAreaDragLeave"
            @dragover.prevent
            @drop="onDropOnFolder(null, $event)"
        >
            <!-- External drag overlay -->
            <div
                v-if="externalDragOver && canUpload"
                :style="{
                    position: 'fixed',
                    inset: '16px',
                    zIndex: 30,
                    pointerEvents: 'none',
                    borderRadius: '12px',
                    border: '2px dashed var(--accent)',
                    background: 'var(--accent-soft)',
                    backdropFilter: 'blur(4px)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: 'var(--accent)',
                }"
            >
                <div :style="{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '8px' }">
                    <i class="pi pi-upload" :style="{ fontSize: '32px' }" />
                    <span :style="{ fontSize: '14px', fontWeight: 500 }">{{ t('files.drop_to_upload') }}</span>
                </div>
            </div>

            <!-- Shared toolbar — same component used on Shared Files,
                 so every header tweak shows up on both scopes. -->
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
                    <Link
                        :href="customerUrl('/files/trash')"
                        class="cmd-ghost-btn"
                        :style="{ position: 'relative' }"
                    >
                        <i class="pi pi-trash" :style="{ fontSize: '11px' }" />
                        <span>{{ t('files.trash') }}</span>
                        <span
                            v-if="(props.trashed_count ?? 0) > 0"
                            :style="{
                                marginLeft: '4px',
                                minWidth: '18px',
                                display: 'inline-flex',
                                justifyContent: 'center',
                                background: 'rgba(255, 138, 138, 0.15)',
                                color: 'var(--danger)',
                                borderRadius: '9px',
                                padding: '1px 6px',
                                fontSize: '10px',
                                fontWeight: 600,
                            }"
                        >{{ props.trashed_count }}</span>
                    </Link>
                </template>
            </FilesToolbar>

            <!-- Shared usage meter — identical on both Files surfaces. -->
            <FilesUsageBar
                :used-bytes="props.usage.used_bytes"
                :quota-bytes="props.usage.quota_bytes"
            />

            <!-- Empty state -->
            <div
                v-if="mergedItems.length === 0"
                :style="{
                    border: '1px dashed var(--border)',
                    background: 'var(--panel)',
                    borderRadius: '6px',
                    padding: '72px 16px',
                    textAlign: 'center',
                    color: 'var(--fg-mute)',
                }"
            >
                <i class="pi pi-folder-open" :style="{ fontSize: '32px' }" />
                <p :style="{ marginTop: '10px', fontSize: '12px' }">{{ t('files.empty') }}</p>
            </div>

            <!-- Grid -->
            <TransitionGroup
                v-else-if="viewMode === 'grid'"
                tag="div"
                name="item-fade"
                :style="{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fill, minmax(148px, 1fr))',
                    gap: '10px',
                }"
            >
                <div
                    v-for="item in mergedItems"
                    :key="item.id"
                    class="cmd-file-card"
                    :class="{ 'cmd-file-card-selected': isSelected(item.id) }"
                    :style="{
                        border: `1px solid ${isSelected(item.id) ? 'var(--accent)' : dragOverId === item.id ? 'var(--accent)' : 'var(--border)'}`,
                        background: 'var(--panel)',
                        borderRadius: '6px',
                        overflow: 'hidden',
                        cursor: 'pointer',
                        position: 'relative',
                        transition: 'border-color 0.12s, background 0.12s',
                        opacity: draggingId === item.id ? 0.5 : 1,
                    }"
                    draggable="true"
                    @dragstart="onDragStart(item, $event)"
                    @dragend="onDragEnd"
                    @dragover="onDragOverFolder(item, $event)"
                    @dragleave="dragOverId = null"
                    @drop="onDropOnFolder(item, $event)"
                    @click="openItem(item)"
                >
                    <!-- Selection checkbox (shows on hover or when any selected) -->
                    <button
                        type="button"
                        class="cmd-select-box"
                        :class="{ 'cmd-select-box-on': isSelected(item.id), 'cmd-select-box-active': selectedCount > 0 }"
                        :aria-label="t('files.bulk.select')"
                        @click.stop="toggleSelect(item, $event)"
                    >
                        <i v-if="isSelected(item.id)" class="pi pi-check" :style="{ fontSize: '10px' }" />
                    </button>
                    <div
                        :style="{
                            position: 'relative',
                            aspectRatio: '1 / 1',
                            background: 'var(--panel2)',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            overflow: 'hidden',
                        }"
                    >
                        <img
                            v-if="item.thumbnail_url"
                            :src="item.thumbnail_url"
                            :alt="item.name"
                            :style="{ width: '100%', height: '100%', objectFit: 'cover' }"
                            loading="lazy"
                        />
                        <i v-else :class="`pi ${iconFor(item)}`" :style="{ fontSize: '40px', color: 'var(--fg-mute)' }" />

                        <div
                            v-if="item.is_video && item.video_ready"
                            :style="{
                                position: 'absolute',
                                inset: 0,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                pointerEvents: 'none',
                            }"
                        >
                            <div
                                :style="{
                                    borderRadius: '9999px',
                                    background: 'rgba(0,0,0,0.55)',
                                    color: '#fff',
                                    padding: '10px',
                                    boxShadow: '0 4px 14px rgba(0,0,0,0.35)',
                                }"
                            ><i class="pi pi-play" /></div>
                        </div>
                        <div
                            v-else-if="item.is_video && item.video_processing"
                            :style="{
                                position: 'absolute',
                                inset: 0,
                                display: 'flex',
                                flexDirection: 'column',
                                alignItems: 'center',
                                justifyContent: 'center',
                                gap: '4px',
                                background: 'rgba(10,12,18,0.65)',
                                color: '#fff',
                                fontSize: '11px',
                                pointerEvents: 'none',
                            }"
                        >
                            <i class="pi pi-spin pi-spinner" :style="{ fontSize: '20px' }" />
                            <span>{{ t('files.video_processing') }}</span>
                        </div>
                        <div
                            v-else-if="item.preview_processing"
                            :style="{
                                position: 'absolute',
                                inset: 0,
                                display: 'flex',
                                flexDirection: 'column',
                                alignItems: 'center',
                                justifyContent: 'center',
                                gap: '4px',
                                background: 'rgba(10,12,18,0.55)',
                                color: '#fff',
                                fontSize: '11px',
                                pointerEvents: 'none',
                            }"
                        >
                            <i class="pi pi-spin pi-spinner" :style="{ fontSize: '20px' }" />
                            <span>{{ t('files.preview_processing') }}</span>
                        </div>
                    </div>
                    <div :style="{ padding: '7px 10px 9px' }">
                        <div :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '6px' }">
                            <input
                                v-if="renamingId === item.id"
                                :ref="(el) => registerRenameInput(item.id, el)"
                                v-model="renameValue"
                                type="text"
                                :style="{
                                    flex: 1,
                                    border: '1px solid var(--accent-border)',
                                    background: 'var(--panel2)',
                                    color: 'var(--fg)',
                                    borderRadius: '3px',
                                    padding: '2px 4px',
                                    fontSize: '12px',
                                    fontFamily: 'inherit',
                                }"
                                @click.stop
                                @keyup.enter="submitRename(item)"
                                @blur="submitRename(item)"
                            />
                            <span
                                v-else
                                :title="item.name"
                                :style="{
                                    flex: 1,
                                    overflow: 'hidden',
                                    textOverflow: 'ellipsis',
                                    whiteSpace: 'nowrap',
                                    color: 'var(--fg)',
                                    fontSize: '12px',
                                }"
                            >{{ item.name }}</span>
                        </div>
                        <div
                            class="cmd-mono"
                            :style="{
                                marginTop: '2px',
                                fontSize: '10.5px',
                                color: 'var(--fg-mute)',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                whiteSpace: 'nowrap',
                            }"
                        >
                            <template v-if="item.type === 'file'">{{ formatBytes(item.size) }}</template>
                            <template v-else>&nbsp;</template>
                        </div>
                    </div>
                    <div
                        class="cmd-file-actions"
                        :style="{ position: 'absolute', right: '4px', top: '4px' }"
                    >
                        <ItemActionsMenu
                            :item="item"
                            :download-url="item.type === 'file' ? customerUrl(`/files/${item.id}/download`) : undefined"
                            variant="overlay"
                            @open="openItem(item)"
                            @rename="startRename(item)"
                            @share="openShareDialog(item)"
                            @delete="confirmDelete(item)"
                            :canShareToCompany="canShareToCompany"
                            @shareToCompany="shareToCompany(item)"
                            @unshareFromCompany="unshareFromCompany(item)"
                            @details="openDetails(item)"
                            @copyLink="copyLink(item)"
                            @openNewTab="openInNewTab(item)"
                        />
                    </div>
                </div>
            </TransitionGroup>

            <!-- List -->
            <div
                v-else
                :style="{
                    background: 'var(--panel)',
                    border: '1px solid var(--border)',
                    borderRadius: '6px',
                    overflow: 'hidden',
                }"
            >
                <table :style="{ width: '100%', borderCollapse: 'collapse', fontSize: '12.5px' }">
                    <thead>
                        <tr>
                            <th
                                class="cmd-mono cmd-uc"
                                :style="{
                                    textAlign: 'left',
                                    padding: '9px 14px',
                                    fontSize: '10.5px',
                                    color: 'var(--fg-mute)',
                                    background: 'var(--panel2)',
                                    borderBottom: '1px solid var(--border)',
                                    fontWeight: 500,
                                    letterSpacing: '0.06em',
                                }"
                            >{{ t('files.root') }}</th>
                            <th
                                class="cmd-mono cmd-uc"
                                :style="{
                                    textAlign: 'left',
                                    padding: '9px 14px',
                                    fontSize: '10.5px',
                                    color: 'var(--fg-mute)',
                                    background: 'var(--panel2)',
                                    borderBottom: '1px solid var(--border)',
                                    fontWeight: 500,
                                    letterSpacing: '0.06em',
                                    width: '100px',
                                }"
                            >{{ t('files.size') }}</th>
                            <th
                                class="cmd-mono cmd-uc"
                                :style="{
                                    textAlign: 'left',
                                    padding: '9px 14px',
                                    fontSize: '10.5px',
                                    color: 'var(--fg-mute)',
                                    background: 'var(--panel2)',
                                    borderBottom: '1px solid var(--border)',
                                    fontWeight: 500,
                                    letterSpacing: '0.06em',
                                    width: '180px',
                                }"
                            >{{ t('files.modified') }}</th>
                            <th
                                :style="{
                                    padding: '9px 14px',
                                    background: 'var(--panel2)',
                                    borderBottom: '1px solid var(--border)',
                                    width: '60px',
                                }"
                            />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in mergedItems"
                            :key="item.id"
                            class="cmd-file-row"
                            :style="{
                                borderTop: '1px solid var(--border)',
                                background: dragOverId === item.id ? 'var(--accent-soft)' : 'transparent',
                                opacity: draggingId === item.id ? 0.5 : 1,
                            }"
                            draggable="true"
                            @dragstart="onDragStart(item, $event)"
                            @dragend="onDragEnd"
                            @dragover="onDragOverFolder(item, $event)"
                            @drop="onDropOnFolder(item, $event)"
                        >
                            <td :style="{ padding: '8px 14px', color: 'var(--fg)' }">
                                <div :style="{ display: 'flex', alignItems: 'center', gap: '8px' }">
                                    <button
                                        type="button"
                                        class="cmd-select-box cmd-select-box-inline"
                                        :class="{ 'cmd-select-box-on': isSelected(item.id), 'cmd-select-box-active': selectedCount > 0 }"
                                        :aria-label="t('files.bulk.select')"
                                        @click.stop="toggleSelect(item, $event)"
                                    >
                                        <i v-if="isSelected(item.id)" class="pi pi-check" :style="{ fontSize: '9px' }" />
                                    </button>
                                    <i :class="`pi ${iconFor(item)}`" :style="{ color: 'var(--fg-mute)', fontSize: '12px' }" />
                                    <button
                                        v-if="renamingId !== item.id"
                                        type="button"
                                        :style="{
                                            background: 'transparent',
                                            border: 'none',
                                            color: 'var(--fg)',
                                            padding: 0,
                                            cursor: 'pointer',
                                            textAlign: 'left',
                                            fontSize: '12.5px',
                                            fontFamily: 'inherit',
                                            overflow: 'hidden',
                                            textOverflow: 'ellipsis',
                                            whiteSpace: 'nowrap',
                                        }"
                                        @click="openItem(item)"
                                    >{{ item.name }}</button>
                                    <input
                                        v-else
                                        :ref="(el) => registerRenameInput(item.id, el)"
                                        v-model="renameValue"
                                        type="text"
                                        :style="{
                                            border: '1px solid var(--accent-border)',
                                            background: 'var(--panel2)',
                                            color: 'var(--fg)',
                                            borderRadius: '3px',
                                            padding: '2px 4px',
                                            fontSize: '12px',
                                            fontFamily: 'inherit',
                                        }"
                                        @click.stop
                                        @keyup.enter="submitRename(item)"
                                        @blur="submitRename(item)"
                                    />
                                </div>
                            </td>
                            <td class="cmd-mono" :style="{ padding: '8px 14px', color: 'var(--fg-dim)', fontSize: '11.5px' }">
                                {{ item.type === 'file' ? formatBytes(item.size) : '—' }}
                            </td>
                            <td class="cmd-mono" :style="{ padding: '8px 14px', color: 'var(--fg-dim)', fontSize: '11.5px' }">
                                {{ item.updated_at ? new Date(item.updated_at).toLocaleString() : '—' }}
                            </td>
                            <td :style="{ padding: '8px 14px', textAlign: 'right' }">
                                <ItemActionsMenu
                                    :item="item"
                                    :download-url="item.type === 'file' ? customerUrl(`/files/${item.id}/download`) : undefined"
                                    variant="inline"
                                    @open="openItem(item)"
                                    @rename="startRename(item)"
                                    @share="openShareDialog(item)"
                                    @delete="confirmDelete(item)"
                                    :canShareToCompany="canShareToCompany"
                                    @shareToCompany="shareToCompany(item)"
                                    @unshareFromCompany="unshareFromCompany(item)"
                                    @details="openDetails(item)"
                                    @copyLink="copyLink(item)"
                                    @openNewTab="openInNewTab(item)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <UploadDialog
            ref="uploadDialogRef"
            v-model:open="uploadOpen"
            :upload-url="uploadUrl"
            :extra-data="extraUploadData"
            :max-file-size="maxUploadMb"
            :multiple="true"
        />

        <ImageLightbox v-if="lightboxItems.length" v-model="lightboxIndex" :items="lightboxItems">
            <template #header-actions="{ item }">
                <button
                    type="button"
                    :aria-label="t('files.details.title')"
                    :title="t('files.details.title')"
                    class="rounded-lg bg-white/10 p-2 text-white transition-colors hover:bg-white/20"
                    @click.stop="openDetailsById(item.id)"
                >
                    <i class="pi pi-info-circle" :style="{ fontSize: '18px' }" />
                </button>
            </template>
        </ImageLightbox>

        <FileDetailsDialog :item="detailsItem" @close="detailsItem = null" />

        <TextPreviewDialog
            :item="textItem"
            :download-url="textItem ? customerUrl(`/files/${textItem.id}/download`) : undefined"
            @close="textItem = null"
        />

        <!-- Bulk action bar -->
        <BulkActionBar
            :count="selectedCount"
            :can-delete="canDelete"
            :can-move="canRename"
            @download="bulkDownload"
            @move="openMoveDialog"
            @delete="confirmBulkDelete"
            @clear="clearSelection"
        />

        <!-- Bulk move dialog -->
        <CommandDialog
            v-model:visible="moveDialogOpen"
            :title="t('files.bulk.move_title', { count: selectedCount })"
            width="420px"
        >
            <CmdSelect
                v-model="moveTargetId"
                :label="t('files.bulk.move_to')"
                :options="moveFolderOptions"
            />
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="moveDialogOpen = false">
                    {{ t('common.cancel') }}
                </CmdButton>
                <CmdButton variant="primary" size="sm" @click="submitMove">
                    <template #icon>
                        <i class="pi pi-folder-open" :style="{ fontSize: '11px' }" />
                    </template>
                    {{ t('files.bulk.move') }}
                </CmdButton>
            </template>
        </CommandDialog>

        <!-- Doc preview dialog -->
        <Teleport to="body">
            <Transition name="doc-preview">
                <div
                    v-if="docPreviewItem"
                    :style="{
                        position: 'fixed',
                        inset: 0,
                        zIndex: 90,
                        background: 'rgba(0,0,0,0.7)',
                        backdropFilter: 'blur(4px)',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        padding: '16px',
                    }"
                    role="dialog"
                    aria-modal="true"
                    @click.self="docPreviewItem = null"
                >
                    <div
                        :style="{
                            display: 'flex',
                            flexDirection: 'column',
                            width: '100%',
                            maxWidth: '960px',
                            maxHeight: '100%',
                            background: 'var(--panel)',
                            border: '1px solid var(--border)',
                            borderRadius: '8px',
                            overflow: 'hidden',
                            boxShadow: 'var(--shadow-palette)',
                        }"
                    >
                        <div
                            :style="{
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                gap: '10px',
                                borderBottom: '1px solid var(--border)',
                                padding: '12px 16px',
                            }"
                        >
                            <div :style="{ display: 'flex', alignItems: 'center', gap: '8px', minWidth: 0 }">
                                <i class="pi pi-file" :style="{ color: 'var(--accent)' }" />
                                <h2 :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', margin: 0 }">
                                    {{ docPreviewItem.name }}
                                </h2>
                            </div>
                            <div :style="{ display: 'flex', alignItems: 'center', gap: '8px' }">
                                <a
                                    :href="customerUrl(`/files/${docPreviewItem.id}/download`)"
                                    :style="{
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        gap: '5px',
                                        background: 'var(--accent)',
                                        color: '#fff',
                                        borderRadius: '5px',
                                        padding: '5px 10px',
                                        fontSize: '12px',
                                        fontWeight: 500,
                                        textDecoration: 'none',
                                    }"
                                >
                                    <i class="pi pi-download" :style="{ fontSize: '11px' }" />
                                    <span>{{ t('files.download_original') }}</span>
                                </a>
                                <button
                                    type="button"
                                    :aria-label="t('common.close')"
                                    @click="docPreviewItem = null"
                                    :style="{
                                        background: 'transparent',
                                        border: 'none',
                                        color: 'var(--fg-mute)',
                                        padding: '6px',
                                        borderRadius: '4px',
                                        cursor: 'pointer',
                                    }"
                                ><i class="pi pi-times" /></button>
                            </div>
                        </div>
                        <div
                            :style="{
                                flex: 1,
                                overflow: 'auto',
                                background: 'var(--bg2)',
                                padding: '16px',
                            }"
                        >
                            <img
                                v-if="docPreviewItem.preview_url"
                                :src="docPreviewItem.preview_url"
                                :alt="docPreviewItem.name"
                                :style="{ maxHeight: '75vh', display: 'block', margin: '0 auto', borderRadius: '4px', boxShadow: '0 2px 10px rgba(0,0,0,0.25)' }"
                            />
                            <div
                                v-else
                                :style="{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '8px', padding: '80px 0', color: 'var(--fg-mute)', fontSize: '12px' }"
                            >
                                <i class="pi pi-spin pi-spinner" :style="{ fontSize: '24px' }" />
                                <span>{{ t('files.preview_processing') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- New folder dialog -->
        <CommandDialog
            v-model:visible="newFolderOpen"
            :title="t('files.new_folder')"
            width="380px"
        >
            <form @submit.prevent="submitNewFolder">
                <Field
                    v-model="newFolderName"
                    :label="t('common.name')"
                    :placeholder="t('files.new_folder')"
                    autofocus
                    @keyup.enter="submitNewFolder"
                />
            </form>
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="newFolderOpen = false">
                    {{ t('common.cancel') }}
                </CmdButton>
                <CmdButton variant="primary" size="sm" @click="submitNewFolder">
                    <template #icon>
                        <Icon name="disk" :size="12" />
                    </template>
                    {{ t('files.new_folder') }}
                </CmdButton>
            </template>
        </CommandDialog>

        <!-- Share dialog -->
        <CommandDialog
            :visible="!!shareDialogFile"
            width="420px"
            @update:visible="(v: boolean) => { if (!v) shareDialogFile = null; }"
        >
            <template #header>
                <h2
                    :style="{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '8px',
                        fontSize: '13px',
                        fontWeight: 600,
                        color: 'var(--fg)',
                        margin: 0,
                    }"
                >
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
                <div
                    class="cmd-mono"
                    :style="{
                        background: 'var(--panel2)',
                        border: '1px solid var(--border)',
                        borderRadius: '4px',
                        padding: '6px 10px',
                        fontSize: '11px',
                        color: 'var(--fg-dim)',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                        whiteSpace: 'nowrap',
                    }"
                >{{ shareResultUrl }}</div>
            </div>

            <template #footer>
                <CmdButton
                    v-if="shareDialogFile?.type === 'file' && !shareResultUrl"
                    variant="ghost"
                    size="sm"
                    @click="quickShare(shareDialogFile)"
                >{{ t('files.quick_link') }}</CmdButton>
                <CmdButton variant="ghost" size="sm" @click="shareDialogFile = null">
                    {{ t('common.close') }}
                </CmdButton>
                <CmdButton
                    v-if="!shareResultUrl"
                    variant="primary"
                    size="sm"
                    :loading="shareCreating"
                    @click="createShare"
                >
                    <template #icon>
                        <i class="pi pi-link" :style="{ fontSize: '11px' }" />
                    </template>
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
.cmd-file-card:hover {
    border-color: var(--accent-border) !important;
}
/* Inline styles override stylesheet rules, so keep the opacity toggle in CSS
   where the :hover selector can actually win. */
.cmd-file-actions {
    opacity: 0;
    transition: opacity 0.12s;
}
/* Selection checkbox — hidden until hover or until a selection is active. */
.cmd-select-box {
    position: absolute;
    top: 6px;
    left: 6px;
    z-index: 5;
    width: 20px;
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 5px;
    border: 1.5px solid var(--border);
    background: var(--overlay, rgba(0, 0, 0, 0.45));
    color: #fff;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.12s, background 0.12s, border-color 0.12s;
}
.cmd-select-box-inline {
    position: static;
    opacity: 0;
    background: var(--panel2);
    color: var(--fg);
}
.cmd-file-card:hover .cmd-select-box,
.cmd-file-row:hover .cmd-select-box,
.cmd-select-box-active,
.cmd-select-box-on {
    opacity: 1;
}
.cmd-select-box-on {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
}
.cmd-file-card-selected {
    background: var(--accent-soft) !important;
}
.cmd-file-card:hover .cmd-file-actions,
.cmd-file-card:focus-within .cmd-file-actions {
    opacity: 1;
}
.cmd-file-row:hover {
    background: var(--row-hover) !important;
}
.item-fade-move,
.item-fade-enter-active,
.item-fade-leave-active {
    transition: all 260ms ease;
}
.item-fade-enter-from {
    opacity: 0;
    transform: translateY(6px) scale(0.98);
}
.item-fade-leave-to {
    opacity: 0;
    transform: scale(0.96);
}
.item-fade-leave-active {
    position: absolute;
}
.doc-preview-enter-active,
.doc-preview-leave-active {
    transition: opacity 200ms ease;
}
.doc-preview-enter-from,
.doc-preview-leave-to {
    opacity: 0;
}
</style>
