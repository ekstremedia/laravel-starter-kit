<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Menu from 'primevue/menu';

interface FileItemLite {
    id: number;
    type: 'folder' | 'file';
    shared_to_company?: boolean;
}

const props = withDefaults(defineProps<{
    item: FileItemLite;
    downloadUrl?: string;
    variant?: 'overlay' | 'inline';
    // Feature flag: whether the surrounding page supports share-to-company.
    // Enabled only when the tenant has company_files_enabled and the user
    // has the `share files to company` permission — the parent resolves both.
    canShareToCompany?: boolean;
    // Per-item gates. Default on so existing (private) callers keep every
    // entry; the shared FileBrowser passes explicit values per scope so the
    // company surface can drop link-sharing and gate rename/delete on
    // `can_manage`.
    canRename?: boolean;
    canShareLink?: boolean;
    canDelete?: boolean;
    // The details (EXIF) and copy-link entries call ownership-scoped
    // `/files/{id}/…` endpoints, so the company surface turns them off for
    // files it doesn't own. Default on for the private surface.
    canDetails?: boolean;
    canCopyLink?: boolean;
}>(), {
    canRename: true,
    canShareLink: true,
    canDelete: true,
    canDetails: true,
    canCopyLink: true,
});

const emit = defineEmits<{
    open: [];
    rename: [];
    share: [];
    download: [];
    delete: [];
    shareToCompany: [];
    unshareFromCompany: [];
    details: [];
    copyLink: [];
    openNewTab: [];
}>();

const { t } = useI18n();
const menuRef = ref<InstanceType<typeof Menu> | null>(null);

const items = computed(() => {
    const out: Array<Record<string, unknown>> = [
        { label: t('files.open'), icon: 'pi pi-external-link', command: () => emit('open') },
    ];

    if (props.canRename) {
        out.push({ label: t('files.rename'), icon: 'pi pi-pencil', command: () => emit('rename') });
    }
    if (props.canShareLink) {
        out.push({ label: t('files.share'), icon: 'pi pi-share-alt', command: () => emit('share') });
    }

    if (props.item.type === 'file') {
        out.push({
            label: t('files.download'),
            icon: 'pi pi-download',
            url: props.downloadUrl,
            command: () => emit('download'),
        });
        out.push({
            label: t('files.open_new_tab'),
            icon: 'pi pi-external-link',
            command: () => emit('openNewTab'),
        });
        if (props.canCopyLink) {
            out.push({
                label: t('files.copy_link'),
                icon: 'pi pi-link',
                command: () => emit('copyLink'),
            });
        }
        if (props.canDetails) {
            out.push({
                label: t('files.details.title'),
                icon: 'pi pi-info-circle',
                command: () => emit('details'),
            });
        }
    }

    // Share-to-company works on both files (one link) and folders
    // (recursive mirror into a matching company folder tree). Only
    // surface "Unshare" when the item is already linked; otherwise
    // "Share" is the only meaningful action. Folders don't carry the
    // `shared_to_company` flag today, so they always get the share
    // entry — sharing is idempotent and picks up any files added since.
    if (props.canShareToCompany) {
        if (props.item.shared_to_company) {
            out.push({
                label: t('files.unshare_from_company'),
                icon: 'pi pi-link',
                command: () => emit('unshareFromCompany'),
            });
        } else {
            out.push({
                label: t('files.share_to_company'),
                icon: 'pi pi-users',
                command: () => emit('shareToCompany'),
            });
        }
    }

    if (props.canDelete) {
        out.push({ separator: true });
        out.push({
            label: t('files.delete'),
            icon: 'pi pi-trash',
            // PrimeVue Menu styles items individually — inject a class to tint the row red.
            class: 'file-action-danger',
            command: () => emit('delete'),
        });
    }

    return out;
});

function toggle(event: MouseEvent) {
    event.stopPropagation();
    menuRef.value?.toggle(event);
}

const triggerStyle = computed(() => props.variant === 'inline'
    ? {
        background: 'transparent',
        border: '1px solid transparent',
        color: 'var(--fg-mute)',
        borderRadius: '4px',
        padding: '4px 6px',
        cursor: 'pointer',
        fontFamily: 'inherit',
    }
    : {
        background: 'var(--overlay)',
        border: 'none',
        color: 'var(--fg-inverse)',
        borderRadius: '9999px',
        padding: '5px 7px',
        cursor: 'pointer',
        fontFamily: 'inherit',
    });
</script>

<template>
    <span :style="{ display: 'inline-flex' }">
        <button
            type="button"
            class="cmd-file-menu-trigger"
            :style="triggerStyle"
            :title="t('common.actions')"
            :aria-label="t('common.actions')"
            @click.stop="toggle"
        >
            <i class="pi pi-ellipsis-v" />
        </button>
        <Menu ref="menuRef" :model="items" :popup="true" />
    </span>
</template>

<style>
.cmd-file-menu-trigger:hover {
    background: var(--row-hover) !important;
    color: var(--fg) !important;
}
.file-action-danger .p-menuitem-link,
.file-action-danger .p-menuitem-icon,
.file-action-danger .p-menuitem-text {
    color: var(--danger);
}
.file-action-danger .p-menuitem-link:hover {
    background-color: rgba(255, 138, 138, 0.12);
}
</style>
