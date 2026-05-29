<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';
import { useToast } from 'primevue/usetoast';

const emit = defineEmits<{
    send: [payload: { body: string; files: File[] }];
    typing: [];
}>();

const { t, locale } = useI18n();
const toast = useToast();
const body = ref('');
const pendingFiles = ref<File[]>([]);
const textareaRef = ref<HTMLTextAreaElement | null>(null);
const fileInputRef = ref<HTMLInputElement | null>(null);

let lastTypingEmit = 0;
const MAX_FILES = 10;
const MAX_SIZE_BYTES = 10 * 1024 * 1024;

function handleInput() {
    autoResize();
    const now = Date.now();
    if (now - lastTypingEmit > 2000) {
        lastTypingEmit = now;
        emit('typing');
    }
}

function handleKeydown(e: KeyboardEvent) {
    // Don't intercept Enter while an IME composition is active — the user is
    // picking a candidate, not submitting.
    if (e.isComposing) return;
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        send();
    }
}

function send() {
    const text = body.value.trim();
    if (!text && pendingFiles.value.length === 0) return;
    emit('send', { body: text, files: [...pendingFiles.value] });
    body.value = '';
    pendingFiles.value = [];
    if (textareaRef.value) {
        textareaRef.value.style.height = 'auto';
    }
    if (fileInputRef.value) {
        fileInputRef.value.value = '';
    }
}

function autoResize() {
    if (!textareaRef.value) return;
    textareaRef.value.style.height = 'auto';
    textareaRef.value.style.height = Math.min(textareaRef.value.scrollHeight, 120) + 'px';
}

function onFilesSelected(e: Event) {
    const target = e.target as HTMLInputElement;
    const selected = Array.from(target.files ?? []);
    const oversized: string[] = [];
    const valid: File[] = [];

    for (const f of selected) {
        if (f.size > MAX_SIZE_BYTES) {
            oversized.push(f.name);
            continue;
        }
        valid.push(f);
    }

    const roomLeft = Math.max(0, MAX_FILES - pendingFiles.value.length);
    const accepted = valid.slice(0, roomLeft);
    const droppedForCap = valid.length - accepted.length;

    pendingFiles.value = [...pendingFiles.value, ...accepted];

    if (oversized.length > 0) {
        toast.add({
            severity: 'warn',
            summary: t('chat.attachment_rejected_size', {
                names: oversized.join(', '),
                max: formatSize(MAX_SIZE_BYTES),
            }),
            life: 5000,
        });
    }
    if (droppedForCap > 0) {
        toast.add({
            severity: 'warn',
            summary: t('chat.attachment_rejected_count', { max: MAX_FILES }),
            life: 5000,
        });
    }

    // Allow re-selecting the same file after removing.
    target.value = '';
}

function removeFile(index: number) {
    pendingFiles.value.splice(index, 1);
}

function openFilePicker() {
    fileInputRef.value?.click();
}

function formatSize(bytes: number): string {
    // Intl.NumberFormat with style:'unit' handles localization for B/KB/MB.
    if (bytes < 1024) {
        return new Intl.NumberFormat(locale.value, { style: 'unit', unit: 'byte', maximumFractionDigits: 0 }).format(bytes);
    }
    if (bytes < 1024 * 1024) {
        return new Intl.NumberFormat(locale.value, { style: 'unit', unit: 'kilobyte', maximumFractionDigits: 0 }).format(bytes / 1024);
    }
    return new Intl.NumberFormat(locale.value, { style: 'unit', unit: 'megabyte', maximumFractionDigits: 1 }).format(bytes / (1024 * 1024));
}
</script>

<template>
    <div class="border-t border-gray-200 dark:border-dark-700 px-4 py-3">
        <!-- Pending attachments -->
        <ul v-if="pendingFiles.length > 0" class="flex flex-wrap gap-2 mb-2">
            <li
                v-for="(f, i) in pendingFiles"
                :key="i"
                :title="f.name"
                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-gray-100 dark:bg-dark-800 text-xs text-gray-700 dark:text-gray-200"
            >
                <i class="pi pi-paperclip text-[10px]"></i>
                <span class="truncate max-w-[10rem]">{{ f.name }}</span>
                <span class="text-gray-400">({{ formatSize(f.size) }})</span>
                <button
                    type="button"
                    @click="removeFile(i)"
                    class="ml-1 text-gray-400 hover:text-red-500 cursor-pointer"
                    :aria-label="t('chat.remove_attachment')"
                >
                    <i class="pi pi-times text-[10px]"></i>
                </button>
            </li>
        </ul>

        <div class="flex items-end gap-2">
            <!-- Upload button -->
            <button
                type="button"
                @click="openFilePicker"
                class="h-10 w-10 shrink-0 flex items-center justify-center rounded-xl bg-gray-100 dark:bg-dark-800 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-dark-700 cursor-pointer"
                :title="t('chat.attach_files')"
                :aria-label="t('chat.attach_files')"
            >
                <i class="pi pi-paperclip text-sm"></i>
            </button>
            <input
                ref="fileInputRef"
                type="file"
                name="attachments"
                multiple
                class="hidden"
                @change="onFilesSelected"
            />

            <textarea
                ref="textareaRef"
                v-model="body"
                name="message"
                @input="handleInput"
                @keydown="handleKeydown"
                :placeholder="t('chat.type_message')"
                rows="1"
                class="flex-1 resize-none rounded-xl border border-gray-300 dark:border-dark-600 bg-white dark:bg-dark-800 px-4 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent leading-6 py-2"
                style="min-height: 2.5rem;"
            ></textarea>
            <button
                type="button"
                @click="send"
                :disabled="!body.trim() && pendingFiles.length === 0"
                class="h-10 w-10 shrink-0 flex items-center justify-center rounded-xl bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors cursor-pointer"
                :title="t('chat.send')"
                :aria-label="t('chat.send')"
            >
                <i class="pi pi-send text-sm"></i>
            </button>
        </div>
    </div>
</template>
