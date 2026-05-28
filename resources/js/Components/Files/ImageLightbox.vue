<script setup lang="ts">
import type { LightboxItem } from '@/types/lightbox';
import { Check, ChevronLeft, ChevronRight, Circle, Download, Grid3x3, Loader2, Maximize, Pause, Play, Square, X } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
    defineProps<{
        /** Currently open index, or null when closed. */
        modelValue: number | null;
        items: LightboxItem[];
    }>(),
    {},
);

const emit = defineEmits<{
    'update:modelValue': [index: number | null];
    'original-toggled': [id: string | number, active: boolean];
}>();

const { t } = useI18n();

// ── Constants ─────────────────────────────────────────────────────
const MIN_ZOOM = 1;
const MAX_ZOOM = 10;

// ── State ──────────────────────────────────────────────────────────
const isOpen = computed(() => props.modelValue !== null);
const currentIndex = computed(() => props.modelValue ?? 0);
const currentItem = computed(() => props.items[currentIndex.value] ?? null);

// ── Media kind (image | video | audio) ─────────────────────────────
const currentKind = computed(() => currentItem.value?.kind ?? 'image');
const isImageKind = computed(() => currentKind.value === 'image');
const isVideoKind = computed(() => currentKind.value === 'video');
const isAudioKind = computed(() => currentKind.value === 'audio');

// Playback element for video/audio + explicit transport controls.
const mediaPlayerRef = ref<HTMLVideoElement | HTMLAudioElement | null>(null);
const isPlaying = ref(false);

function onMediaPlay() {
    isPlaying.value = true;
}
function onMediaPause() {
    isPlaying.value = false;
}
function togglePlay() {
    const el = mediaPlayerRef.value;
    if (!el) return;
    if (el.paused) el.play?.()?.catch?.(() => undefined);
    else el.pause?.();
}
function stopMedia() {
    const el = mediaPlayerRef.value;
    if (!el) return;
    el.pause?.();
    el.currentTime = 0;
}

// Auto-play (muted, so browsers allow it) when a video/audio item opens or is
// navigated to. The native controls let the user unmute immediately.
watch([currentIndex, isOpen], () => {
    if (!isOpen.value) return;
    if (isImageKind.value) return;
    isPlaying.value = false;
    setTimeout(() => {
        const el = mediaPlayerRef.value;
        if (!el) return;
        if (el instanceof HTMLVideoElement) el.muted = true;
        el.play?.()?.catch?.(() => undefined);
    }, 60);
});

function downloadCurrent() {
    const url = currentItem.value?.downloadUrl;
    if (!url) return;
    const a = document.createElement('a');
    a.href = url;
    a.rel = 'noopener';
    a.click();
}

const lightboxImageLoading = ref(false);
const lightboxContainerRef = ref<HTMLElement | null>(null);

// Zoom & pan — continuous scale from 1.0 to 4.0
const zoomScale = ref(MIN_ZOOM);
const isZoomed = computed(() => zoomScale.value > 1.01);
const panOffset = ref({ x: 0, y: 0 });
const currentZoomScale = computed(() => zoomScale.value);

// Background for transparent images
type ImageBackground = 'transparent' | 'black' | 'white';
const imageBackground = ref<ImageBackground>('transparent');

const canHaveTransparency = computed(() => currentItem.value?.canHaveTransparency ?? false);

const imageBackgroundClass = computed(() => {
    if (!canHaveTransparency.value) return 'bg-black';
    switch (imageBackground.value) {
        case 'white':
            return 'bg-white';
        case 'black':
            return 'bg-black';
        default:
            return 'bg-[url("data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2220%22%20height%3D%2220%22%3E%3Crect%20width%3D%2210%22%20height%3D%2210%22%20fill%3D%22%23333%22%2F%3E%3Crect%20x%3D%2210%22%20y%3D%2210%22%20width%3D%2210%22%20height%3D%2210%22%20fill%3D%22%23333%22%2F%3E%3Crect%20x%3D%2210%22%20width%3D%2210%22%20height%3D%2210%22%20fill%3D%22%23222%22%2F%3E%3Crect%20y%3D%2210%22%20width%3D%2210%22%20height%3D%2210%22%20fill%3D%22%23222%22%2F%3E%3C%2Fsvg%3E")]';
    }
});

