<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref, computed, nextTick, watch, onMounted } from 'vue';
import type { ChatMessage } from '@/composables/useChat';
import ImageLightbox from '@/Components/Files/ImageLightbox.vue';
import type { LightboxItem } from '@/types/lightbox';

const props = defineProps<{
    messages: ChatMessage[];
    currentUserId: number;
    hasMore: boolean;
    loading: boolean;
    typingUser: string | null;
}>();

const emit = defineEmits<{
    loadMore: [];
}>();

const { t } = useI18n();
const scrollContainer = ref<HTMLElement | null>(null);
const autoScroll = ref(true);
let prevScrollHeight = 0;
let prevScrollTop = 0;

function scrollToBottom() {
    nextTick(() => {
        if (scrollContainer.value && autoScroll.value) {
            scrollContainer.value.scrollTop = scrollContainer.value.scrollHeight;
        }
    });
}

function handleScroll() {
    if (!scrollContainer.value) return;
    const { scrollTop, scrollHeight, clientHeight } = scrollContainer.value;
    autoScroll.value = scrollHeight - scrollTop - clientHeight < 50;

    // Load more when scrolled near top — remember both height and scrollTop
    // so the viewport stays anchored to the same content after new messages
    // are prepended above.
    if (scrollTop < 100 && props.hasMore && !props.loading) {
        prevScrollHeight = scrollHeight;
        prevScrollTop = scrollTop;
        emit('loadMore');
    }
}

watch(() => props.messages.length, () => {
    if (autoScroll.value) {
        scrollToBottom();
        return;
    }
    if (prevScrollHeight && scrollContainer.value) {
        const capturedHeight = prevScrollHeight;
        const capturedTop = prevScrollTop;
        prevScrollHeight = 0;
        prevScrollTop = 0;
        nextTick(() => {
            const el = scrollContainer.value;
            if (!el) return;
            el.scrollTop = capturedTop + (el.scrollHeight - capturedHeight);
        });
    }
});

onMounted(() => {
    scrollToBottom();
});

function isSameDay(a: string, b: string): boolean {
    return new Date(a).toDateString() === new Date(b).toDateString();
}

function dateLabel(iso: string): string {
    const d = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);

    if (d.toDateString() === today.toDateString()) return t('chat.today');
    if (d.toDateString() === yesterday.toDateString()) return t('chat.yesterday');
    return d.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' });
}

// Full date + time, shown only on hover (title) so the thread stays clean
// instead of stamping a time under every single message.
function fullDateTime(iso: string): string {
    return new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
}

function initials(user: { first_name: string; last_name: string }): string {
    const first = (user.first_name?.trim() ?? '')[0] ?? '';
    const last = (user.last_name?.trim() ?? '')[0] ?? '';
    return (first + last).toUpperCase();
}

// Every image attachment in the thread, flattened in render order, mapped to the
// shared Files lightbox's item shape so clicking a chat image opens the same
// full-screen viewer (with zoom + arrow navigation between all images here).
const imageItems = computed<LightboxItem[]>(() =>
    props.messages.flatMap((m) =>
        (m.attachments ?? [])
            .filter((a) => a.is_image)
            .map((a): LightboxItem => ({
                id: a.id,
                kind: 'image',
                src: a.url,
                alt: a.name,
                canZoom: true,
                downloadUrl: a.download_url,
                mime: a.mime_type,
            })),
    ),
);
const lightboxIndex = ref<number | null>(null);
function openLightbox(attachmentId: number) {
    const idx = imageItems.value.findIndex((i) => i.id === attachmentId);
    if (idx >= 0) lightboxIndex.value = idx;
}

defineExpose({ scrollToBottom });
</script>

