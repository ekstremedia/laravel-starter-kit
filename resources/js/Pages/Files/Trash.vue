<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ConfirmDialog from 'primevue/confirmdialog';
import { useConfirm } from 'primevue/useconfirm';
import AppLayout from '@/Layouts/CommandLayout.vue';
import { useWorkspace } from '@/composables/useWorkspace';

interface FileItem {
    id: number;
    name: string;
    type: 'folder' | 'file';
    mime_type: string | null;
    size: number;
    thumbnail_url: string | null;
    is_image: boolean;
    updated_at: string | null;
}

interface PageData {
    items: { data: FileItem[] };
    retention_days: number;
}

const props = defineProps<PageData>();
const { t, locale } = useI18n();
const { workspaceUrl } = useWorkspace();
const confirm = useConfirm();

function restore(id: number) {
    router.post(workspaceUrl(`/files/trash/${id}/restore`), {}, { preserveScroll: true });
}

function forceDelete(item: FileItem) {
    confirm.require({
        group: 'files-trash',
        message: t('files.confirm_delete_forever', { name: item.name }),
        header: t('files.delete_forever'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('files.delete_forever'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(workspaceUrl(`/files/trash/${item.id}`), { preserveScroll: true }),
    });
}

function emptyTrash() {
    confirm.require({
        group: 'files-trash',
        message: t('files.confirm_empty_trash'),
        header: t('files.empty_trash'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('files.empty_trash'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(workspaceUrl('/files/trash'), { preserveScroll: true }),
    });
}

function formatBytes(n: number): string {
    if (!n) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0, v = n;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return `${v.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

function iconFor(item: FileItem) {
    if (item.type === 'folder') return 'pi-folder';
    if (item.is_image) return 'pi-image';
    if (item.mime_type === 'application/pdf') return 'pi-file-pdf';
    if (item.mime_type?.startsWith('video/')) return 'pi-video';
    return 'pi-file';
}
</script>

<template>
    <AppLayout>
        <Head :title="t('files.trash')" />
        <ConfirmDialog group="files-trash" />
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <div class="mb-6 flex items-center gap-2 text-sm">
                <Link :href="workspaceUrl('/files')" class="text-indigo-600 hover:underline dark:text-indigo-400">
                    {{ t('files.root') }}
                </Link>
                <i class="pi pi-chevron-right text-xs text-slate-400" />
                <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('files.trash') }}</span>
            </div>

            <div class="mb-6 flex items-start justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-500/10 dark:text-amber-200">
                <div class="flex items-start gap-2">
                    <i class="pi pi-info-circle mt-0.5" />
                    <p>{{ t('files.trash_desc', { days: props.retention_days }) }}</p>
                </div>
                <button
                    v-if="props.items.data.length"
                    type="button"
                    class="rounded-md bg-rose-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-rose-500"
                    @click="emptyTrash"
                >
                    <i class="pi pi-trash mr-1" />
                    {{ t('files.empty_trash') }}
                </button>
            </div>

            <div
                v-if="props.items.data.length === 0"
                class="rounded-lg border border-dashed border-slate-300 bg-white py-24 text-center dark:border-dark-700 dark:bg-dark-900"
            >
                <i class="pi pi-inbox text-4xl text-slate-400" />
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">{{ t('files.trash_empty') }}</p>
            </div>

            <TransitionGroup
                v-else
                tag="div"
                name="trash-list"
                class="divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white dark:divide-dark-700 dark:border-dark-700 dark:bg-dark-900"
            >
                <div
                    v-for="item in props.items.data"
                    :key="item.id"
                    class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-dark-800"
                >
                    <div class="flex h-10 w-10 items-center justify-center overflow-hidden rounded bg-slate-100 dark:bg-dark-800">
                        <img v-if="item.thumbnail_url" :src="item.thumbnail_url" :alt="item.name" class="h-full w-full object-cover" loading="lazy" />
                        <i v-else :class="`pi ${iconFor(item)} text-slate-400`" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="truncate text-sm text-slate-700 dark:text-slate-200">{{ item.name }}</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">
                            {{ item.type === 'file' ? formatBytes(item.size) : '—' }}
                            <span v-if="item.updated_at">· {{ t('files.deleted_on', { when: new Date(item.updated_at).toLocaleDateString(locale) }) }}</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-2 py-1 text-xs text-slate-700 hover:bg-slate-50 dark:border-dark-700 dark:text-slate-200 dark:hover:bg-dark-800"
                        @click="restore(item.id)"
                    >
                        <i class="pi pi-replay mr-1" />
                        {{ t('files.restore') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-rose-600/80 px-2 py-1 text-xs text-white hover:bg-rose-600"
                        @click="forceDelete(item)"
                    >
                        <i class="pi pi-trash mr-1" />
                        {{ t('files.delete_forever') }}
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </AppLayout>
</template>

<style scoped>
.trash-list-enter-active,
.trash-list-leave-active {
    transition: all 280ms ease;
}
.trash-list-enter-from {
    opacity: 0;
    transform: translateY(-6px);
}
.trash-list-leave-to {
    opacity: 0;
    transform: translateX(12px);
}
.trash-list-leave-active {
    position: absolute;
    width: 100%;
}
</style>