const canZoom = computed(() => currentItem.value?.canZoom ?? false);

// Preload zoomSrc in the background so it's cached when user clicks to zoom
const preloadedUrls = new Set<string>();

function preloadZoomSrc() {
    const item = currentItem.value;
    if (!item) return;
    for (const url of [item.zoomSrc]) {
        if (!url || url === item.src || preloadedUrls.has(url)) continue;
        const img = new Image();
        img.src = url;
        preloadedUrls.add(url);
    }
}

watch(currentItem, () => preloadZoomSrc());
watch(isOpen, (open) => {
    if (open) preloadZoomSrc();
});

// Original image loading — toggleable, cached by browser after first load
const originalCachedIds = new Set<string | number>();
const originalLoading = ref(false);
const originalActive = ref(false);

// Reset original state only when navigating to a different item (index change),
// not when the parent mutates item props (e.g. changing src after original-toggled).
watch(currentIndex, () => {
    const item = currentItem.value;
    originalLoading.value = false;
    originalActive.value = item ? originalCachedIds.has(item.id) : false;
});

const hasOriginal = computed(() => !!currentItem.value?.originalSrc);

const originalTooltip = computed(() => {
    const item = currentItem.value;
    if (!item) return '';
    let tip = t('lightbox.loadOriginalTooltip');
    if (item.zoomResolution && item.originalResolution) {
        tip += `\n${t('lightbox.currentResolution', { resolution: item.zoomResolution })}\n${t('lightbox.originalResolution', { resolution: item.originalResolution })}`;
    }
    return tip;
});

function toggleOriginal() {
    const item = currentItem.value;
    if (!item?.originalSrc) return;

    // If already cached, just toggle on/off
    if (originalCachedIds.has(item.id)) {
        originalActive.value = !originalActive.value;
        emit('original-toggled', item.id, originalActive.value);
        return;
    }

    originalLoading.value = true;
    const img = new Image();
    img.onload = () => {
        originalCachedIds.add(item.id);
        originalActive.value = true;
        originalLoading.value = false;
        emit('original-toggled', item.id, true);
    };
    img.onerror = () => {
        originalLoading.value = false;
    };
    img.src = item.originalSrc;
}

const displaySrc = computed(() => {
    if (!currentItem.value) return '';
    if (originalActive.value && currentItem.value.originalSrc) {
        return currentItem.value.originalSrc;
    }
    if (isZoomed.value) {
        return currentItem.value.zoomSrc || currentItem.value.src;
    }
    return currentItem.value.src;
});

const displaySrcset = computed(() => {
    if (!currentItem.value || isZoomed.value || originalActive.value) return '';
    return currentItem.value.srcset ?? '';
});

// ── Zoom helpers ───────────────────────────────────────────────────

function getMaxPan(scale: number): number {
    if (scale <= 1) return 0;
    return (1 - 1 / scale) * 50;
}

function clampPan(pan: { x: number; y: number }, scale: number): { x: number; y: number } {
    const maxPan = getMaxPan(scale);
    return {
        x: Math.max(-maxPan, Math.min(maxPan, pan.x)),
        y: Math.max(-maxPan, Math.min(maxPan, pan.y)),
    };
}

function zoomToPoint(oldScale: number, newScale: number, clientX: number, clientY: number) {
    const container = lightboxContainerRef.value;
    if (!container) return;
    const rect = container.getBoundingClientRect();
    const focalX = (clientX - rect.left) / rect.width - 0.5;
    const focalY = (clientY - rect.top) / rect.height - 0.5;

    const imageX = focalX / oldScale - panOffset.value.x / 100;
    const imageY = focalY / oldScale - panOffset.value.y / 100;

    const newPan = {
        x: (focalX / newScale - imageX) * 100,
        y: (focalY / newScale - imageY) * 100,
    };
    panOffset.value = clampPan(newPan, newScale);
}

// ── Zoom indicator display ─────────────────────────────────────────

