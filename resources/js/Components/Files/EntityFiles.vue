<script setup lang="ts">
/*
 * Reusable file browser for any polymorphic FileOwner entity (Asset, …).
 * Mirrors the personal Files browser look & behaviour but is simpler: no
 * share-to-company, no live Echo patches, no drag-to-move. All file
 * mutations hit the generic entity-file endpoints with owner_type +
 * owner_id in the payload; after a mutation we reload so the parent page
 * re-provides the `files` prop.
 */
import { computed, nextTick, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useConfirm } from 'primevue/useconfirm';
import ConfirmDialog from 'primevue/confirmdialog';
import CommandDialog from '@/Components/Command/Dialog.vue';
import CmdButton from '@/Components/Command/Button.vue';
import Field from '@/Components/Command/Field.vue';
import Icon from '@/Components/Command/Icon.vue';
import UploadDialog from '@/Components/Files/UploadDialog.vue';
import FilesUsageBar from '@/Components/Files/FilesUsageBar.vue';
import ItemActionsMenu from '@/Components/Files/ItemActionsMenu.vue';
import ImageLightbox from '@/Components/Files/ImageLightbox.vue';
import FileDetailsDialog from '@/Components/Files/FileDetailsDialog.vue';
import TextPreviewDialog from '@/Components/Files/TextPreviewDialog.vue';
import { humanBytes as formatBytes } from '@/utils/bytes';
import { useWorkspace } from '@/composables/useWorkspace';
import { useFileMedia } from '@/composables/useFileMedia';

export interface FileRow {
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
    video_ready?: boolean;
    video_processing?: boolean;
    preview_processing?: boolean;
    has_doc_preview?: boolean;
    thumbnail_url: string | null;
    preview_url: string | null;
    original_url: string | null;
    video_web_url?: string | null;
    video_poster_url?: string | null;
    available_sizes?: Record<string, { url: string; width: number; height: number }> | null;
    created_at: string | null;
    updated_at: string | null;
    can_manage?: boolean;
}

interface Breadcrumb {
    id: number;
    name: string;
}

const props = withDefaults(
    defineProps<{
        ownerType: string;
        ownerId: number;
        files: { data: FileRow[] };
        breadcrumbs: Breadcrumb[];
        currentFolder: { id: number; name: string } | null;
        // Optional storage-usage bar. Omit for owners with no per-entity quota
        // (e.g. Equipment) and the bar is simply not rendered.
        usage?: { used_bytes: number; quota_bytes: number | null; percent: number } | null;
        canManage: boolean;
        // Opt-in "set as cover" affordance for file-owning entities that have a
        // cover image (Equipment). When on, file thumbnails gain a cover toggle.
        allowSetCover?: boolean;
        coverFileItemId?: number | null;
        // Builds the Inertia URL for navigating into a folder (or root when null).
        folderUrl: (folderId: number | null) => string;
    }>(),
    {
        usage: null,
        allowSetCover: false,
        coverFileItemId: null,
    },
);

const emit = defineEmits<{ setCover: [fileId: number] }>();

const { t } = useI18n();
const { workspaceUrl } = useWorkspace();
const confirm = useConfirm();

const viewMode = ref<'grid' | 'list'>((localStorage.getItem('entityFiles.viewMode') as 'grid' | 'list') || 'grid');
const uploadOpen = ref(false);
const newFolderOpen = ref(false);
const newFolderName = ref('');
const renamingId = ref<number | null>(null);
const renameValue = ref('');
const renameInputRefs = ref<Record<number, HTMLInputElement | null>>({});

const confirmGroup = computed(() => `entity-files-${props.ownerType}-${props.ownerId}`);
const currentFolderId = computed(() => props.currentFolder?.id ?? null);
const items = computed(() => props.files?.data ?? []);

const uploadUrl = computed(() => workspaceUrl('/entity-files'));
const extraUploadData = computed(() => ({
    owner_type: props.ownerType,
    owner_id: props.ownerId,
    parent_id: currentFolderId.value,
}));

function setViewMode(mode: 'grid' | 'list') {
    viewMode.value = mode;
    localStorage.setItem('entityFiles.viewMode', mode);
}

