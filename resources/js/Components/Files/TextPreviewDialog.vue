<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CommandDialog from '@/Components/Command/Dialog.vue';
import { useWorkspace } from '@/composables/useWorkspace';

interface TextFileItem {
    id: number;
    name: string;
    is_markdown?: boolean;
}

const props = defineProps<{ item: TextFileItem | null; downloadUrl?: string }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();
const { workspaceUrl } = useWorkspace();

const loading = ref(false);
const error = ref(false);
const content = ref('');
const truncated = ref(false);
const renderedHtml = ref<string | null>(null);

const isOpen = computed(() => props.item !== null);

function close() {
    emit('close');
}

async function load(id: number, isMarkdown: boolean) {
    loading.value = true;
    error.value = false;
    content.value = '';
    renderedHtml.value = null;
    truncated.value = false;
    try {
        const res = await fetch(workspaceUrl(`/files/${id}/text`), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? ''),
            },
            credentials: 'same-origin',
        });
        if (!res.ok) {
            error.value = true;
            return;
        }
        const data = await res.json();
        content.value = data.content ?? '';
        truncated.value = !!data.truncated;
        if (isMarkdown || data.is_markdown) {
            // Render markdown, then sanitize — never trust file contents.
            const [{ default: MarkdownIt }, DOMPurify] = await Promise.all([
                import('markdown-it'),
                import('dompurify'),
            ]);
            const md = new MarkdownIt({ html: false, linkify: true, breaks: true });
            renderedHtml.value = DOMPurify.default.sanitize(md.render(content.value));
        }
    } catch {
        error.value = true;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.item,
    (item) => {
        if (item) load(item.id, !!item.is_markdown);
    },
);
</script>

<template>
    <CommandDialog
        :visible="isOpen"
        :title="item?.name ?? ''"
        width="820px"
        @update:visible="(v: boolean) => { if (!v) close(); }"
    >
        <div v-if="loading" :style="{ display: 'flex', justifyContent: 'center', padding: '40px' }">
            <i class="pi pi-spin pi-spinner" :style="{ fontSize: '22px', color: 'var(--fg-mute)' }" />
        </div>

        <div v-else-if="error" :style="{ padding: '24px', textAlign: 'center', color: 'var(--danger)', fontSize: '12px' }">
            {{ t('files.text_preview.error') }}
        </div>

        <div v-else>
            <!-- Rendered markdown -->
            <div
                v-if="renderedHtml !== null"
                class="cmd-md-body"
                v-html="renderedHtml"
            />
            <!-- Raw text / code -->
            <pre v-else class="cmd-code-body">{{ content }}</pre>

            <p v-if="truncated" :style="{ marginTop: '10px', fontSize: '11px', color: 'var(--fg-mute)' }">
                {{ t('files.text_preview.truncated') }}
                <a v-if="downloadUrl" :href="downloadUrl" :style="{ color: 'var(--accent)' }">{{ t('files.download_original') }}</a>
            </p>
        </div>
    </CommandDialog>
</template>

<style scoped>
.cmd-code-body {
    margin: 0;
    max-height: 70vh;
    overflow: auto;
    background: var(--panel2);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 14px 16px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12.5px;
    line-height: 1.55;
    color: var(--fg);
    white-space: pre-wrap;
    word-break: break-word;
    tab-size: 4;
}
.cmd-md-body {
    max-height: 70vh;
    overflow: auto;
    font-size: 13.5px;
    line-height: 1.6;
    color: var(--fg);
    padding: 4px 2px;
}
.cmd-md-body :deep(h1),
.cmd-md-body :deep(h2),
.cmd-md-body :deep(h3) {
    font-weight: 600;
    margin: 1em 0 0.4em;
    color: var(--fg);
}
.cmd-md-body :deep(p) {
    margin: 0.5em 0;
}
.cmd-md-body :deep(code) {
    background: var(--panel2);
    border-radius: 3px;
    padding: 1px 5px;
    font-family: ui-monospace, monospace;
    font-size: 0.9em;
}
.cmd-md-body :deep(pre) {
    background: var(--panel2);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 12px 14px;
    overflow: auto;
}
.cmd-md-body :deep(a) {
    color: var(--accent);
}
.cmd-md-body :deep(ul),
.cmd-md-body :deep(ol) {
    padding-left: 1.4em;
    margin: 0.5em 0;
}
.cmd-md-body :deep(blockquote) {
    border-left: 3px solid var(--border);
    margin: 0.6em 0;
    padding-left: 12px;
    color: var(--fg-dim);
}
.cmd-md-body :deep(table) {
    border-collapse: collapse;
    margin: 0.6em 0;
}
.cmd-md-body :deep(th),
.cmd-md-body :deep(td) {
    border: 1px solid var(--border);
    padding: 5px 10px;
}
</style>