const zoomIndicatorText = computed(() => {
    const s = zoomScale.value;
    if (Math.abs(s - Math.round(s)) < 0.05) {
        return Math.round(s) + '\u00d7';
    }
    return s.toFixed(1) + '\u00d7';
});

// ── Actions ────────────────────────────────────────────────────────

function onBackdropClick() {
    if (recentlyPinched.value) return;
    if (isZoomed.value) resetZoom();
    else close();
}

function close() {
    resetZoom();
    lightboxImageLoading.value = false;
    emit('update:modelValue', null);
    document.body.style.overflow = '';
}

function goTo(index: number) {
    resetZoom();
    lightboxImageLoading.value = false;
    emit('update:modelValue', index);
}

function prev() {
    if (currentIndex.value > 0) goTo(currentIndex.value - 1);
}

function next() {
    if (currentIndex.value < props.items.length - 1) goTo(currentIndex.value + 1);
}

function resetZoom() {
    zoomScale.value = MIN_ZOOM;
    panOffset.value = { x: 0, y: 0 };
}

// ── Click/tap to zoom (cycles 1x -> 2x -> 4x -> 1x) ──────────────

function toggleZoom(e: MouseEvent | TouchEvent) {
    if (!canZoom.value || recentlyPinched.value) return;

    const CLICK_LEVELS = [1, 2, 4, 8];
    const idx = CLICK_LEVELS.findIndex((l) => zoomScale.value < l - 0.1);
    const nextLevel = idx === -1 ? 1 : CLICK_LEVELS[idx];

    if (nextLevel === 1) {
        resetZoom();
    } else {
        const oldScale = zoomScale.value;
        zoomScale.value = nextLevel;
        if (e instanceof MouseEvent) {
            updatePanFromMouse(e);
        } else {
            const touch = (e as TouchEvent).changedTouches?.[0];
            if (touch) {
                zoomToPoint(oldScale, nextLevel, touch.clientX, touch.clientY);
            } else {
                panOffset.value = { x: 0, y: 0 };
            }
        }
    }
}

// ── Mouse pan (desktop) ────────────────────────────────────────────

function onMouseMoveZoomed(e: MouseEvent) {
    if (!isZoomed.value || isPinching.value) return;
    updatePanFromMouse(e);
}

function updatePanFromMouse(e: MouseEvent) {
    const container = lightboxContainerRef.value;
    if (!container) return;

    const rect = container.getBoundingClientRect();
    const mouseXPercent = (e.clientX - rect.left) / rect.width;
    const mouseYPercent = (e.clientY - rect.top) / rect.height;

    const maxPan = getMaxPan(zoomScale.value);
    const normalizedX = (mouseXPercent - 0.5) * 2;
    const normalizedY = (mouseYPercent - 0.5) * 2;

    panOffset.value = {
        x: -normalizedX * maxPan,
        y: -normalizedY * maxPan,
    };
}

// ── Pinch to zoom (touch) ──────────────────────────────────────────

let pinchStartDistance = 0;
let pinchStartScale = 1;
let pinchStartPan = { x: 0, y: 0 };
const isPinching = ref(false);
const recentlyPinched = ref(false);
let recentlyPinchedTimer: ReturnType<typeof setTimeout> | null = null;

function getTouchDistance(t0: Touch, t1: Touch): number {
    const dx = t0.clientX - t1.clientX;
    const dy = t0.clientY - t1.clientY;
    return Math.sqrt(dx * dx + dy * dy);
}

function getTouchMidpoint(t0: Touch, t1: Touch) {
    return { x: (t0.clientX + t1.clientX) / 2, y: (t0.clientY + t1.clientY) / 2 };
}

// ── Touch handling ─────────────────────────────────────────────────

const touchStart = ref<{ x: number; y: number } | null>(null);
const touchPanStart = ref({ x: 0, y: 0 });
const isDragging = ref(false);

function onTouchStart(e: TouchEvent) {
    if (e.touches.length === 2 && canZoom.value) {
        // Start pinch
        isPinching.value = true;
        pinchStartDistance = getTouchDistance(e.touches[0], e.touches[1]);
        pinchStartScale = zoomScale.value;
        pinchStartPan = { ...panOffset.value };
        e.preventDefault();
        return;
    }

    if (e.touches.length === 1 && isZoomed.value && !isPinching.value) {
        // Start single-finger pan
        touchStart.value = { x: e.touches[0].clientX, y: e.touches[0].clientY };
        touchPanStart.value = { ...panOffset.value };
        isDragging.value = false;
    }
}

