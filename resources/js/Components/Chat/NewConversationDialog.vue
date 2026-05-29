<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { onBeforeUnmount, ref, watch } from 'vue';
import CommandDialog from '@/Components/Command/Dialog.vue';
import Button from '@/Components/Command/Button.vue';
import type { ChatUser } from '@/composables/useChat';

const props = defineProps<{
    visible: boolean;
}>();

const emit = defineEmits<{
    'update:visible': [value: boolean];
    create: [userIds: number[]];
}>();

const { t } = useI18n();

const query = ref('');
const results = ref<(ChatUser & { full_name: string })[]>([]);
const searching = ref(false);
const selectedUsers = ref<(ChatUser & { full_name: string })[]>([]);
const searchInput = ref<HTMLInputElement | null>(null);

let searchTimeout: ReturnType<typeof setTimeout> | null = null;
let searchAbort: AbortController | null = null;

watch(query, (val) => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchAbort?.abort();
    if (val.trim().length < 2) {
        results.value = [];
        searching.value = false;
        return;
    }
    searching.value = true;
    searchTimeout = setTimeout(() => {
        searchAbort = new AbortController();
        const controller = searchAbort;
        fetch(`/chat/users/search?q=${encodeURIComponent(val.trim())}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        })
            .then(r => r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`)))
            .then(json => {
                if (controller.signal.aborted || !props.visible) return;
                results.value = (json.users ?? []).filter(
                    (u: ChatUser) => !selectedUsers.value.some(s => s.id === u.id),
                );
            })
            .catch(() => { /* aborted or network error */ })
            .finally(() => {
                if (!controller.signal.aborted) searching.value = false;
            });
    }, 300);
});

watch(() => props.visible, (v) => {
    if (!v) {
        if (searchTimeout) clearTimeout(searchTimeout);
        searchAbort?.abort();
        query.value = '';
        results.value = [];
        selectedUsers.value = [];
        searching.value = false;
    }
});

onBeforeUnmount(() => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchAbort?.abort();
});

function selectUser(user: ChatUser & { full_name: string }) {
    selectedUsers.value.push(user);
    results.value = results.value.filter(r => r.id !== user.id);
    query.value = '';
}

function removeUser(userId: number) {
    selectedUsers.value = selectedUsers.value.filter(u => u.id !== userId);
}

function submit() {
    if (selectedUsers.value.length === 0) return;
    emit('create', selectedUsers.value.map(u => u.id));
}

function close() {
    emit('update:visible', false);
}

function initials(user: ChatUser): string {
    const first = (user.first_name?.trim() ?? '')[0] ?? '';
    const last = (user.last_name?.trim() ?? '')[0] ?? '';
    return (first + last).toUpperCase();
}
</script>

