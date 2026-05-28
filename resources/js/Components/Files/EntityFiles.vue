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
import { humanBytes as formatBytes } from '@/utils/bytes';
import { useCustomer } from '@/composables/useCustomer';

export interface FileRow {
    id: number;
    uuid: string;
    type: 'folder' | 'file';
    name: string;
    mime_type: string | null;
    size: number;
    parent_id: number | null;
    is_image: boolean;
    thumbnail_url: string | null;
    preview_url: string | null;
    original_url: string | null;
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
        usage: { used_bytes: number; quota_bytes: number | null; percent: number };
        canManage: boolean;
        // Builds the Inertia URL for navigating into a folder (or root when null).
        folderUrl: (folderId: number | null) => string;
    }>(),
    {},
);

const { t } = useI18n();
const { customerUrl } = useCustomer();
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

const uploadUrl = computed(() => customerUrl('/entity-files'));
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

function openItem(item: FileRow) {
    if (renamingId.value !== null) return;
    if (item.type === 'folder') {
        router.visit(props.folderUrl(item.id));
        return;
    }
    window.location.href = customerUrl(`/entity-files/${item.id}/download`);
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
        customerUrl('/entity-files/folder'),
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
    router.patch(customerUrl(`/entity-files/${item.id}`), { name }, { preserveScroll: true });
}

function confirmDelete(item: FileRow) {
    confirm.require({
        group: confirmGroup.value,
        message: t('files.confirm_delete', { name: item.name }),
        header: t('assets.delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('assets.delete'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(customerUrl(`/entity-files/${item.id}`), { preserveScroll: true }),
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
                    <span>{{ t('assets.root') }}</span>
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
                        :title="t('assets.view_grid')"
                        :aria-label="t('assets.view_grid')"
                        :style="{ background: viewMode === 'grid' ? 'var(--accent-soft)' : 'transparent', color: viewMode === 'grid' ? 'var(--accent)' : 'var(--fg-mute)', border: 'none', padding: '5px 8px', cursor: 'pointer' }"
                        @click="setViewMode('grid')"
                    ><i class="pi pi-th-large" :style="{ fontSize: '11px' }" /></button>
                    <button
                        type="button"
                        :title="t('assets.view_list')"
                        :aria-label="t('assets.view_list')"
                        :style="{ background: viewMode === 'list' ? 'var(--accent-soft)' : 'transparent', color: viewMode === 'list' ? 'var(--accent)' : 'var(--fg-mute)', border: 'none', padding: '5px 8px', cursor: 'pointer' }"
                        @click="setViewMode('list')"
                    ><i class="pi pi-list" :style="{ fontSize: '11px' }" /></button>
                </div>
                <CmdButton v-if="canManage" variant="ghost" size="sm" @click="createFolder">
                    <template #icon><i class="pi pi-folder-plus" :style="{ fontSize: '11px' }" /></template>
                    {{ t('assets.new_folder') }}
                </CmdButton>
                <CmdButton v-if="canManage" variant="primary" size="sm" @click="uploadOpen = true">
                    <template #icon><i class="pi pi-upload" :style="{ fontSize: '11px' }" /></template>
                    {{ t('assets.upload') }}
                </CmdButton>
            </div>
        </div>

        <FilesUsageBar :used-bytes="usage.used_bytes" :quota-bytes="usage.quota_bytes" />

        <!-- Empty state -->
        <div
            v-if="items.length === 0"
            :style="{ border: '1px dashed var(--border)', background: 'var(--panel)', borderRadius: '6px', padding: '56px 16px', textAlign: 'center', color: 'var(--fg-mute)' }"
        >
            <i class="pi pi-folder-open" :style="{ fontSize: '28px' }" />
            <p :style="{ marginTop: '10px', fontSize: '12px' }">{{ t('assets.empty_documents') }}</p>
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
                        :download-url="item.type === 'file' ? customerUrl(`/entity-files/${item.id}/download`) : undefined"
                        variant="overlay"
                        @open="openItem(item)"
                        @rename="startRename(item)"
                        @delete="confirmDelete(item)"
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
                        >{{ t('assets.name') }}</th>
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
                            </div>
                        </td>
                        <td class="cmd-mono" :style="{ padding: '8px 14px', color: 'var(--fg-dim)', fontSize: '11.5px' }">
                            {{ item.type === 'file' ? formatBytes(item.size) : '—' }}
                        </td>
                        <td :style="{ padding: '8px 14px', textAlign: 'right' }">
                            <ItemActionsMenu
                                v-if="canManage"
                                :item="item"
                                :download-url="item.type === 'file' ? customerUrl(`/entity-files/${item.id}/download`) : undefined"
                                variant="inline"
                                @open="openItem(item)"
                                @rename="startRename(item)"
                                @delete="confirmDelete(item)"
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
            :title="t('assets.new_folder')"
            width="380px"
        >
            <form @submit.prevent="submitNewFolder">
                <Field
                    v-model="newFolderName"
                    :label="t('assets.folder_name')"
                    :placeholder="t('assets.new_folder')"
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
                    {{ t('assets.new_folder') }}
                </CmdButton>
            </template>
        </CommandDialog>
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
.cmd-file-row:hover {
    background: var(--row-hover) !important;
}
</style>