function onTouchMove(e: TouchEvent) {
    if (e.touches.length === 2 && isPinching.value && canZoom.value) {
        e.preventDefault();
        const currentDistance = getTouchDistance(e.touches[0], e.touches[1]);
        const ratio = currentDistance / pinchStartDistance;
        const newScale = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, pinchStartScale * ratio));

        zoomScale.value = newScale;

        // Keep midpoint fixed: adjust pan based on scale change from pinch start
        const currentMidpoint = getTouchMidpoint(e.touches[0], e.touches[1]);
        zoomToPointFromPinch(pinchStartScale, newScale, pinchStartPan, currentMidpoint.x, currentMidpoint.y);
        return;
    }

    if (e.touches.length === 1 && isZoomed.value && !isPinching.value && touchStart.value) {
        const dx = e.touches[0].clientX - touchStart.value.x;
        const dy = e.touches[0].clientY - touchStart.value.y;

        if (!isDragging.value && Math.abs(dx) < 5 && Math.abs(dy) < 5) return;
        isDragging.value = true;
        e.preventDefault();

        const container = lightboxContainerRef.value;
        if (!container) return;

        const rect = container.getBoundingClientRect();
        // Divide by zoomScale so the image follows the finger 1:1
        const scale = zoomScale.value;
        const newX = touchPanStart.value.x + ((dx / rect.width) * 100) / scale;
        const newY = touchPanStart.value.y + ((dy / rect.height) * 100) / scale;

        panOffset.value = clampPan({ x: newX, y: newY }, zoomScale.value);
    }
}

function zoomToPointFromPinch(startScale: number, newScale: number, startPan: { x: number; y: number }, clientX: number, clientY: number) {
    const container = lightboxContainerRef.value;
    if (!container) return;
    const rect = container.getBoundingClientRect();
    const focalX = (clientX - rect.left) / rect.width - 0.5;
    const focalY = (clientY - rect.top) / rect.height - 0.5;

    const imageX = focalX / startScale - startPan.x / 100;
    const imageY = focalY / startScale - startPan.y / 100;

    const newPan = {
        x: (focalX / newScale - imageX) * 100,
        y: (focalY / newScale - imageY) * 100,
    };
    panOffset.value = clampPan(newPan, newScale);
}

function onTouchEnd(e: TouchEvent) {
    if (isPinching.value) {
        if (e.touches.length < 2) {
            isPinching.value = false;

            // Snap to 1x if close
            if (zoomScale.value < 1.1) {
                resetZoom();
            }

            // If one finger remains, start pan mode from current position
            if (e.touches.length === 1 && isZoomed.value) {
                touchStart.value = { x: e.touches[0].clientX, y: e.touches[0].clientY };
                touchPanStart.value = { ...panOffset.value };
                isDragging.value = false;
            }

            // Prevent the subsequent tap from triggering toggleZoom
            recentlyPinched.value = true;
            if (recentlyPinchedTimer) clearTimeout(recentlyPinchedTimer);
            recentlyPinchedTimer = setTimeout(() => {
                recentlyPinched.value = false;
            }, 400);
        }
        return;
    }

    if (e.touches.length === 0) {
        touchStart.value = null;
        isDragging.value = false;
    }
}

// ── Wheel zoom (desktop trackpad + mouse wheel) ────────────────────

function onWheel(e: WheelEvent) {
    if (!canZoom.value || !isOpen.value) return;
    e.preventDefault();

    const oldScale = zoomScale.value;
    const delta = -e.deltaY * (e.ctrlKey ? 0.01 : 0.005);
    const newScale = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, oldScale * (1 + delta)));

    if (newScale <= 1.02) {
        resetZoom();
        return;
    }

    zoomScale.value = newScale;
    zoomToPoint(oldScale, newScale, e.clientX, e.clientY);
}

