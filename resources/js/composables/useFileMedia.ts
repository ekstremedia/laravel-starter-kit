import { computed, onUnmounted, ref, type Ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useToast } from 'primevue/usetoast';
import { useCustomer } from '@/composables/useCustomer';
import type { LightboxItem } from '@/types/lightbox';

/**
 * The subset of a FileItemResource the preview/lightbox logic needs. Both the
 * personal Files browser and the polymorphic entity-file browser hand rows of
 * this shape, so the open/lightbox/details/text behaviour lives here once.
 */
export interface MediaFileRow {
    id: number;
    type: 'folder' | 'file';
    name: string;
    mime_type: string | null;
    is_image?: boolean;
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
}

export interface UseFileMediaOptions<T extends MediaFileRow> {
    /** Reactive list of rows currently shown (already merged with live patches). */
    items: Ref<T[]>;
    /** Download URL builder — differs per surface (/files vs /entity-files). */
    downloadUrl: (item: T) => string;
    /** Folder navigation handler. */
    onFolder?: (item: T) => void;
    /** Doc-preview handler; return true when it took over (a modal opened). */
    onDocPreview?: (item: T) => boolean;
}

/**
 * Shared file open/preview behaviour: routes folders, images, videos, audio,
 * text/markdown and documents to the right surface, and owns the lightbox,
 * details and text-preview state. The details and text endpoints are generic
 * (`/files/{id}/…`) and authorize by FileItem ownership, so they work for any
 * owner — personal or entity.
 */
export function useFileMedia<T extends MediaFileRow>(opts: UseFileMediaOptions<T>) {
    const { t } = useI18n();
    const { customerUrl } = useCustomer();
    const toast = useToast();

    const lightboxIndex = ref<number | null>(null);
    const detailsItem = ref<T | null>(null);
    const textItem = ref<T | null>(null);

    function isLightboxMedia(i: T): boolean {
        if (i.type !== 'file') return false;
        if (i.is_image) return true;
        if (i.is_video) return !!(i.video_ready && i.video_web_url);
        if (i.is_audio) return true;
        return false;
    }

    const mediaItems = computed(() => opts.items.value.filter(isLightboxMedia));

    const lightboxItems = computed<LightboxItem[]>(() =>
        mediaItems.value.map((i) => {
            const downloadUrl = opts.downloadUrl(i);
            if (i.is_video) {
                return {
                    id: i.id,
                    kind: 'video' as const,
                    src: i.video_poster_url ?? i.thumbnail_url ?? '',
                    videoSrc: i.video_web_url ?? undefined,
                    poster: i.video_poster_url ?? i.thumbnail_url ?? undefined,
                    alt: i.name,
                    canZoom: false,
                    downloadUrl,
                    mime: i.mime_type ?? undefined,
                };
            }
            if (i.is_audio) {
                return {
                    id: i.id,
                    kind: 'audio' as const,
                    src: i.thumbnail_url ?? '',
                    audioSrc: i.original_url ?? undefined,
                    poster: i.thumbnail_url ?? undefined,
                    alt: i.name,
                    canZoom: false,
                    downloadUrl,
                    mime: i.mime_type ?? undefined,
                };
            }
            return {
                id: i.id,
                kind: 'image' as const,
                src: i.preview_url ?? i.original_url ?? '',
                zoomSrc: i.available_sizes?.large?.url ?? i.available_sizes?.xlarge?.url ?? i.preview_url ?? undefined,
                // For RAW the "original" is undisplayable, so zoom tops out at the
                // largest generated size instead.
                originalSrc: i.is_image && i.mime_type?.startsWith('image/') ? (i.original_url ?? undefined) : undefined,
                alt: i.name,
                canZoom: true,
                downloadUrl,
                mime: i.mime_type ?? undefined,
            };
        }),
    );

    /** Open a row in whatever surface fits its type. Returns nothing. */
    function openFile(item: T): void {
        if (item.type === 'folder') {
            opts.onFolder?.(item);
            return;
        }
        if (isLightboxMedia(item)) {
            const idx = mediaItems.value.findIndex((i) => i.id === item.id);
            if (idx >= 0) lightboxIndex.value = idx;
            return;
        }
        if (item.is_video && item.video_processing) return; // still transcoding
        if (item.has_doc_preview && opts.onDocPreview?.(item)) return;
        if (item.is_text) {
            textItem.value = item;
            return;
        }
        if (item.preview_processing) return;
        window.location.href = opts.downloadUrl(item);
    }

    function openDetails(item: T): void {
        if (item.type !== 'file') return;
        detailsItem.value = item;
    }

    /** Used from the lightbox header slot, which carries only the id. */
    function openDetailsById(id: string | number): void {
        const found = opts.items.value.find((i) => i.id === Number(id));
        if (found) detailsItem.value = found;
    }

    function openInNewTab(item: T): void {
        if (item.type !== 'file') return;
        const url = item.is_image && item.original_url ? item.original_url : opts.downloadUrl(item);
        window.open(url, '_blank', 'noopener');
    }

    async function copyLink(item: T): Promise<void> {
        if (item.type !== 'file') return;
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
            if (!res.ok) throw new Error('signed link failed');
            const data = await res.json();
            await navigator.clipboard?.writeText(data.url).catch(() => undefined);
            toast.add({ severity: 'success', summary: t('files.copy_link'), detail: t('files.link_copied'), life: 3000 });
        } catch {
            toast.add({ severity: 'error', summary: t('files.copy_link'), detail: t('files.share_failed'), life: 4000 });
        }
    }

    /** Clear all preview surfaces (e.g. on Escape). */
    function closeAll(): void {
        lightboxIndex.value = null;
        detailsItem.value = null;
        textItem.value = null;
    }

    // Close every overlay before an Inertia visit so a teleported lightbox/
    // dialog is never torn down mid-patch during the page swap (the Vue
    // "Cannot set properties of null (setting '__vnode')" teleport race).
    const stopNavListener = router.on('before', () => closeAll());
    onUnmounted(() => stopNavListener());

    return {
        lightboxIndex,
        detailsItem,
        textItem,
        mediaItems,
        lightboxItems,
        isLightboxMedia,
        openFile,
        openDetails,
        openDetailsById,
        openInNewTab,
        copyLink,
        closeAll,
    };
}
