<script setup lang="ts" generic="T extends FileBrowserItem">
/*
 * Shared file browser surface used by BOTH the private (/files) and the
 * shared/company (/files/company) pages. It owns everything between the
 * toolbar and the page-level dialogs: the grid/list rendering, per-item
 * actions menu, selection + bulk bar, inline rename, drag-to-move,
 * drag-to-upload + the upload dialog, the unified preview stack (lightbox /
 * details / text / doc) via useFileMedia, and the shared/linked badge.
 *
 * Scope differences are expressed through props, not forks:
 *   - `basePath` drives every URL (nav, rename, move, download, upload).
 *   - `permissions` gates rename/delete/share/move per surface.
 *   - company items carry `linked` / `can_manage` / `owner` / `shared_by`,
 *     which the card/row renders when present.
 * Scope-specific orchestration (the share-link dialog, share-to-company, the
 * company delete/unlink+notify dialog, bulk endpoints, websocket refresh)
 * stays in the hosting page and is wired through the emitted intents.
 */
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import UploadDialog from '@/Components/Files/UploadDialog.vue';
import ImageLightbox from '@/Components/Files/ImageLightbox.vue';
import ItemActionsMenu from '@/Components/Files/ItemActionsMenu.vue';
import FileDetailsDialog from '@/Components/Files/FileDetailsDialog.vue';
import TextPreviewDialog from '@/Components/Files/TextPreviewDialog.vue';
import BulkActionBar from '@/Components/Files/BulkActionBar.vue';
import { humanBytes as formatBytes } from '@/utils/bytes';
import { useWorkspace } from '@/composables/useWorkspace';
import { useFileMedia } from '@/composables/useFileMedia';
import type { FileBrowserItem } from '@/types/files';

const props = withDefaults(defineProps<{
    items: T[];
    viewMode: 'grid' | 'list';
    scope: 'private' | 'shared';
    // URL prefix for every file operation, e.g. '/files' or '/files/company'.
    basePath: string;
    currentFolderId: number | null;
    permissions?: { rename?: boolean; delete?: boolean; shareLink?: boolean; move?: boolean; upload?: boolean };
    canShareToCompany?: boolean;
    // Multi-select + bulk bar (private only — the company surface has no bulk
    // endpoints, so it renders without checkboxes).
    selectable?: boolean;
    canBulkDelete?: boolean;
    canBulkMove?: boolean;
    // Upload dialog config.
    uploadOpen?: boolean;
    maxUploadMb?: number;
}>(), {
    permissions: () => ({}),
    canShareToCompany: false,
    selectable: false,
    canBulkDelete: false,
    canBulkMove: false,
    uploadOpen: false,
    maxUploadMb: 2048,
});

const emit = defineEmits<{
    'update:uploadOpen': [value: boolean];
    delete: [item: T];
    shareLink: [item: T];
    shareToCompany: [item: T];
    unshareFromCompany: [item: T];
    bulkDownload: [ids: number[]];
    bulkMove: [ids: number[]];
    bulkDelete: [ids: number[]];
}>();

const { t } = useI18n();
const { workspaceUrl } = useWorkspace();

const perm = computed(() => ({
    rename: props.permissions?.rename ?? false,
    delete: props.permissions?.delete ?? false,
    shareLink: props.permissions?.shareLink ?? false,
    move: props.permissions?.move ?? false,
    upload: props.permissions?.upload ?? false,
}));

const url = (path: string) => workspaceUrl(`${props.basePath}${path}`);
// Param kept structural (not `T`) so it accepts both the rendered rows and the
// non-generic `docPreviewItem` ref below without fighting the component generic.
const downloadUrl = (item: { id: number }) => url(`/${item.id}/download`);
// The normalized JPEG for RAW/TIFF/HEIC — only when one was generated.
const convertedUrl = (item: { id: number; has_converted_image?: boolean }) =>
    item.has_converted_image ? `${downloadUrl(item)}?variant=converted` : undefined;
function lightboxConvertedUrl(id: number | string): string | undefined {
    const found = props.items.find((i) => i.id === Number(id));
    return found ? convertedUrl(found) : undefined;
}

