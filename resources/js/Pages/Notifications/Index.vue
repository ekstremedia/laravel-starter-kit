<script setup lang="ts">
/*
 * Full notifications page. Reached by navigating to /notifications (or the
 * workspace-scoped /w/{slug}/notifications) — e.g. the "View all" link in the
 * bell. The bell itself fetches the same endpoint with Accept: application/json
 * and gets a compact JSON slice; this page is the HTML rendering.
 */
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Icon from '@/Components/Command/Icon.vue';
import { useWorkspace } from '@/composables/useWorkspace';
import { useUnreadCounts } from '@/composables/useUnreadCounts';
import { useNotificationsStore } from '@/stores/notifications';

defineOptions({ layout: CommandLayout });

interface NotificationItem {
    id: string;
    type: string;
    data: { title?: string; message?: string; icon?: string; action_url?: string };
    read_at: string | null;
    created_at: string;
}

const props = defineProps<{
    notifications: NotificationItem[];
    unread_count: number;
}>();

const { t } = useI18n();
const { workspaceUrl } = useWorkspace();
const { setNotifications, decrementNotifications } = useUnreadCounts();

const items = ref<NotificationItem[]>([...props.notifications]);
const hoverId = ref<string | null>(null);

// Live: the layout's single user-channel subscription feeds this Pinia store,
// so we watch it here (rather than opening a second Echo subscription, which
// would fight the layout's on the same channel). When something new lands,
// re-pull the list and re-sync the local copy.
const notificationsStore = useNotificationsStore();
watch(
    () => notificationsStore.latest?.id,
    (id, prev) => {
        if (!id || id === prev) return;
        router.reload({
            only: ['notifications', 'unread_count'],
            onSuccess: () => { items.value = [...props.notifications]; },
        });
    },
);

// Derive the unread count from local state so the "mark all read" control
// reacts to in-page mutations (mark/clear) — the unread_count prop is only
// the initial server value and goes stale after the first action.
const unreadCount = computed(() => items.value.filter((n) => !n.read_at).length);

const nowTick = ref(Date.now());
let tickHandle: number | undefined;
onMounted(() => { tickHandle = window.setInterval(() => { nowTick.value = Date.now(); }, 30_000); });
onBeforeUnmount(() => { if (tickHandle !== undefined) window.clearInterval(tickHandle); });

function markOneRead(n: NotificationItem) {
    if (n.read_at) return;
    router.post(workspaceUrl(`/notifications/${n.id}/read`), {}, {
        preserveScroll: true,
        onSuccess: () => {
            n.read_at = new Date().toISOString();
            decrementNotifications(1);
        },
    });
}

// Click marks read and, if the notification carries an action_url (e.g. a chat
// message → its conversation), navigates there.
function onNotificationClick(n: NotificationItem) {
    markOneRead(n);
    if (n.data.action_url) router.visit(n.data.action_url);
}

function deleteOne(n: NotificationItem) {
    const wasUnread = !n.read_at;
    router.delete(workspaceUrl(`/notifications/${n.id}`), {
        preserveScroll: true,
        onSuccess: () => {
            items.value = items.value.filter((x) => x.id !== n.id);
            if (wasUnread) decrementNotifications(1);
        },
    });
}

function markAllRead() {
    router.post(workspaceUrl('/notifications/read-all'), {}, {
        preserveScroll: true,
        onSuccess: () => {
            items.value.forEach((n) => { n.read_at = n.read_at ?? new Date().toISOString(); });
            setNotifications(0);
        },
    });
}