function toggleFullscreen() {
    if (document.fullscreenElement) {
        document.exitFullscreen();
    } else {
        document.documentElement.requestFullscreen();
    }
}

function onImageLoad() {
    lightboxImageLoading.value = false;
}

// ── Keyboard ───────────────────────────────────────────────────────

function onKeydown(e: KeyboardEvent) {
    if (!isOpen.value) return;
    const key = e.key.toLowerCase();
    const tag = (e.target as HTMLElement | null)?.tagName;
    const inField = tag === 'INPUT' || tag === 'TEXTAREA';
    if (e.key === 'ArrowLeft') prev();
    else if (e.key === 'ArrowRight') next();
    else if (e.key === ' ' && !isImageKind.value && !inField) {
        e.preventDefault();
        togglePlay();
    } else if (key === 'f') toggleFullscreen();
    else if (key === 'o' && isImageKind.value) toggleOriginal();
    else if (key === '0' && isImageKind.value) toggleOriginal();
    else if (e.key === 'Escape') {
        if (isZoomed.value) resetZoom();
        else close();
    }
}

// ── Wheel event listener management ────────────────────────────────

function addWheelListener() {
    const container = lightboxContainerRef.value;
    if (container) {
        container.addEventListener('wheel', onWheel, { passive: false });
    }
}

function removeWheelListener() {
    const container = lightboxContainerRef.value;
    if (container) {
        container.removeEventListener('wheel', onWheel);
    }
}

// ── Lifecycle ──────────────────────────────────────────────────────

watch(isOpen, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) {
        // Wait for DOM update so the container ref is available
        requestAnimationFrame(() => addWheelListener());
    } else {
        removeWheelListener();
    }
});

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    if (isOpen.value) {
        requestAnimationFrame(() => addWheelListener());
    }
});
onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
    removeWheelListener();
    document.body.style.overflow = '';
    if (recentlyPinchedTimer) clearTimeout(recentlyPinchedTimer);
});

// ── Expose for parent components ───────────────────────────────────

// Use width/height sizing instead of transform: scale() so the browser
// (especially iPad Safari) rasterises at the displayed resolution instead
// of scaling up a low-res bitmap.  flex-shrink: 0 prevents the flex
// container from collapsing the oversized image back to 100%.
//
// Cap CSS sizing at MAX_CSS_ZOOM to avoid hitting Safari's GPU texture
// size limits (causes aspect-ratio distortion with large originals).
// Beyond the cap, an additional transform: scale() handles the rest.
const MAX_CSS_ZOOM = 5;

const zoomStyle = computed(() => {
    const scale = zoomScale.value;
    const { x, y } = panOffset.value;

    if (scale <= 1.01) {
        return {
            transform: 'none',
            willChange: 'auto' as const,
        };
    }

    const cssZoom = Math.min(scale, MAX_CSS_ZOOM);
    const extraScale = scale / cssZoom;

    return {
        width: `${cssZoom * 100}%`,
        height: `${cssZoom * 100}%`,
        maxWidth: 'none',
        maxHeight: 'none',
        flexShrink: 0,
        transform: extraScale > 1.01 ? `scale(${extraScale}) translate(${x}%, ${y}%)` : `translate(${x}%, ${y}%)`,
        willChange: 'transform' as const,
    };
});