// company items carry `can_manage`; private items are always manageable by
// their owner. `linked` items (a personal file mirrored into the company tree)
// can be unshared/deleted but not renamed/moved in place.
function manageable(item: T): boolean {
    return props.scope === 'private' ? true : item.can_manage === true;
}
function canRenameItem(item: T): boolean {
    return perm.value.rename && manageable(item) && item.linked !== true;
}
function canDeleteItem(item: T): boolean {
    return perm.value.delete && manageable(item);
}
function canMoveItem(item: T): boolean {
    return perm.value.move && manageable(item) && item.linked !== true;
}
const canShareLink = computed(() => props.scope === 'private' && perm.value.shareLink);

const itemsRef = computed(() => props.items);

// ── Preview stack (lightbox / details / text / doc) ──────────────────
// Non-generic (FileBrowserItem, not T) so template assignments and downloadUrl
// don't trip the generic-ref distribution typing.
const docPreviewItem = ref<FileBrowserItem | null>(null);
const {
    lightboxIndex,
    detailsItem,
    textItem,
    lightboxItems,
    isLightboxMedia,
    openFile,
    openDetails,
    openDetailsById,
    openInNewTab,
    copyLink,
} = useFileMedia<T>({
    items: itemsRef,
    downloadUrl,
    onFolder: (i) => router.visit(url(`/${i.id}`)),
    onDocPreview: (i) => { docPreviewItem.value = i; return true; },
});

// Whether the rich, ownership-scoped actions (text preview, EXIF details,
// copy-link) are safe to offer. The private surface owns its files; the
// company surface lists files owned by anyone, and those endpoints live under
// /files/{id}/… and authorize by ownership — so previews there stay on the
// payload-URL lightbox + doc modal only.
const fullPreview = computed(() => props.scope === 'private');

// Wrapped so the template doesn't assign null directly to the generic refs
// (vue-tsc rejects `detailsItem = null` under the `T` distribution typing).
function closeDetails() { detailsItem.value = null; }
function closeText() { textItem.value = null; }

function openItem(item: T) {
    if (renamingId.value !== null) return;
    if (!fullPreview.value && item.type === 'file' && !isLightboxMedia(item) && !item.has_doc_preview) {
        // Company file that isn't an inline-previewable media/doc — download it
        // rather than hitting the owner-scoped text/preview endpoints.
        window.location.href = downloadUrl(item);
        return;
    }
    openFile(item);
}

// ── Inline rename ────────────────────────────────────────────────────
const renamingId = ref<number | null>(null);
const renameValue = ref('');
const renameInputRefs = ref<Record<number, HTMLInputElement | null>>({});
function registerRenameInput(id: number, el: unknown): void {
    renameInputRefs.value[id] = el instanceof HTMLInputElement ? el : null;
}
function startRename(item: T) {
    renamingId.value = item.id;
    renameValue.value = item.name;
    nextTick(() => {
        const el = renameInputRefs.value[item.id];
        if (!el) return;
        el.focus();
        const dot = item.name.lastIndexOf('.');
        if (dot > 0 && item.type === 'file') el.setSelectionRange(0, dot);
        else el.select();
    });
}
function submitRename(item: T) {
    const name = renameValue.value.trim();
    renamingId.value = null;
    if (!name || name === item.name) return;
    router.patch(url(`/${item.id}`), { name }, { preserveScroll: true });
}