<template>
    <CommandDialog
        :visible="visible"
        :title="t('chat.new_conversation')"
        width="440px"
        @update:visible="(v: boolean) => emit('update:visible', v)"
    >
        <div :style="{ display: 'flex', flexDirection: 'column', gap: '14px' }">
            <!-- Selected users chips -->
            <div
                v-if="selectedUsers.length > 0"
                :style="{ display: 'flex', flexWrap: 'wrap', gap: '6px' }"
            >
                <span
                    v-for="u in selectedUsers"
                    :key="u.id"
                    :style="{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: '6px',
                        padding: '3px 4px 3px 10px',
                        borderRadius: '999px',
                        background: 'var(--accent-soft)',
                        border: '1px solid var(--accent-border)',
                        color: 'var(--accent)',
                        fontSize: '11.5px',
                        fontWeight: 500,
                    }"
                >
                    {{ u.full_name }}
                    <button
                        type="button"
                        :aria-label="t('common.remove')"
                        @click="removeUser(u.id)"
                        :style="{
                            width: '16px',
                            height: '16px',
                            borderRadius: '50%',
                            background: 'transparent',
                            border: 'none',
                            color: 'var(--accent)',
                            cursor: 'pointer',
                            display: 'inline-flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            padding: 0,
                            lineHeight: 1,
                        }"
                    >
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="none" aria-hidden="true">
                            <path d="M2 2l6 6M8 2l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                        </svg>
                    </button>
                </span>
            </div>

            <!-- Search input -->
            <input
                ref="searchInput"
                v-model="query"
                type="text"
                name="user_search"
                data-autofocus
                :placeholder="t('chat.search_users')"
                :style="{
                    width: '100%',
                    background: 'var(--panel2)',
                    border: '1px solid var(--border)',
                    borderRadius: '5px',
                    padding: '8px 10px',
                    color: 'var(--fg)',
                    fontSize: '13px',
                    outline: 'none',
                    fontFamily: 'inherit',
                    transition: 'border-color 0.12s',
                }"
                @focus="($event.target as HTMLInputElement).style.borderColor = 'var(--accent)'"
                @blur="($event.target as HTMLInputElement).style.borderColor = 'var(--border)'"
            />

            <!-- Search results -->
            <div
                v-if="searching"
                :style="{ textAlign: 'center', padding: '10px 0', color: 'var(--fg-mute)', fontSize: '11.5px' }"
            >
                <i class="pi pi-spin pi-spinner" />
            </div>
            <ul
                v-else-if="results.length > 0"
                role="listbox"
                :aria-label="t('chat.search_users')"
                :style="{
                    listStyle: 'none',
                    padding: 0,
                    margin: 0,
                    maxHeight: '200px',
                    overflowY: 'auto',
                    border: '1px solid var(--border)',
                    borderRadius: '5px',
                    background: 'var(--panel2)',
                }"
            >
                <li
                    v-for="(u, i) in results"
                    :key="u.id"
                    role="option"
                    tabindex="0"
                    class="cmd-nc-result"
                    :aria-selected="false"
                    @click="selectUser(u)"
                    @keydown.enter.prevent="selectUser(u)"
                    @keydown.space.prevent="selectUser(u)"
                    :style="{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '10px',
                        padding: '8px 10px',
                        cursor: 'pointer',
                        borderTop: i === 0 ? 'none' : '1px solid var(--border)',
                        fontSize: '12.5px',
                        color: 'var(--fg)',
                    }"
                >
                    <img
                        v-if="u.avatar_thumb_url"
                        :src="u.avatar_thumb_url"
                        :alt="t('chat.avatar_alt', { name: u.full_name })"
                        :style="{ width: '26px', height: '26px', borderRadius: '50%', objectFit: 'cover', flexShrink: 0 }"
                    />
                    <div
                        v-else
                        :aria-label="t('chat.avatar_alt', { name: u.full_name })"
                        :style="{
                            width: '26px',
                            height: '26px',
                            borderRadius: '50%',
                            background: 'var(--accent)',
                            color: '#fff',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            fontSize: '10px',
                            fontWeight: 600,
                            fontFamily: 'var(--font-mono)',
                            flexShrink: 0,
                        }"
                    >{{ initials(u) }}</div>
                    <span>{{ u.full_name }}</span>
                </li>
            </ul>
            <p
                v-else-if="query.trim().length >= 2 && !searching"
                :style="{ fontSize: '12px', color: 'var(--fg-mute)', textAlign: 'center', padding: '10px 0', margin: 0 }"
            >
                {{ t('chat.no_users_found') }}
            </p>
        </div>

        <template #footer>
            <Button variant="ghost" size="sm" @click="close">
                {{ t('common.cancel') }}
            </Button>
            <Button
                variant="primary"
                size="sm"
                :disabled="selectedUsers.length === 0"
                @click="submit"
            >
                {{ t('chat.start_chat') }}
            </Button>
        </template>
    </CommandDialog>
</template>

<style scoped>
.cmd-nc-result:hover,
.cmd-nc-result:focus-visible {
    background: var(--accent-soft);
    outline: none;
}
</style>