defineExpose({
    zoomScale,
    isZoomed,
    currentZoomScale,
    panOffset,
    zoomStyle,
    toggleZoom,
    onMouseMoveZoomed,
    resetZoom,
    canZoom,
    lightboxImageLoading,
    onImageLoad,
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-300"
            leave-active-class="transition-opacity duration-300"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isOpen && currentItem"
                class="fixed inset-0 z-50 flex items-center justify-center transition-colors duration-200"
                :class="imageBackgroundClass"
                @click="onBackdropClick"
            >
                <!-- Header -->
                <Transition
                    enter-active-class="transition-opacity duration-200"
                    leave-active-class="transition-opacity duration-200"
                    enter-from-class="opacity-0"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-show="!isZoomed"
                        class="absolute top-0 right-0 left-0 z-20 flex items-center justify-between bg-gradient-to-b from-black/80 to-transparent p-4"
                    >
                        <span class="text-sm text-white/70"> {{ currentIndex + 1 }} / {{ items.length }} </span>
                        <div class="flex items-center gap-2">
                            <!-- Background toggle for transparent images -->
                            <template v-if="canHaveTransparency">
                                <button
                                    @click.stop="imageBackground = 'transparent'"
                                    :aria-label="t('lightbox.background_transparent')"
                                    :title="t('lightbox.background_transparent')"
                                    class="rounded-lg p-2 transition-colors"
                                    :class="
                                        imageBackground === 'transparent' ? 'cmd-lightbox-active' : 'bg-white/10 text-white hover:bg-white/20'
                                    "
                                >
                                    <Grid3x3 class="h-5 w-5" />
                                </button>
                                <button
                                    @click.stop="imageBackground = 'black'"
                                    :aria-label="t('lightbox.background_black')"
                                    :title="t('lightbox.background_black')"
                                    class="rounded-lg p-2 transition-colors"
                                    :class="imageBackground === 'black' ? 'cmd-lightbox-active' : 'bg-white/10 text-white hover:bg-white/20'"
                                >
                                    <Circle class="h-5 w-5 fill-black" />
                                </button>
                                <button
                                    @click.stop="imageBackground = 'white'"
                                    :aria-label="t('lightbox.background_white')"
                                    :title="t('lightbox.background_white')"
                                    class="rounded-lg p-2 transition-colors"
                                    :class="imageBackground === 'white' ? 'cmd-lightbox-active' : 'bg-white/10 text-white hover:bg-white/20'"
                                >
                                    <Circle class="h-5 w-5 fill-white" />
                                </button>
                                <div class="mx-1 h-6 w-px bg-white/20" />
                            </template>

                            <!-- Load original button -->
                            <button
                                v-if="hasOriginal"
                                :title="originalTooltip"
                                @click.stop="toggleOriginal"
                                class="flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm transition-colors"
                                :class="originalActive ? 'cmd-lightbox-active' : 'bg-white/10 text-white hover:bg-white/20'"
                                :disabled="originalLoading"
                            >
                                <Loader2 v-if="originalLoading" class="h-4 w-4 animate-spin" />
                                <Check v-else-if="originalActive" class="h-4 w-4" />
                                {{ t('lightbox.loadOriginal') }}
                            </button>

                            <!-- Transport controls for video / audio -->
                            <template v-if="!isImageKind">
                                <button
                                    @click.stop="togglePlay"
                                    :aria-label="isPlaying ? t('lightbox.pause') : t('lightbox.play')"
                                    :title="isPlaying ? t('lightbox.pause') : t('lightbox.play')"
                                    class="rounded-lg bg-white/10 p-2 text-white transition-colors hover:bg-white/20"
                                >
                                    <Pause v-if="isPlaying" class="h-5 w-5" />
                                    <Play v-else class="h-5 w-5" />
                                </button>
                                <button
                                    @click.stop="stopMedia"
                                    :aria-label="t('lightbox.stop')"
                                    :title="t('lightbox.stop')"
                                    class="rounded-lg bg-white/10 p-2 text-white transition-colors hover:bg-white/20"
                                >
                                    <Square class="h-5 w-5" />
                                </button>
                            </template>

                            <!-- Download (any kind that provides a target) -->
                            <button
                                v-if="currentItem.downloadUrl"
                                @click.stop="downloadCurrent"
                                :aria-label="t('lightbox.download')"
                                :title="t('lightbox.download')"
                                class="rounded-lg bg-white/10 p-2 text-white transition-colors hover:bg-white/20"
                            >
                                <Download class="h-5 w-5" />
                            </button>

                            <button
                                @click.stop="toggleFullscreen"
                                :aria-label="t('lightbox.fullscreen')"
                                :title="t('lightbox.fullscreen')"
                                class="rounded-lg bg-white/10 p-2 text-white transition-colors hover:bg-white/20"
                            >
                                <Maximize class="h-5 w-5" />
                            </button>

                            <!-- Slot for extra header actions (EXIF, details, etc.) -->
                            <slot name="header-actions" :item="currentItem" :index="currentIndex" />

                            <button
                                :aria-label="t('lightbox.close')"
                                :title="t('lightbox.close')"
                                class="rounded-lg bg-white/10 p-2 text-white transition-colors hover:bg-white/20"
                                @click.stop="close"
                            >
                                <X class="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                </Transition>

                <!-- Navigation arrows -->
                <Transition
                    enter-active-class="transition-opacity duration-200"
                    leave-active-class="transition-opacity duration-200"
                    enter-from-class="opacity-0"
                    leave-to-class="opacity-0"
                >
                    <button
                        v-if="currentIndex > 0 && !isZoomed"
                        :aria-label="t('lightbox.previous')"
                        :title="t('lightbox.previous')"
                        class="absolute top-1/2 left-4 z-20 -translate-y-1/2 rounded-full bg-black/50 p-3 text-white transition-colors hover:bg-black/70"
                        @click.stop="prev"
                    >
                        <ChevronLeft class="h-8 w-8" />
                    </button>
                </Transition>
                <Transition
                    enter-active-class="transition-opacity duration-200"
                    leave-active-class="transition-opacity duration-200"
                    enter-from-class="opacity-0"
                    leave-to-class="opacity-0"
                >
                    <button
                        v-if="currentIndex < items.length - 1 && !isZoomed"
                        :aria-label="t('lightbox.next')"
                        :title="t('lightbox.next')"
                        class="absolute top-1/2 right-4 z-20 -translate-y-1/2 rounded-full bg-black/50 p-3 text-white transition-colors hover:bg-black/70"
                        @click.stop="next"
                    >
                        <ChevronRight class="h-8 w-8" />
                    </button>
                </Transition>

                <!-- Content area -->
                <div
                    ref="lightboxContainerRef"
                    class="relative flex h-full w-full items-center justify-center overflow-hidden"
                    :class="isZoomed ? 'p-0' : 'px-6 pt-14 pb-20 sm:px-8'"
                    :style="{ touchAction: canZoom ? 'none' : 'auto' }"
                    @click.stop
                    @mousemove="onMouseMoveZoomed"
                    @touchstart="onTouchStart"
                    @touchmove="onTouchMove"
                    @touchend="onTouchEnd"
                >
                    <!-- Loading spinner -->
                    <Transition
                        enter-active-class="transition-opacity duration-200"
                        leave-active-class="transition-opacity duration-200"
                        enter-from-class="opacity-0"
                        leave-to-class="opacity-0"
                    >
                        <div v-if="lightboxImageLoading" class="absolute inset-0 z-30 flex items-center justify-center bg-black/50">
                            <div class="h-12 w-12 animate-spin rounded-full border-4 border-white/20 border-t-white"></div>
                        </div>
                    </Transition>

                    <!-- Media slot — parent can override for video/documents -->
                    <slot
                        name="media"
                        :item="currentItem"
                        :index="currentIndex"
                        :display-src="displaySrc"
                        :display-srcset="displaySrcset"
                        :zoom-style="zoomStyle"
                        :toggle-zoom="toggleZoom"
                        :on-image-load="onImageLoad"
                        :can-zoom="canZoom"
                        :zoom-scale="zoomScale"
                        :is-zoomed="isZoomed"
                    >
                        <!-- Default: image with zoom/pan -->
                        <img
                            v-if="isImageKind"
                            :src="displaySrc"
                            :srcset="displaySrcset"
                            sizes="100vw"
                            :alt="currentItem.alt || ''"
                            class="h-full w-full object-contain select-none"
                            :class="[
                                !canZoom ? 'cursor-default' : '',
                                canZoom && zoomScale < MAX_ZOOM - 0.1 ? 'cursor-zoom-in' : '',
                                canZoom && zoomScale >= MAX_ZOOM - 0.1 ? 'cursor-zoom-out' : '',
                            ]"
                            :style="zoomStyle"
                            @load="onImageLoad"
                            @click.stop="toggleZoom"
                            draggable="false"
                        />

                        <!-- Video -->
                        <video
                            v-else-if="isVideoKind"
                            ref="mediaPlayerRef"
                            :src="currentItem.videoSrc ?? currentItem.src"
                            :poster="currentItem.poster ?? undefined"
                            controls
                            playsinline
                            class="max-h-full max-w-full rounded-lg bg-black"
                            @play="onMediaPlay"
                            @pause="onMediaPause"
                            @click.stop
                        />

                        <!-- Audio -->
                        <div
                            v-else
                            class="flex w-full max-w-md flex-col items-center gap-6 px-6"
                            @click.stop
                        >
                            <div class="flex h-48 w-48 items-center justify-center overflow-hidden rounded-2xl border border-white/10 bg-white/5 shadow-2xl">
                                <img v-if="currentItem.poster" :src="currentItem.poster" :alt="currentItem.alt || ''" class="h-full w-full object-cover" />
                                <i v-else class="pi pi-volume-up text-5xl text-white/50" />
                            </div>
                            <p v-if="currentItem.alt" class="max-w-full truncate text-center font-medium text-white">{{ currentItem.alt }}</p>
                            <audio
                                ref="mediaPlayerRef"
                                :src="currentItem.audioSrc ?? currentItem.src"
                                controls
                                class="w-full"
                                @play="onMediaPlay"
                                @pause="onMediaPause"
                            />
                        </div>
                    </slot>

                    <!-- Status & hotkeys widget (inside content area, moves up when footer visible) -->
                </div>

                <!-- Status & hotkeys widget -->
                <div
                    class="pointer-events-none absolute left-1/2 z-30 -translate-x-1/2 rounded-xl border border-white/10 bg-black/85 px-2 py-2 text-xs shadow-lg backdrop-blur-sm transition-all duration-200"
                    :class="isZoomed ? 'bottom-4' : 'bottom-14'"
                >
                    <div class="flex items-center gap-2.5 text-white">
                        <!-- Zoom level -->
                        <span v-if="isZoomed" class="ml-1 font-semibold">{{ zoomIndicatorText }}</span>

                        <!-- Original status -->
                        <span v-if="hasOriginal" class="flex items-center gap-1" :class="originalActive ? 'text-purple-300' : 'text-white/80'">
                            <Check v-if="originalActive" class="h-3 w-3" />
                            <span>{{ t('lightbox.original') }}</span>
                        </span>

                        <span class="hidden items-center gap-1 lg:flex">
                            <span class="mx-0.5 h-3.5 w-px bg-white/25" />
                            <kbd
                                class="inline-flex h-5 min-w-5 items-center justify-center rounded bg-white/15 p-1 font-mono text-[10px] text-white/80"
                                >&#9664;</kbd
                            >
                            <kbd
                                class="inline-flex h-5 min-w-5 items-center justify-center rounded bg-white/15 p-1 font-mono text-[10px] text-white/80"
                                >&#9654;</kbd
                            >
                            <kbd
                                class="inline-flex h-5 min-w-5 items-center justify-center rounded bg-white/15 p-1 font-mono text-[10px] text-white/80"
                                >F</kbd
                            >
                            <kbd
                                v-if="hasOriginal"
                                class="inline-flex h-5 min-w-5 items-center justify-center rounded bg-white/15 p-1 font-mono text-[10px] text-white/80"
                                >O</kbd
                            >
                            <kbd
                                class="inline-flex h-5 min-w-5 items-center justify-center rounded bg-white/15 p-1 font-mono text-[10px] text-white/80"
                                >Esc</kbd
                            >
                        </span>
                    </div>
                </div>

                <!-- Footer -->
                <Transition
                    enter-active-class="transition-opacity duration-200"
                    leave-active-class="transition-opacity duration-200"
                    enter-from-class="opacity-0"
                    leave-to-class="opacity-0"
                >
                    <div v-show="!isZoomed" class="absolute right-0 bottom-0 left-0 z-20 bg-gradient-to-t from-black/80 to-transparent p-4">
                        <div class="mx-auto max-w-4xl text-center">
                            <slot name="footer" :item="currentItem" :index="currentIndex">
                                <p v-if="currentItem.alt" class="font-medium text-white">
                                    {{ currentItem.alt }}
                                </p>
                            </slot>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.cmd-lightbox-active {
    background: var(--accent);
    color: #fff;
}
</style>
