<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { useWorkspace } from '@/composables/useWorkspace';
import { useUnreadCounts } from '@/composables/useUnreadCounts';
import Icon from '@/Components/Command/Icon.vue';

interface NotificationItem {
    id: string;
    type: string;
    data: { title?: string; message?: string; icon?: string; action_url?: string };
    read_at: string | null;
    created_at: string;
}

const { t } = useI18n();
const toast = useToast();
const { workspaceUrl } = useWorkspace();
const { notificationsCount: unreadCount, decrementNotifications, setNotifications } = useUnreadCounts();

const open = ref(false);
const notifications = ref<NotificationItem[]>([]);
const loading = ref(false);
const hoverId = ref<string | null>(null);

const notificationsUrl = computed(() => workspaceUrl('/notifications'));
const readAllUrl = computed(() => workspaceUrl('/notifications/read-all'));
const clearAllUrl = computed(() => workspaceUrl('/notifications'));

function fetchNotifications() {
    loading.value = true;
    fetch(notificationsUrl.value, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then(r => r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`)))
        .then(json => { notifications.value = json.recent ?? []; })
        .catch(() => {
            toast.add({ severity: 'error', summary: t('notifications.load_failed'), life: 4000 });
        })
        .finally(() => { loading.value = false; });
}

const nowTick = ref(Date.now());
let tickHandle: number | undefined;
onMounted(() => {
    tickHandle = window.setInterval(() => { nowTick.value = Date.now(); }, 30_000);
    document.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
    if (tickHandle !== undefined) window.clearInterval(tickHandle);
    document.removeEventListener('keydown', onKeydown);
});

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape' && open.value) open.value = false;
}

watch(open, (v) => { if (v) fetchNotifications(); });

defineExpose({ refresh: fetchNotifications });

function markOneRead(n: NotificationItem, onDone?: () => void) {
    if (n.read_at) {
        onDone?.();
        return;
    }
    router.post(workspaceUrl(`/notifications/${n.id}/read`), {}, {
        preserveScroll: true,
        onSuccess: () => {
            n.read_at = new Date().toISOString();
            decrementNotifications(1);
        },
        // Navigate (if any) only once the read request settles — otherwise the
        // immediate router.visit below would cancel this in-flight POST and the
        // notification would stay unread server-side.
        onFinish: () => onDone?.(),
    });
}

// Clicking a notification marks it read and, if it carries an action_url
// (e.g. a chat message → its conversation), navigates there afterwards.
function onNotificationClick(n: NotificationItem) {
    const url = n.data.action_url;
    if (url) {
        open.value = false;
        markOneRead(n, () => router.visit(url));
    } else {
        markOneRead(n);
    }
}

function deleteOne(n: NotificationItem) {
    const wasUnread = !n.read_at;
    router.delete(workspaceUrl(`/notifications/${n.id}`), {
        preserveScroll: true,
        onSuccess: () => {
            notifications.value = notifications.value.filter(x => x.id !== n.id);
            if (wasUnread) decrementNotifications(1);
        },
    });
}

function clearAll() {
    router.delete(clearAllUrl.value, {
        preserveScroll: true,
        onSuccess: () => {
            notifications.value = [];
            setNotifications(0);
            open.value = false;
        },
    });
}

function markAllRead() {
    router.post(readAllUrl.value, {}, {
        preserveScroll: true,
        onSuccess: () => {
            notifications.value.forEach(n => { n.read_at = n.read_at ?? new Date().toISOString(); });
            setNotifications(0);
            open.value = false;
        },
    });
}

function timeAgo(iso: string): string {
    const seconds = Math.floor((nowTick.value - new Date(iso).getTime()) / 1000);
    if (seconds < 60) return t('notifications.just_now');
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return t('notifications.minutes_ago', { n: minutes });
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return t('notifications.hours_ago', { n: hours });
    const days = Math.floor(hours / 24);
    return t('notifications.days_ago', { n: days });
}

function notificationTitle(n: NotificationItem): string {
    if (n.data.title) return n.data.title;
    return t('notifications.untitled');
}
</script>

<template>
    <div :style="{ position: 'relative' }">
        <button
            type="button"
            @click="open = !open"
            :aria-label="t('notifications.title')"
            :aria-expanded="open"
            aria-haspopup="true"
            :style="{
                background: open ? 'var(--accent-soft)' : 'transparent',
                border: `1px solid ${open ? 'var(--accent-border)' : 'var(--border)'}`,
                color: open ? 'var(--accent)' : 'var(--fg-dim)',
                padding: '4px 7px',
                borderRadius: '5px',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                position: 'relative',
                transition: 'background 0.12s, border-color 0.12s, color 0.12s',
            }"
        >
            <Icon name="bell" :size="13" />
            <span
                v-if="unreadCount > 0"
                class="cmd-mono"
                :style="{
                    position: 'absolute',
                    top: '-4px',
                    right: '-4px',
                    minWidth: '14px',
                    height: '14px',
                    padding: '0 3px',
                    borderRadius: '7px',
                    background: 'var(--danger)',
                    color: '#0a0c12',
                    fontSize: '9px',
                    fontWeight: 600,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                }"
            >{{ unreadCount > 99 ? '99+' : unreadCount }}</span>
        </button>

        <Transition
            enter-active-class="cmd-pop-enter"
            leave-active-class="cmd-pop-leave"
        >
            <div
                v-if="open"
                :style="{
                    position: 'absolute',
                    right: 0,
                    top: 'calc(100% + 6px)',
                    width: '340px',
                    background: 'var(--panel)',
                    border: '1px solid var(--border)',
                    borderRadius: '6px',
                    boxShadow: 'var(--shadow-palette)',
                    zIndex: 50,
                    overflow: 'hidden',
                    animation: 'cmdFadeIn 0.12s ease-out',
                }"
            >
                <!-- Header -->
                <div
                    :style="{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        padding: '10px 14px',
                        borderBottom: '1px solid var(--border)',
                        gap: '10px',
                    }"
                >
                    <div :style="{ display: 'flex', alignItems: 'center', gap: '7px' }">
                        <span
                            class="cmd-mono cmd-uc"
                            :style="{ fontSize: '10px', color: 'var(--fg-mute)', fontWeight: 500, letterSpacing: '0.08em' }"
                        >{{ t('notifications.title') }}</span>
                        <span
                            v-if="unreadCount > 0"
                            class="cmd-mono"
                            :style="{ fontSize: '9.5px', color: 'var(--accent)', background: 'var(--accent-soft)', border: '1px solid var(--accent-border)', padding: '1px 6px', borderRadius: '3px' }"
                        >{{ unreadCount }}</span>
                    </div>
                    <div v-if="notifications.length > 0" :style="{ display: 'flex', gap: '10px' }">
                        <button
                            v-if="unreadCount > 0"
                            type="button"
                            @click="markAllRead"
                            :style="{ background: 'transparent', border: 'none', color: 'var(--accent)', fontSize: '11px', cursor: 'pointer', padding: 0, fontFamily: 'inherit' }"
                        >{{ t('notifications.mark_all_read') }}</button>
                        <button
                            type="button"
                            @click="clearAll"
                            :style="{ background: 'transparent', border: 'none', color: 'var(--danger)', fontSize: '11px', cursor: 'pointer', padding: 0, fontFamily: 'inherit' }"
                        >{{ t('notifications.clear_all') }}</button>
                    </div>
                </div>

                <!-- Body -->
                <div
                    v-if="loading"
                    :style="{ padding: '28px 14px', textAlign: 'center', color: 'var(--fg-mute)', fontSize: '11.5px' }"
                >
                    <span class="cmd-mono">{{ t('notifications.loading') }}</span>
                </div>
                <div
                    v-else-if="notifications.length === 0"
                    :style="{ padding: '28px 14px', textAlign: 'center', color: 'var(--fg-mute)', fontSize: '12px' }"
                >
                    {{ t('notifications.empty') }}
                </div>
                <ul
                    v-else
                    :style="{ listStyle: 'none', padding: 0, margin: 0, maxHeight: '360px', overflowY: 'auto' }"
                >
                    <li
                        v-for="n in notifications"
                        :key="n.id"
                        @mouseenter="hoverId = n.id"
                        @mouseleave="hoverId = null"
                        @click="onNotificationClick(n)"
                        :style="{
                            display: 'grid',
                            gridTemplateColumns: '10px 1fr 24px',
                            gap: '10px',
                            padding: '10px 14px',
                            borderBottom: '1px solid var(--border)',
                            cursor: (n.read_at && !n.data.action_url) ? 'default' : 'pointer',
                            background: hoverId === n.id ? 'var(--row-hover)' : 'transparent',
                            transition: 'background 0.1s',
                            opacity: n.read_at ? 0.65 : 1,
                        }"
                    >
                        <!-- Unread dot -->
                        <span
                            :style="{
                                width: '6px',
                                height: '6px',
                                borderRadius: '50%',
                                background: n.read_at ? 'transparent' : 'var(--accent)',
                                marginTop: '6px',
                                justifySelf: 'center',
                            }"
                        />
                        <!-- Content -->
                        <div :style="{ minWidth: 0 }">
                            <div
                                :style="{
                                    fontSize: '12.5px',
                                    fontWeight: n.read_at ? 400 : 500,
                                    color: 'var(--fg)',
                                    overflow: 'hidden',
                                    textOverflow: 'ellipsis',
                                    whiteSpace: 'nowrap',
                                }"
                            >{{ notificationTitle(n) }}</div>
                            <div
                                v-if="n.data.message"
                                :style="{
                                    fontSize: '11px',
                                    color: 'var(--fg-dim)',
                                    marginTop: '2px',
                                    lineHeight: 1.4,
                                    overflow: 'hidden',
                                    display: '-webkit-box',
                                    '-webkit-line-clamp': 2,
                                    '-webkit-box-orient': 'vertical',
                                }"
                            >{{ n.data.message }}</div>
                            <div
                                class="cmd-mono"
                                :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginTop: '4px' }"
                            >{{ timeAgo(n.created_at) }}</div>
                        </div>
                        <!-- Delete icon (shown on hover) -->
                        <button
                            type="button"
                            @click.stop="deleteOne(n)"
                            :aria-label="t('notifications.delete')"
                            :style="{
                                background: 'transparent',
                                border: 'none',
                                color: 'var(--fg-mute)',
                                cursor: 'pointer',
                                padding: '4px',
                                borderRadius: '3px',
                                opacity: hoverId === n.id ? 1 : 0,
                                transition: 'opacity 0.12s, color 0.12s',
                                alignSelf: 'start',
                            }"
                            class="cmd-notif-delete"
                        >
                            <Icon name="trash" :size="11" />
                        </button>
                    </li>
                </ul>

                <!-- Footer: link to the full notifications page -->
                <Link
                    :href="notificationsUrl"
                    @click="open = false"
                    :style="{
                        display: 'block',
                        padding: '9px 14px',
                        borderTop: '1px solid var(--border)',
                        textAlign: 'center',
                        fontSize: '11.5px',
                        color: 'var(--fg-dim)',
                        textDecoration: 'none',
                        background: 'var(--panel)',
                    }"
                    class="cmd-notif-viewall"
                >{{ t('notifications.view_all') }}</Link>
            </div>
        </Transition>
        <div
            v-if="open"
            @click="open = false"
            :style="{ position: 'fixed', inset: 0, zIndex: 40 }"
        ></div>
    </div>
</template>

<style scoped>
.cmd-notif-delete:hover {
    color: var(--danger) !important;
}
.cmd-notif-viewall:hover {
    background: var(--row-hover) !important;
    color: var(--fg) !important;
}
</style>