function registerRenameInput(id: number, el: unknown): void {
    renameInputRefs.value[id] = el instanceof HTMLInputElement ? el : null;
}

function iconFor(item: FileRow): string {
    if (item.type === 'folder') return 'pi-folder';
    if (item.is_image) return 'pi-image';
    if (item.mime_type === 'application/pdf') return 'pi-file-pdf';
    if (item.mime_type?.startsWith('video/')) return 'pi-video';
    if (item.mime_type?.startsWith('audio/')) return 'pi-volume-up';
    return 'pi-file';
}

// Same unified preview behaviour as the personal Files browser, via the
// shared composable — images/video/audio open the lightbox, text/markdown
// preview inline, details show EXIF + map. Download URLs target the generic
// entity-file endpoint.
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
} = useFileMedia<FileRow>({
    items,
    downloadUrl: (i) => workspaceUrl(`/entity-files/${i.id}/download`),
    onFolder: (i) => router.visit(props.folderUrl(i.id)),
});

function openItem(item: FileRow) {
    if (renamingId.value !== null) return;
    openFile(item);
}

function navigate(folderId: number | null) {
    router.visit(props.folderUrl(folderId));
}

function reload() {
    router.reload();
}

function createFolder() {
    newFolderName.value = '';
    newFolderOpen.value = true;
}

function submitNewFolder() {
    const name = newFolderName.value.trim();
    if (!name) return;
    router.post(
        workspaceUrl('/entity-files/folder'),
        {
            owner_type: props.ownerType,
            owner_id: props.ownerId,
            parent_id: currentFolderId.value,
            name,
        },
        {
            preserveScroll: true,
            onSuccess: () => { newFolderOpen.value = false; },
        },
    );
}