<template>
    <div ref="scrollContainer" @scroll="handleScroll" class="flex-1 overflow-y-auto px-4 py-4 space-y-1">
        <!-- Load more spinner -->
        <div v-if="loading && hasMore" class="text-center py-2">
            <i class="pi pi-spin pi-spinner text-gray-400"></i>
        </div>

        <template v-for="(msg, i) in messages" :key="msg.id">
            <!-- Date separator -->
            <div
                v-if="i === 0 || !isSameDay(messages[i - 1].created_at, msg.created_at)"
                class="flex items-center gap-3 py-3"
            >
                <div class="flex-1 h-px bg-gray-200 dark:bg-dark-700"></div>
                <span class="text-[10px] text-gray-400 font-medium uppercase">{{ dateLabel(msg.created_at) }}</span>
                <div class="flex-1 h-px bg-gray-200 dark:bg-dark-700"></div>
            </div>

            <!-- Message bubble -->
            <div
                class="flex gap-2"
                :class="msg.user_id === currentUserId ? 'justify-end' : 'justify-start'"
            >
                <!-- Other user avatar -->
                <div v-if="msg.user_id !== currentUserId" class="shrink-0 mt-auto">
                    <img
                        v-if="msg.user.avatar_thumb_url"
                        :src="msg.user.avatar_thumb_url"
                        :alt="`${msg.user.first_name} ${msg.user.last_name}`.trim()"
                        class="w-7 h-7 rounded-full object-cover"
                    />
                    <div
                        v-else
                        class="w-7 h-7 rounded-full bg-indigo-600 text-white flex items-center justify-center text-[10px] font-semibold"
                        :aria-label="`${msg.user.first_name} ${msg.user.last_name}`.trim()"
                    >{{ initials(msg.user) }}</div>
                </div>

                <div
                    class="max-w-[75%] px-3 py-2 rounded-2xl text-sm"
                    :title="fullDateTime(msg.created_at)"
                    :class="msg.user_id === currentUserId
                        ? 'bg-indigo-600 text-white rounded-br-md'
                        : 'bg-gray-100 dark:bg-dark-800 text-gray-900 dark:text-gray-100 rounded-bl-md'"
                >
                    <p v-if="msg.body" class="whitespace-pre-wrap break-words">{{ msg.body }}</p>

                    <!-- Attachments. Images render inline (click → open);
                         non-images render as a download card. A small
                         download icon on images offers the same forced-
                         download path via the authenticated route. -->
                    <div v-if="msg.attachments && msg.attachments.length > 0" class="mt-2 space-y-2">
                        <template v-for="att in msg.attachments" :key="att.id">
                            <div v-if="att.is_image" class="relative group/attachment">
                                <button
                                    type="button"
                                    class="block cursor-zoom-in"
                                    :aria-label="att.name"
                                    @click="openLightbox(att.id)"
                                >
                                    <img
                                        :src="att.thumb_url ?? att.url"
                                        :alt="att.name"
                                        class="max-w-full rounded-lg max-h-60 object-contain bg-white/10"
                                        @error="(e) => { const el = e.target as HTMLImageElement; if (att.url && el.src !== att.url) el.src = att.url; }"
                                    />
                                </button>
                                <a
                                    :href="att.download_url"
                                    class="absolute top-1 right-1 p-1.5 rounded-md bg-black/60 text-white opacity-0 group-hover/attachment:opacity-100 focus:opacity-100 transition-opacity"
                                    :title="t('chat.download_attachment')"
                                    :aria-label="t('chat.download_attachment')"
                                >
                                    <i class="pi pi-download text-xs"></i>
                                </a>
                            </div>
                            <a
                                v-else
                                :href="att.download_url"
                                :title="att.name"
                                class="flex items-center gap-2 px-2 py-1.5 rounded-lg text-xs"
                                :class="msg.user_id === currentUserId ? 'bg-indigo-700/50 hover:bg-indigo-700/70' : 'bg-gray-200 dark:bg-dark-700 hover:bg-gray-300 dark:hover:bg-dark-600'"
                            >
                                <i class="pi pi-file text-sm"></i>
                                <span class="truncate max-w-[12rem]">{{ att.name }}</span>
                                <i class="pi pi-download text-[10px] ml-auto opacity-70"></i>
                            </a>
                        </template>
                    </div>

                </div>
            </div>
        </template>

        <!-- Typing indicator -->
        <div v-if="typingUser" class="flex items-center gap-2 py-1">
            <span class="text-xs text-gray-400 italic">{{ t('chat.typing', { name: typingUser }) }}</span>
        </div>

        <!-- Empty state -->
        <div v-if="messages.length === 0 && !loading" class="flex items-center justify-center h-full">
            <p class="text-sm text-gray-400">{{ t('chat.no_messages') }}</p>
        </div>

        <!-- Shared Files lightbox for image attachments (teleports to body) -->
        <ImageLightbox v-if="imageItems.length" v-model="lightboxIndex" :items="imageItems" />
    </div>
</template>
