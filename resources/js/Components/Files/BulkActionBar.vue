<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Download, FolderInput, Trash2, X } from 'lucide-vue-next';

defineProps<{ count: number; canDelete?: boolean; canMove?: boolean }>();
const emit = defineEmits<{ download: []; move: []; delete: []; clear: [] }>();
const { t } = useI18n();
</script>

<template>
    <Transition
        enter-active-class="transition ease-out duration-150"
        enter-from-class="opacity-0 translate-y-2"
        leave-active-class="transition ease-in duration-100"
        leave-to-class="opacity-0 translate-y-2"
    >
        <div v-if="count > 0" class="cmd-bulk-bar">
            <span class="cmd-bulk-count">{{ t('files.bulk.selected', { count }) }}</span>
            <div class="cmd-bulk-actions">
                <button type="button" class="cmd-bulk-btn" @click="emit('download')">
                    <Download class="h-4 w-4" />
                    <span>{{ t('files.bulk.download') }}</span>
                </button>
                <button v-if="canMove" type="button" class="cmd-bulk-btn" @click="emit('move')">
                    <FolderInput class="h-4 w-4" />
                    <span>{{ t('files.bulk.move') }}</span>
                </button>
                <button v-if="canDelete" type="button" class="cmd-bulk-btn cmd-bulk-danger" @click="emit('delete')">
                    <Trash2 class="h-4 w-4" />
                    <span>{{ t('files.bulk.delete') }}</span>
                </button>
                <button type="button" class="cmd-bulk-btn cmd-bulk-ghost" :aria-label="t('common.cancel')" @click="emit('clear')">
                    <X class="h-4 w-4" />
                </button>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.cmd-bulk-bar {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 40;
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 8px 10px 8px 16px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: var(--shadow-palette, 0 10px 40px rgba(0, 0, 0, 0.35));
}
.cmd-bulk-count {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--fg);
    white-space: nowrap;
}
.cmd-bulk-actions {
    display: flex;
    align-items: center;
    gap: 6px;
}
.cmd-bulk-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--panel2);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 6px 11px;
    font-size: 12px;
    font-family: inherit;
    color: var(--fg);
    cursor: pointer;
    transition: background 0.12s, border-color 0.12s;
}
.cmd-bulk-btn:hover {
    background: var(--panel);
    border-color: var(--accent-border);
}
.cmd-bulk-danger {
    color: var(--danger);
}
.cmd-bulk-danger:hover {
    background: rgba(255, 138, 138, 0.12);
    border-color: rgba(255, 138, 138, 0.4);
}
.cmd-bulk-ghost {
    padding: 6px;
}
</style>