function startRename(item: FileRow) {
    renamingId.value = item.id;
    renameValue.value = item.name;
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

function submitRename(item: FileRow) {
    const name = renameValue.value.trim();
    renamingId.value = null;
    if (!name || name === item.name) return;
    router.patch(workspaceUrl(`/entity-files/${item.id}`), { name }, { preserveScroll: true });
}

function confirmDelete(item: FileRow) {
    confirm.require({
        group: confirmGroup.value,
        message: t('files.confirm_delete', { name: item.name }),
        header: t('files.delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('files.delete'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(workspaceUrl(`/entity-files/${item.id}`), { preserveScroll: true }),
    });
}
</script>

<template>
    <div>
        <ConfirmDialog :group="confirmGroup" />

        <!-- Toolbar: breadcrumbs + actions -->
        <div :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '12px' }">
            <nav
                :aria-label="t('common.breadcrumb')"
                :style="{ display: 'flex', alignItems: 'center', gap: '4px', fontSize: '12px', color: 'var(--fg-mute)', minWidth: 0, flexWrap: 'wrap' }"
            >
                <button
                    type="button"
                    :style="{ background: 'transparent', border: 'none', color: breadcrumbs.length ? 'var(--fg-dim)' : 'var(--fg)', cursor: 'pointer', padding: 0, fontFamily: 'inherit', fontSize: '12px', display: 'inline-flex', alignItems: 'center', gap: '5px' }"
                    @click="navigate(null)"
                >
                    <i class="pi pi-folder" :style="{ fontSize: '11px' }" />
                    <span>{{ t('files.documents_root') }}</span>
                </button>
                <template v-for="(crumb, idx) in breadcrumbs" :key="crumb.id">
                    <i class="pi pi-angle-right" :style="{ fontSize: '10px', color: 'var(--fg-mute)' }" />
                    <button
                        type="button"
                        :style="{ background: 'transparent', border: 'none', color: idx === breadcrumbs.length - 1 ? 'var(--fg)' : 'var(--fg-dim)', cursor: 'pointer', padding: 0, fontFamily: 'inherit', fontSize: '12px', maxWidth: '180px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }"
                        @click="navigate(crumb.id)"
                    >{{ crumb.name }}</button>
                </template>
            </nav>

            <div :style="{ display: 'flex', alignItems: 'center', gap: '6px' }">
                <div :style="{ display: 'inline-flex', border: '1px solid var(--border)', borderRadius: '5px', overflow: 'hidden' }">
                    <button
                        type="button"
                        :title="t('files.view_grid')"
                        :aria-label="t('files.view_grid')"
                        :style="{ background: viewMode === 'grid' ? 'var(--accent-soft)' : 'transparent', color: viewMode === 'grid' ? 'var(--accent)' : 'var(--fg-mute)', border: 'none', padding: '5px 8px', cursor: 'pointer' }"
                        @click="setViewMode('grid')"
                    ><i class="pi pi-th-large" :style="{ fontSize: '11px' }" /></button>
                    <button
                        type="button"
                        :title="t('files.view_list')"
                        :aria-label="t('files.view_list')"
                        :style="{ background: viewMode === 'list' ? 'var(--accent-soft)' : 'transparent', color: viewMode === 'list' ? 'var(--accent)' : 'var(--fg-mute)', border: 'none', padding: '5px 8px', cursor: 'pointer' }"
                        @click="setViewMode('list')"
                    ><i class="pi pi-list" :style="{ fontSize: '11px' }" /></button>
                </div>
                <CmdButton v-if="canManage" variant="ghost" size="sm" @click="createFolder">
                    <template #icon><i class="pi pi-folder-plus" :style="{ fontSize: '11px' }" /></template>
                    {{ t('files.new_folder') }}
                </CmdButton>
                <CmdButton v-if="canManage" variant="primary" size="sm" @click="uploadOpen = true">
                    <template #icon><i class="pi pi-upload" :style="{ fontSize: '11px' }" /></template>
                    {{ t('files.upload') }}
                </CmdButton>
            </div>
        </div>

        <FilesUsageBar v-if="usage" :used-bytes="usage.used_bytes" :quota-bytes="usage.quota_bytes" />

        <!-- Empty state -->
        <div
            v-if="items.length === 0"
            :style="{ border: '1px dashed var(--border)', background: 'var(--panel)', borderRadius: '6px', padding: '56px 16px', textAlign: 'center', color: 'var(--fg-mute)' }"
        >
            <i class="pi pi-folder-open" :style="{ fontSize: '28px' }" />
            <p :style="{ marginTop: '10px', fontSize: '12px' }">{{ t('files.empty_documents') }}</p>
        </div>

        <!-- Grid -->
        <div
            v-else-if="viewMode === 'grid'"
            :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(148px, 1fr))', gap: '10px' }"
        >
            <div
                v-for="item in items"
                :key="item.id"
                class="cmd-file-card"
                :style="{ border: '1px solid var(--border)', background: 'var(--panel)', borderRadius: '6px', overflow: 'hidden', cursor: 'pointer', position: 'relative', transition: 'border-color 0.12s' }"
                @click="openItem(item)"
            >
                <div :style="{ position: 'relative', aspectRatio: '1 / 1', background: 'var(--panel2)', display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden' }">
                    <img
                        v-if="item.thumbnail_url"
                        :src="item.thumbnail_url"
                        :alt="item.name"
                        :style="{ width: '100%', height: '100%', objectFit: 'cover' }"
                        loading="lazy"
                    />
                    <i v-else :class="`pi ${iconFor(item)}`" :style="{ fontSize: '40px', color: 'var(--fg-mute)' }" />

                    <!-- Play overlay for ready videos -->
                    <div
                        v-if="item.is_video && item.video_ready"
                        :style="{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', pointerEvents: 'none' }"
                    >
                        <div :style="{ borderRadius: '9999px', background: 'rgba(0,0,0,0.55)', color: '#fff', padding: '10px', boxShadow: '0 4px 14px rgba(0,0,0,0.35)' }"><i class="pi pi-play" /></div>
                    </div>
                    <!-- Processing spinner (video transcode / RAW-TIFF preview) -->
                    <div
                        v-else-if="(item.is_video && item.video_processing) || item.preview_processing"
                        :style="{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: '4px', background: 'rgba(10,12,18,0.6)', color: '#fff', fontSize: '11px', pointerEvents: 'none' }"
                    >
                        <i class="pi pi-spin pi-spinner" :style="{ fontSize: '20px' }" />
                        <span>{{ item.is_video ? t('files.video_processing') : t('files.preview_processing') }}</span>
                    </div>

                    <!-- Cover controls (opt-in via allowSetCover) -->
                    <div
                        v-if="allowSetCover && item.type === 'file'"
                        :style="{ position: 'absolute', left: '5px', top: '5px', display: 'flex', alignItems: 'center', gap: '4px' }"
                        @click.stop
                    >
                        <span
                            v-if="item.id === coverFileItemId"
                            :style="{ background: 'var(--accent)', color: '#fff', fontSize: '9px', fontWeight: 600, padding: '2px 6px', borderRadius: '4px', textTransform: 'uppercase', letterSpacing: '0.04em' }"
                        >{{ t('files.cover') }}</span>
                        <button
                            v-else-if="canManage"
                            type="button"
                            class="cmd-cover-btn"
                            :title="t('files.set_as_cover')"
                            :aria-label="t('files.set_as_cover')"
                            :style="{ background: 'rgba(0,0,0,0.55)', color: '#fff', border: 'none', borderRadius: '4px', width: '22px', height: '22px', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }"
                            @click.stop="emit('setCover', item.id)"
                        ><i class="pi pi-star" :style="{ fontSize: '11px' }" /></button>
                    </div>
                </div>
                <div :style="{ padding: '7px 10px 9px' }">
                    <input
                        v-if="renamingId === item.id"
                        :ref="(el) => registerRenameInput(item.id, el)"
                        v-model="renameValue"
                        type="text"
                        :style="{ width: '100%', border: '1px solid var(--accent-border)', background: 'var(--panel2)', color: 'var(--fg)', borderRadius: '3px', padding: '2px 4px', fontSize: '12px', fontFamily: 'inherit' }"
                        @click.stop
                        @keyup.enter="submitRename(item)"
                        @blur="submitRename(item)"
                    />
                    <span
                        v-else
                        :title="item.name"
                        :style="{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: 'var(--fg)', fontSize: '12px' }"
                    >{{ item.name }}</span>
                    <div
                        class="cmd-mono"
                        :style="{ marginTop: '2px', fontSize: '10.5px', color: 'var(--fg-mute)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }"
                    >
                        <template v-if="item.type === 'file'">{{ formatBytes(item.size) }}</template>
                        <template v-else>&nbsp;</template>
                    </div>
                </div>
                <div v-if="canManage" class="cmd-file-actions" :style="{ position: 'absolute', right: '4px', top: '4px' }">
                    <ItemActionsMenu
                        :item="item"
                        :download-url="item.type === 'file' ? workspaceUrl(`/entity-files/${item.id}/download`) : undefined"
                        variant="overlay"
                        @open="openItem(item)"
                        @rename="startRename(item)"
                        @delete="confirmDelete(item)"
                        @details="openDetails(item)"
                        @copyLink="copyLink(item)"
                        @openNewTab="openInNewTab(item)"
                    />
                </div>
            </div>
        </div>

        <!-- List -->
        <div
            v-else
            :style="{ background: 'var(--panel)', border: '1px solid var(--border)', borderRadius: '6px', overflow: 'hidden' }"
        >
            <table :style="{ width: '100%', borderCollapse: 'collapse', fontSize: '12.5px' }">
                <thead>
                    <tr>
                        <th
                            class="cmd-mono cmd-uc"
                            :style="{ textAlign: 'left', padding: '9px 14px', fontSize: '10.5px', color: 'var(--fg-mute)', background: 'var(--panel2)', borderBottom: '1px solid var(--border)', fontWeight: 500, letterSpacing: '0.06em' }"
                        >{{ t('files.col_name') }}</th>
                        <th
                            class="cmd-mono cmd-uc"
                            :style="{ textAlign: 'left', padding: '9px 14px', fontSize: '10.5px', color: 'var(--fg-mute)', background: 'var(--panel2)', borderBottom: '1px solid var(--border)', fontWeight: 500, letterSpacing: '0.06em', width: '110px' }"
                        >{{ t('files.size') }}</th>
                        <th :style="{ padding: '9px 14px', background: 'var(--panel2)', borderBottom: '1px solid var(--border)', width: '60px' }" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items"
                        :key="item.id"
                        class="cmd-file-row"
                        :style="{ borderTop: '1px solid var(--border)' }"
                    >
                        <td :style="{ padding: '8px 14px', color: 'var(--fg)' }">
                            <div :style="{ display: 'flex', alignItems: 'center', gap: '8px' }">
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
                                <span v-if="allowSetCover && item.type === 'file'" @click.stop>
                                    <i
                                        v-if="item.id === coverFileItemId"
                                        class="pi pi-star-fill"
                                        :title="t('files.cover')"
                                        :style="{ color: 'var(--accent)', fontSize: '10px' }"
                                    />
                                    <button
                                        v-else-if="canManage"
                                        type="button"
                                        class="cmd-list-cover-btn"
                                        :title="t('files.set_as_cover')"
                                        :aria-label="t('files.set_as_cover')"
                                        :style="{ background: 'transparent', border: 'none', color: 'var(--fg-mute)', cursor: 'pointer', padding: '2px', display: 'inline-flex' }"
                                        @click.stop="emit('setCover', item.id)"
                                    ><i class="pi pi-star" :style="{ fontSize: '11px' }" /></button>
                                </span>
                            </div>
                        </td>
                        <td class="cmd-mono" :style="{ padding: '8px 14px', color: 'var(--fg-dim)', fontSize: '11.5px' }">
                            {{ item.type === 'file' ? formatBytes(item.size) : '—' }}
                        </td>
                        <td :style="{ padding: '8px 14px', textAlign: 'right' }">
                            <ItemActionsMenu
                                v-if="canManage"
                                :item="item"
                                :download-url="item.type === 'file' ? workspaceUrl(`/entity-files/${item.id}/download`) : undefined"
                                variant="inline"
                                @open="openItem(item)"
                                @rename="startRename(item)"
                                @delete="confirmDelete(item)"
                                @details="openDetails(item)"
                                @copyLink="copyLink(item)"
                                @openNewTab="openInNewTab(item)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <UploadDialog
            v-model:open="uploadOpen"
            :upload-url="uploadUrl"
            :extra-data="extraUploadData"
            :max-file-size="50"
            :multiple="true"
            @all-complete="reload"
        />

        <CommandDialog
            v-model:visible="newFolderOpen"
            :title="t('files.new_folder')"
            width="380px"
        >
            <form @submit.prevent="submitNewFolder">
                <Field
                    v-model="newFolderName"
                    :label="t('files.folder_name')"
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
                    <template #icon><Icon name="disk" :size="12" /></template>
                    {{ t('files.new_folder') }}
                </CmdButton>
            </template>
        </CommandDialog>

        <!-- Unified media lightbox + detail/text previews (shared components). -->
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
            :download-url="textItem ? workspaceUrl(`/entity-files/${textItem.id}/download`) : undefined"
            @close="textItem = null"
        />
    </div>
</template>

<style scoped>
.cmd-file-card:hover {
    border-color: var(--accent-border) !important;
}
.cmd-file-actions {
    opacity: 0;
    transition: opacity 0.12s;
}
.cmd-file-card:hover .cmd-file-actions,
.cmd-file-card:focus-within .cmd-file-actions {
    opacity: 1;
}
.cmd-cover-btn {
    opacity: 0;
    transition: opacity 0.12s;
}
.cmd-file-card:hover .cmd-cover-btn,
.cmd-file-card:focus-within .cmd-cover-btn {
    opacity: 1;
}
.cmd-file-row:hover {
    background: var(--row-hover) !important;
}
.cmd-list-cover-btn {
    opacity: 0;
    transition: opacity 0.12s;
}
.cmd-file-row:hover .cmd-list-cover-btn,
.cmd-file-row:focus-within .cmd-list-cover-btn {
    opacity: 1;
}
</style>