function clearAll() {
    router.delete(workspaceUrl('/notifications'), {
        preserveScroll: true,
        onSuccess: () => {
            items.value = [];
            setNotifications(0);
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
    return t('notifications.days_ago', { n: Math.floor(hours / 24) });
}

function title(n: NotificationItem): string {
    return n.data.title || t('notifications.untitled');
}
</script>

<template>
    <Head :title="t('notifications.title')" />

    <div :style="{ maxWidth: '780px', margin: '0 auto', width: '100%' }">
        <div :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', marginBottom: '4px' }">
            <h1 :style="{ fontSize: '22px', fontWeight: 700, letterSpacing: '-0.02em', margin: 0 }">
                {{ t('notifications.title') }}
            </h1>
            <div v-if="items.length > 0" :style="{ display: 'flex', gap: '14px' }">
                <button
                    v-if="unreadCount > 0"
                    type="button"
                    @click="markAllRead"
                    :style="{ background: 'transparent', border: 'none', color: 'var(--accent)', fontSize: '12px', cursor: 'pointer', padding: 0, fontFamily: 'inherit' }"
                >{{ t('notifications.mark_all_read') }}</button>
                <button
                    type="button"
                    @click="clearAll"
                    :style="{ background: 'transparent', border: 'none', color: 'var(--danger)', fontSize: '12px', cursor: 'pointer', padding: 0, fontFamily: 'inherit' }"
                >{{ t('notifications.clear_all') }}</button>
            </div>
        </div>
        <p :style="{ fontSize: '13px', color: 'var(--fg-dim)', margin: '0 0 20px' }">{{ t('notifications.subtitle') }}</p>

        <div
            v-if="items.length === 0"
            class="cmd-card"
            :style="{ padding: '40px 16px', textAlign: 'center', color: 'var(--fg-mute)', fontSize: '13px' }"
        >
            {{ t('notifications.empty') }}
        </div>

        <ul v-else class="cmd-card" :style="{ listStyle: 'none', padding: 0, margin: 0, overflow: 'hidden' }">
            <li
                v-for="(n, i) in items"
                :key="n.id"
                @mouseenter="hoverId = n.id"
                @mouseleave="hoverId = null"
                @click="onNotificationClick(n)"
                :style="{
                    display: 'grid',
                    gridTemplateColumns: '10px 1fr auto',
                    gap: '12px',
                    padding: '13px 16px',
                    borderTop: i === 0 ? 'none' : '1px solid var(--border)',
                    cursor: n.read_at ? 'default' : 'pointer',
                    background: hoverId === n.id ? 'var(--row-hover)' : 'transparent',
                    transition: 'background 0.1s',
                    opacity: n.read_at ? 0.65 : 1,
                }"
            >
                <span
                    :style="{
                        width: '6px', height: '6px', borderRadius: '50%',
                        background: n.read_at ? 'transparent' : 'var(--accent)',
                        marginTop: '6px', justifySelf: 'center',
                    }"
                />
                <div :style="{ minWidth: 0 }">
                    <div :style="{ fontSize: '13px', fontWeight: n.read_at ? 400 : 600, color: 'var(--fg)' }">{{ title(n) }}</div>
                    <div
                        v-if="n.data.message"
                        :style="{ fontSize: '12px', color: 'var(--fg-dim)', marginTop: '3px', lineHeight: 1.5 }"
                    >{{ n.data.message }}</div>
                    <div class="cmd-mono" :style="{ fontSize: '10.5px', color: 'var(--fg-mute)', marginTop: '5px' }">{{ timeAgo(n.created_at) }}</div>
                </div>
                <button
                    type="button"
                    @click.stop="deleteOne(n)"
                    :aria-label="t('notifications.delete')"
                    class="cmd-notif-delete"
                    :style="{
                        background: 'transparent', border: 'none', color: 'var(--fg-mute)',
                        cursor: 'pointer', padding: '4px', borderRadius: '3px', alignSelf: 'start',
                        opacity: hoverId === n.id ? 1 : 0, transition: 'opacity 0.12s, color 0.12s',
                    }"
                >
                    <Icon name="trash" :size="12" />
                </button>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.cmd-notif-delete:hover {
    color: var(--danger) !important;
}
</style>