// ── Multi-select + bulk ──────────────────────────────────────────────
const selectedIds = ref<Set<number>>(new Set());
const lastClickedId = ref<number | null>(null);
const selectedCount = computed(() => selectedIds.value.size);
const isSelected = (id: number) => selectedIds.value.has(id);
function toggleSelect(item: T, event?: MouseEvent) {
    const next = new Set(selectedIds.value);
    if (event?.shiftKey && lastClickedId.value !== null) {
        const ids = props.items.map((i) => i.id);
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
defineExpose({ clearSelection });

// ── Drag to move (between folders) ───────────────────────────────────
const draggingId = ref<number | null>(null);
const dragOverId = ref<number | null>(null);
function onDragStart(item: T, event: DragEvent) {
    if (!canMoveItem(item)) return;
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
function onDragOverFolder(item: T, event: DragEvent) {
    if (item.type !== 'folder' || draggingId.value === null || draggingId.value === item.id) return;
    event.preventDefault();
    dragOverId.value = item.id;
}
function hasExternalFiles(event: DragEvent): boolean {
    const types = event.dataTransfer?.types;
    return types ? Array.from(types).includes('Files') : false;
}
function onDropOnFolder(target: T | null, event: DragEvent) {
    event.preventDefault();
    event.stopPropagation();
    dragOverId.value = null;
    externalDragOver.value = false;
    externalDragCounter = 0;

    const internalId = draggingId.value;
    if (internalId === null && hasExternalFiles(event)) {
        const files = event.dataTransfer?.files;
        if (files && files.length > 0 && perm.value.upload) openUploadWithFiles(Array.from(files));
        return;
    }
    draggingId.value = null;
    if (internalId === null) return;
    if (target && target.type !== 'folder') return;
    const targetId = target?.id ?? props.currentFolderId;
    if (internalId === targetId) return;
    const moving = props.items.find((i) => i.id === internalId);
    if (moving && moving.parent_id === targetId) return;
    if (moving && !canMoveItem(moving)) return;
    router.patch(url(`/${internalId}`), { parent_id: targetId }, { preserveScroll: true });
}

// ── Drag-to-upload (external files onto the page) ────────────────────
const externalDragOver = ref(false);
let externalDragCounter = 0;
function onAreaDragEnter(event: DragEvent) {
    if (draggingId.value !== null || !hasExternalFiles(event)) return;
    externalDragCounter++;
    externalDragOver.value = true;
}
function onAreaDragLeave(event: DragEvent) {
    if (draggingId.value !== null || !hasExternalFiles(event)) return;
    externalDragCounter = Math.max(0, externalDragCounter - 1);
    if (externalDragCounter === 0) externalDragOver.value = false;
}

// ── Upload dialog ────────────────────────────────────────────────────
const uploadDialogRef = ref<InstanceType<typeof UploadDialog> | null>(null);
const uploadOpenModel = computed({
    get: () => props.uploadOpen,
    set: (v: boolean) => emit('update:uploadOpen', v),
});
const uploadExtraData = computed(() => ({ parent_id: props.currentFolderId }));
async function openUploadWithFiles(files: File[]) {
    uploadOpenModel.value = true;
    await nextTick();
    await nextTick();
    uploadDialogRef.value?.handleFiles(files);
}

// ── Badges / icons / owner chip ──────────────────────────────────────
const isShared = (item: T) => item.shared_to_company === true || item.linked === true;
function iconFor(item: T): string {
    if (item.type === 'folder') return 'pi-folder';
    if (item.is_image) return 'pi-image';
    if (item.mime_type === 'application/pdf') return 'pi-file-pdf';
    if (item.is_video || item.mime_type?.startsWith('video/')) return 'pi-video';
    if (item.mime_type?.startsWith('audio/')) return 'pi-volume-up';
    return 'pi-file';
}

function onKey(e: KeyboardEvent) {
    if (e.key !== 'Escape') return;
    renamingId.value = null;
    docPreviewItem.value = null;
    detailsItem.value = null;
    textItem.value = null;
    if (selectedIds.value.size) clearSelection();
}
const stopDocNavListener = router.on('before', () => { docPreviewItem.value = null; });
onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => {
    window.removeEventListener('keydown', onKey);
    stopDocNavListener();
});
</script>

<template>
    <div
        class="cmd-file-browser"
        @dragenter="onAreaDragEnter"
        @dragleave="onAreaDragLeave"
        @dragover.prevent
        @drop="onDropOnFolder(null, $event)"
    >
        <!-- External drag overlay -->
        <div
            v-if="externalDragOver && perm.upload"
            :style="{ position: 'fixed', inset: '16px', zIndex: 30, pointerEvents: 'none', borderRadius: '12px', border: '2px dashed var(--accent)', background: 'var(--accent-soft)', backdropFilter: 'blur(4px)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--accent)' }"
        >
            <div :style="{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '8px' }">
                <i class="pi pi-upload" :style="{ fontSize: '32px' }" />
                <span :style="{ fontSize: '14px', fontWeight: 500 }">{{ t('files.drop_to_upload') }}</span>
            </div>
        </div>

        <!-- Empty state -->
        <div
            v-if="items.length === 0"
            :style="{ border: '1px dashed var(--border)', background: 'var(--panel)', borderRadius: '6px', padding: '72px 16px', textAlign: 'center', color: 'var(--fg-mute)' }"
        >
            <i class="pi pi-folder-open" :style="{ fontSize: '32px' }" />
            <p :style="{ marginTop: '10px', fontSize: '12px' }">{{ t('files.empty') }}</p>
        </div>

        <!-- Grid -->
        <TransitionGroup
            v-else-if="viewMode === 'grid'"
            tag="div"
            name="item-fade"
            :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(148px, 1fr))', gap: '10px' }"
        >
            <div
                v-for="item in items"
                :key="item.id"
                class="cmd-file-card"
                :class="{ 'cmd-file-card-selected': isSelected(item.id) }"
                :style="{
                    border: `1px solid ${isSelected(item.id) || dragOverId === item.id ? 'var(--accent)' : 'var(--border)'}`,
                    background: 'var(--panel)',
                    borderRadius: '6px',
                    overflow: 'hidden',
                    cursor: 'pointer',
                    position: 'relative',
                    transition: 'border-color 0.12s, background 0.12s',
                    opacity: draggingId === item.id ? 0.5 : 1,
                }"
                :draggable="canMoveItem(item)"
                @dragstart="onDragStart(item, $event)"
                @dragend="onDragEnd"
                @dragover="onDragOverFolder(item, $event)"
                @dragleave="dragOverId = null"
                @drop="onDropOnFolder(item, $event)"
                @click="openItem(item)"
            >
                <button
                    v-if="selectable"
                    type="button"
                    class="cmd-select-box"
                    :class="{ 'cmd-select-box-on': isSelected(item.id), 'cmd-select-box-active': selectedCount > 0 }"
                    :aria-label="t('files.bulk.select')"
                    @click.stop="toggleSelect(item, $event)"
                >
                    <i v-if="isSelected(item.id)" class="pi pi-check" :style="{ fontSize: '10px' }" />
                </button>

                <div :style="{ position: 'relative', aspectRatio: '1 / 1', background: 'var(--panel2)', display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden' }">
                    <img
                        v-if="item.thumbnail_url"
                        :src="item.thumbnail_url"
                        :alt="item.name"
                        :style="{ width: '100%', height: '100%', objectFit: 'cover' }"
                        loading="lazy"
                    />
                    <i v-else :class="`pi ${iconFor(item)}`" :style="{ fontSize: '40px', color: 'var(--fg-mute)' }" />

                    <!-- Shared-to-company / linked badge (shows on BOTH scopes). -->
                    <span
                        v-if="isShared(item)"
                        :title="t('files.shared_to_company')"
                        :style="{ position: 'absolute', top: '6px', left: selectable ? '32px' : '6px', padding: '1px 6px', fontSize: '10px', background: 'var(--accent-soft)', color: 'var(--accent)', borderRadius: '3px', fontWeight: 500 }"
                    >{{ t('files.linked_badge') }}</span>

                    <div
                        v-if="item.is_video && item.video_ready"
                        :style="{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', pointerEvents: 'none' }"
                    >
                        <div :style="{ borderRadius: '9999px', background: 'rgba(0,0,0,0.55)', color: '#fff', padding: '10px', boxShadow: '0 4px 14px rgba(0,0,0,0.35)' }"><i class="pi pi-play" /></div>
                    </div>
                    <div
                        v-else-if="item.is_video && item.video_processing"
                        :style="{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: '4px', background: 'rgba(10,12,18,0.65)', color: '#fff', fontSize: '11px', pointerEvents: 'none' }"
                    >
                        <i class="pi pi-spin pi-spinner" :style="{ fontSize: '20px' }" />
                        <span>{{ t('files.video_processing') }}</span>
                    </div>
                    <div
                        v-else-if="item.preview_processing"
                        :style="{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: '4px', background: 'rgba(10,12,18,0.55)', color: '#fff', fontSize: '11px', pointerEvents: 'none' }"
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
                            :style="{ flex: 1, border: '1px solid var(--accent-border)', background: 'var(--panel2)', color: 'var(--fg)', borderRadius: '3px', padding: '2px 4px', fontSize: '12px', fontFamily: 'inherit' }"
                            @click.stop
                            @keyup.enter="submitRename(item)"
                            @blur="submitRename(item)"
                        />
                        <span
                            v-else
                            :title="item.name"
                            :style="{ flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: 'var(--fg)', fontSize: '12px' }"
                        >{{ item.name }}</span>
                    </div>
                    <div
                        class="cmd-mono"
                        :style="{ marginTop: '2px', fontSize: '10.5px', color: 'var(--fg-mute)', display: 'flex', alignItems: 'center', gap: '6px', overflow: 'hidden', whiteSpace: 'nowrap' }"
                    >
                        <span v-if="item.type === 'file'">{{ formatBytes(item.size) }}</span>
                        <span v-else>&nbsp;</span>
                        <span
                            v-if="item.owner"
                            :style="{ display: 'inline-flex', alignItems: 'center', gap: '4px', overflow: 'hidden', textOverflow: 'ellipsis' }"
                        >
                            <img v-if="item.owner.avatar_thumb_url" :src="item.owner.avatar_thumb_url" :alt="item.owner.name" :style="{ width: '12px', height: '12px', borderRadius: '50%' }" />
                            <span :style="{ overflow: 'hidden', textOverflow: 'ellipsis' }">{{ item.owner.name }}</span>
                        </span>
                    </div>
                </div>

                <div class="cmd-file-actions" :style="{ position: 'absolute', right: '4px', top: '4px' }">
                    <ItemActionsMenu
                        :item="item"
                        :download-url="item.type === 'file' ? downloadUrl(item) : undefined"
                        :converted-download-url="item.type === 'file' ? convertedUrl(item) : undefined"
                        variant="overlay"
                        :can-rename="canRenameItem(item)"
                        :can-share-link="canShareLink"
                        :can-delete="canDeleteItem(item)"
                        :can-details="fullPreview"
                        :can-copy-link="fullPreview"
                        :can-share-to-company="canShareToCompany"
                        @open="openItem(item)"
                        @rename="startRename(item)"
                        @share="emit('shareLink', item)"
                        @delete="emit('delete', item)"
                        @shareToCompany="emit('shareToCompany', item)"
                        @unshareFromCompany="emit('unshareFromCompany', item)"
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
            :style="{ background: 'var(--panel)', border: '1px solid var(--border)', borderRadius: '6px', overflow: 'hidden' }"
        >
            <table :style="{ width: '100%', borderCollapse: 'collapse', fontSize: '12.5px' }">
                <thead>
                    <tr>
                        <th class="cmd-mono cmd-uc cmd-th">{{ t('files.root') }}</th>
                        <th class="cmd-mono cmd-uc cmd-th" :style="{ width: '100px' }">{{ t('files.size') }}</th>
                        <th class="cmd-mono cmd-uc cmd-th cmd-th-hide-sm" :style="{ width: '180px' }">{{ t('files.modified') }}</th>
                        <th class="cmd-th" :style="{ width: '60px' }" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items"
                        :key="item.id"
                        class="cmd-file-row"
                        :style="{ borderTop: '1px solid var(--border)', background: dragOverId === item.id ? 'var(--accent-soft)' : 'transparent', opacity: draggingId === item.id ? 0.5 : 1 }"
                        :draggable="canMoveItem(item)"
                        @dragstart="onDragStart(item, $event)"
                        @dragend="onDragEnd"
                        @dragover="onDragOverFolder(item, $event)"
                        @drop="onDropOnFolder(item, $event)"
                    >
                        <td :style="{ padding: '8px 14px', color: 'var(--fg)' }">
                            <div :style="{ display: 'flex', alignItems: 'center', gap: '8px' }">
                                <button
                                    v-if="selectable"
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
                                    :style="{ background: 'transparent', border: 'none', color: 'var(--fg)', padding: 0, cursor: 'pointer', textAlign: 'left', fontSize: '12.5px', fontFamily: 'inherit', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }"
                                    @click="openItem(item)"
                                >{{ item.name }}</button>
                                <input
                                    v-else
                                    :ref="(el) => registerRenameInput(item.id, el)"
                                    v-model="renameValue"
                                    type="text"
                                    :style="{ border: '1px solid var(--accent-border)', background: 'var(--panel2)', color: 'var(--fg)', borderRadius: '3px', padding: '2px 4px', fontSize: '12px', fontFamily: 'inherit' }"
                                    @click.stop
                                    @keyup.enter="submitRename(item)"
                                    @blur="submitRename(item)"
                                />
                                <span
                                    v-if="isShared(item)"
                                    :title="t('files.shared_to_company')"
                                    :style="{ padding: '1px 6px', fontSize: '10px', background: 'var(--accent-soft)', color: 'var(--accent)', borderRadius: '3px', fontWeight: 500, flexShrink: 0 }"
                                >{{ t('files.linked_badge') }}</span>
                                <span
                                    v-if="item.shared_by"
                                    :style="{ color: 'var(--fg-mute)', fontSize: '11px', flexShrink: 0 }"
                                >· {{ t('files.shared_by', { name: item.shared_by.name }) }}</span>
                            </div>
                        </td>
                        <td class="cmd-mono" :style="{ padding: '8px 14px', color: 'var(--fg-dim)', fontSize: '11.5px' }">
                            {{ item.type === 'file' ? formatBytes(item.size) : '—' }}
                        </td>
                        <td class="cmd-mono cmd-th-hide-sm" :style="{ padding: '8px 14px', color: 'var(--fg-dim)', fontSize: '11.5px' }">
                            {{ item.updated_at ? new Date(item.updated_at).toLocaleString() : '—' }}
                        </td>
                        <td :style="{ padding: '8px 14px', textAlign: 'right' }">
                            <ItemActionsMenu
                                :item="item"
                                :download-url="item.type === 'file' ? downloadUrl(item) : undefined"
                                :converted-download-url="item.type === 'file' ? convertedUrl(item) : undefined"
                                variant="inline"
                                :can-rename="canRenameItem(item)"
                                :can-share-link="canShareLink"
                                :can-delete="canDeleteItem(item)"
                                :can-details="fullPreview"
                                :can-copy-link="fullPreview"
                                :can-share-to-company="canShareToCompany"
                                @open="openItem(item)"
                                @rename="startRename(item)"
                                @share="emit('shareLink', item)"
                                @delete="emit('delete', item)"
                                @shareToCompany="emit('shareToCompany', item)"
                                @unshareFromCompany="emit('unshareFromCompany', item)"
                                @details="openDetails(item)"
                                @copyLink="copyLink(item)"
                                @openNewTab="openInNewTab(item)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Upload -->
        <UploadDialog
            ref="uploadDialogRef"
            v-model:open="uploadOpenModel"
            :upload-url="url('')"
            :extra-data="uploadExtraData"
            :max-file-size="maxUploadMb"
            :multiple="true"
        />

        <!-- Preview stack -->
        <ImageLightbox v-if="lightboxItems.length" v-model="lightboxIndex" :items="lightboxItems">
            <template #header-actions="{ item }">
                <a
                    v-if="lightboxConvertedUrl(item.id)"
                    :href="lightboxConvertedUrl(item.id)"
                    :aria-label="t('files.download_converted')"
                    :title="t('files.download_converted')"
                    class="rounded-lg bg-white/10 p-2 text-white transition-colors hover:bg-white/20"
                    @click.stop
                >
                    <i class="pi pi-image" :style="{ fontSize: '18px' }" />
                </a>
                <button
                    v-if="fullPreview"
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

        <FileDetailsDialog :item="detailsItem" @close="closeDetails" />

        <TextPreviewDialog
            :item="textItem"
            :download-url="textItem ? downloadUrl(textItem) : undefined"
            @close="closeText"
        />

        <!-- Doc preview modal -->
        <Teleport to="body">
            <Transition name="doc-preview">
                <div
                    v-if="docPreviewItem"
                    :style="{ position: 'fixed', inset: 0, zIndex: 90, background: 'rgba(0,0,0,0.7)', backdropFilter: 'blur(4px)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '16px' }"
                    role="dialog"
                    aria-modal="true"
                    @click.self="docPreviewItem = null"
                >
                    <div :style="{ display: 'flex', flexDirection: 'column', width: '100%', maxWidth: '960px', maxHeight: '100%', background: 'var(--panel)', border: '1px solid var(--border)', borderRadius: '8px', overflow: 'hidden', boxShadow: 'var(--shadow-palette)' }">
                        <div :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '10px', borderBottom: '1px solid var(--border)', padding: '12px 16px' }">
                            <div :style="{ display: 'flex', alignItems: 'center', gap: '8px', minWidth: 0 }">
                                <i class="pi pi-file" :style="{ color: 'var(--accent)' }" />
                                <h2 :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', margin: 0 }">{{ docPreviewItem.name }}</h2>
                            </div>
                            <div :style="{ display: 'flex', alignItems: 'center', gap: '8px' }">
                                <a
                                    :href="downloadUrl(docPreviewItem)"
                                    :style="{ display: 'inline-flex', alignItems: 'center', gap: '5px', background: 'var(--accent)', color: '#fff', borderRadius: '5px', padding: '5px 10px', fontSize: '12px', fontWeight: 500, textDecoration: 'none' }"
                                >
                                    <i class="pi pi-download" :style="{ fontSize: '11px' }" />
                                    <span>{{ t('files.download_original') }}</span>
                                </a>
                                <button
                                    type="button"
                                    :aria-label="t('common.close')"
                                    @click="docPreviewItem = null"
                                    :style="{ background: 'transparent', border: 'none', color: 'var(--fg-mute)', padding: '6px', borderRadius: '4px', cursor: 'pointer' }"
                                ><i class="pi pi-times" /></button>
                            </div>
                        </div>
                        <div :style="{ flex: 1, overflow: 'auto', background: 'var(--bg2)', padding: '16px' }">
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

        <!-- Bulk action bar -->
        <BulkActionBar
            v-if="selectable"
            :count="selectedCount"
            :can-delete="canBulkDelete"
            :can-move="canBulkMove"
            @download="emit('bulkDownload', Array.from(selectedIds))"
            @move="emit('bulkMove', Array.from(selectedIds))"
            @delete="emit('bulkDelete', Array.from(selectedIds))"
            @clear="clearSelection"
        />
    </div>
</template>

<style scoped>
.cmd-file-browser { position: relative; }
.cmd-th {
    text-align: left;
    padding: 9px 14px;
    font-size: 10.5px;
    color: var(--fg-mute);
    background: var(--panel2);
    border-bottom: 1px solid var(--border);
    font-weight: 500;
    letter-spacing: 0.06em;
}
.cmd-file-card:hover { border-color: var(--accent-border) !important; }
.cmd-file-card:hover { background: var(--panel) !important; }
/* Actions + selection are hover-revealed on pointer devices… */
.cmd-file-actions {
    opacity: 0;
    transition: opacity 0.12s;
}
.cmd-file-card:hover .cmd-file-actions,
.cmd-file-card:focus-within .cmd-file-actions {
    opacity: 1;
}
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
.cmd-select-box-on { opacity: 1; }
.cmd-select-box-on {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
}
.cmd-file-card-selected { background: var(--accent-soft) !important; }
.cmd-file-row:hover { background: var(--row-hover) !important; }

/* …but on touch / no-hover devices there's no hover to reveal them, so the
 * kebab menu and selection box stay visible. This is the fix for "no options
 * on files on mobile". */
@media (hover: none), (pointer: coarse) {
    .cmd-file-actions { opacity: 1; }
    .cmd-select-box { opacity: 1; }
}

/* Drop the Modified column on narrow screens so the list never overflows. */
@media (max-width: 640px) {
    .cmd-th-hide-sm { display: none; }
}

.item-fade-move,
.item-fade-enter-active,
.item-fade-leave-active { transition: all 260ms ease; }
.item-fade-enter-from { opacity: 0; transform: translateY(6px) scale(0.98); }
.item-fade-leave-to { opacity: 0; transform: scale(0.96); }
.item-fade-leave-active { position: absolute; }
.doc-preview-enter-active,
.doc-preview-leave-active { transition: opacity 200ms ease; }
.doc-preview-enter-from,
.doc-preview-leave-to { opacity: 0; }
</style>
