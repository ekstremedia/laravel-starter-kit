<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from 'primevue/useconfirm';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Icon from '@/Components/Command/Icon.vue';
import Skeleton from '@/Components/Command/Skeleton.vue';
import RoleBadge from '@/Components/Command/RoleBadge.vue';
import CommandDialog from '@/Components/Command/Dialog.vue';
import Field from '@/Components/Command/Field.vue';
import CmdButton from '@/Components/Command/Button.vue';
import { useCommandToasts } from '@/composables/useCommandToasts';

defineOptions({ layout: CommandLayout });

interface WorkspaceRoleCell { id: number; name: string; slug: string; roles: string[] }
interface UserRow {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    created_at: string;
    last_login_at?: string | null;
    avatar_thumb_url: string | null;
    is_super_admin?: boolean;
    workspace_roles: WorkspaceRoleCell[];
    storage_used_bytes: number;
    // Raw user override. null = inherit, -1 = explicit unlimited,
    // 0 = blocked, N>0 = cap. Resolution happens server-side for the
    // main file UI; the admin list only uses it to shade the bar.
    storage_quota_override: number | null;
    banned_at?: string | null;
}

interface Props {
    users: { data: UserRow[]; links: Array<{ url: string | null; label: string; active: boolean }>; current_page?: number; last_page?: number; total?: number; per_page?: number };
    filters: { search: string; sort?: string; direction?: string };
    allRoles: string[];
    userStats: { total: number; active: number };
}

const props = defineProps<Props>();
const { push } = useCommandToasts();
const { t } = useI18n();
const confirmer = useConfirm();

const search = ref(props.filters?.search ?? '');
const sortKey = computed(() => props.filters?.sort ?? 'id');
const sortDir = computed(() => props.filters?.direction ?? 'desc');

function sortBy(key: 'first_name' | 'email' | 'storage_used_bytes') {
    // Toggle direction when clicking the active column; otherwise default to asc.
    const nextDir = sortKey.value === key && sortDir.value === 'asc' ? 'desc' : 'asc';
    router.get(
        '/admin/users',
        { search: search.value || undefined, sort: key, direction: nextDir },
        { preserveState: true, preserveScroll: true, replace: true, only: ['users', 'filters', 'userStats'] },
    );
}
const selected = ref<Set<number>>(new Set());
const hoverId = ref<number | null>(null);
const hoverWorkspaceKey = ref<string | null>(null);
// Data is always pre-rendered by Inertia (and the controller caches the
// payload), so no client-side loading state is needed — the skeleton shimmer
// was purely cosmetic and added a 700ms stutter on every reload.
const loading = ref(false);

const rows = computed(() => props.users?.data ?? []);
const selectedCount = computed(() => selected.value.size);

// Bulk email — compose a subject + message and send it to the selected users.
const emailDialog = ref(false);
const emailSubject = ref('');
const emailMessage = ref('');
const emailSending = ref(false);

function sendBulkEmail() {
    if (!emailSubject.value.trim() || !emailMessage.value.trim()) return;
    emailSending.value = true;
    router.post('/admin/users/bulk-email', {
        user_ids: [...selected.value],
        subject: emailSubject.value,
        message: emailMessage.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            emailDialog.value = false;
            emailSubject.value = '';
            emailMessage.value = '';
            selected.value = new Set();
        },
        onFinish: () => { emailSending.value = false; },
    });
}
const allSelected = computed(() => rows.value.length > 0 && rows.value.every((u) => selected.value.has(u.id)));

function initials(u: UserRow): string {
    return ((u.first_name?.[0] ?? '') + (u.last_name?.[0] ?? '')).toUpperCase() || '??';
}

const AVATAR_PALETTE = ['#4c6fff', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#ec4899', '#14b8a6', '#a855f7', '#f97316'];
function avatarColor(u: UserRow): string {
    return AVATAR_PALETTE[u.id % AVATAR_PALETTE.length];
}

function formatBytes(n: number | null | undefined): string {
    if (n == null || n < 0) return '—';
    if (n === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0; let v = n;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return `${v.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

function storageRatio(u: UserRow): number {
    if (u.storage_quota_override && u.storage_quota_override > 0) {
        return Math.min(1, (u.storage_used_bytes ?? 0) / u.storage_quota_override);
    }
    // Unlimited or inherited quota — fill by using an arbitrary ceiling so
    // the bar shows something. The real effective cap (3-tier) isn't
    // exposed to this list; edit the user to see it.
    const ceiling = 1024 * 1024 * 1024; // 1 GB visual ceiling
    return Math.min(1, (u.storage_used_bytes ?? 0) / ceiling);
}

function lastSeen(u: UserRow): string {
    const iso = u.last_login_at ?? u.created_at;
    if (!iso) return '—';
    const diff = Date.now() - new Date(iso).getTime();
    if (diff < 0) return t('admin.users.last_seen.now');
    const min = Math.floor(diff / 60000);
    if (min < 1) return t('admin.users.last_seen.just_now');
    if (min < 60) return t('admin.users.last_seen.minutes', { n: min });
    const hr = Math.floor(min / 60);
    if (hr < 24) return t('admin.users.last_seen.hours', { n: hr });
    const day = Math.floor(hr / 24);
    if (day < 7) return t('admin.users.last_seen.days', { n: day });
    const week = Math.floor(day / 7);
    if (week < 52) return t('admin.users.last_seen.weeks', { n: week });
    return t('admin.users.last_seen.years', { n: Math.floor(day / 365) });
}

let searchDebounce: ReturnType<typeof setTimeout> | null = null;
watch(search, (v) => {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        router.get(
            '/admin/users',
            { search: v },
            { preserveState: true, replace: true, preserveScroll: true, only: ['users', 'filters', 'userStats'] },
        );
    }, 280);
});

onBeforeUnmount(() => {
    // Prevent post-unmount router.get from snapping the user back to /admin/users
    // when they navigate away while the debounce is still pending.
    if (searchDebounce) clearTimeout(searchDebounce);
});

function toggleOne(u: UserRow) {
    const next = new Set(selected.value);
    if (next.has(u.id)) next.delete(u.id); else next.add(u.id);
    selected.value = next;
}

function toggleAll() {
    if (allSelected.value) {
        selected.value = new Set();
    } else {
        selected.value = new Set(rows.value.map((u) => u.id));
    }
}

function deleteOne(u: UserRow) {
    confirmer.require({
        group: 'command',
        message: t('admin.users.confirm_delete', { name: `${u.first_name} ${u.last_name}` }),
        header: t('common.delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: t('common.delete'),
        rejectLabel: t('common.cancel'),
        accept: () => {
            // Server flashes flash.users.deleted; useFlashToast shows it.
            router.delete(`/admin/users/${u.id}`, { preserveScroll: true });
        },
    });
}

function sendTest(u: UserRow) {
    // Server flashes flash.users.test_notification_sent.
    router.post(`/admin/users/${u.id}/notify-test`, {}, { preserveScroll: true });
}

function unban(u: UserRow) {
    if (!u.banned_at) { push(t('admin.users.toast_not_banned'), 'info'); return; }
    // Server flashes flash.users.unbanned.
    router.post(`/admin/users/${u.id}/unban`, {}, { preserveScroll: true });
}

// Current user id — used to hide the ban action on your own row (the server
// also blocks self-ban and banning another super admin).
const currentUserId = computed(() => (usePage().props as { auth?: { user?: { id?: number } } }).auth?.user?.id ?? null);
function canBan(u: UserRow): boolean {
    return !u.is_super_admin && u.id !== currentUserId.value;
}

function ban(u: UserRow) {
    confirmer.require({
        group: 'command',
        message: t('admin.users.confirm_ban', { name: `${u.first_name} ${u.last_name}` }),
        header: t('admin.users.ban'),
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: t('admin.users.ban'),
        rejectLabel: t('common.cancel'),
        accept: () => {
            // Reason is optional from the list; the detail page offers a reason field.
            router.post(`/admin/users/${u.id}/ban`, {}, { preserveScroll: true });
        },
    });
}

function pageStart(): number {
    const n = rows.value.length;
    if (n === 0) return 0;
    const current = props.users?.current_page ?? 1;
    const per = props.users?.per_page ?? 15;
    return (current - 1) * per + 1;
}
function pageEnd(): number {
    const current = props.users?.current_page ?? 1;
    const per = props.users?.per_page ?? 15;
    return Math.min((props.users?.total ?? 0), (current - 1) * per + rows.value.length);
}

function goToPage(url: string | null) {
    if (!url) return;
    router.visit(url, { preserveState: true, preserveScroll: true, only: ['users', 'filters'] });
}

const prevLink = computed(() => (props.users?.links ?? []).find((l) => l.label.includes('Previous') || l.label.includes('«'))?.url ?? null);
const nextLink = computed(() => (props.users?.links ?? []).find((l) => l.label.includes('Next') || l.label.includes('»'))?.url ?? null);

const rowPadVar = 'var(--pad-row)';
const gridCols = '32px 32px 2fr 2.2fr 1fr 1.2fr 1fr 120px';
</script>

<template>
    <div>
    <Head :title="t('admin.users.head_title')" />

    <!-- Header -->
    <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: '14px' }">
        <div>
            <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">{{ t('admin.users.title') }}</h1>
        </div>
        <div :style="{ display: 'flex', gap: '6px' }">
            <div :style="{ position: 'relative' }">
                <Icon
                    name="search"
                    :size="12"
                    :style="{ position: 'absolute', left: '10px', top: '50%', transform: 'translateY(-50%)', color: 'var(--fg-mute)' }"
                />
                <input
                    v-model="search"
                    :placeholder="t('common.search') + '…'"
                    :style="{
                        background: 'var(--panel2)',
                        border: '1px solid var(--border)',
                        borderRadius: '5px',
                        padding: '5px 10px 5px 28px',
                        color: 'var(--fg)',
                        fontSize: '11.5px',
                        width: '220px',
                        outline: 'none',
                        fontFamily: 'inherit',
                    }"
                />
            </div>
            <Link
                href="/admin/users/create"
                :style="{
                    background: 'var(--accent)',
                    color: '#fff',
                    border: 'none',
                    padding: '5px 11px',
                    borderRadius: '5px',
                    fontSize: '11.5px',
                    fontWeight: 500,
                    cursor: 'pointer',
                    textDecoration: 'none',
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: '5px',
                }"
            >
                <Icon name="plus" :size="12" />
                {{ t('admin.users.new_user') }}
            </Link>
        </div>
    </div>

    <!-- Bulk bar -->
    <div
        v-if="selectedCount > 0"
        :style="{
            padding: '8px 12px',
            background: 'var(--accent-soft)',
            border: '1px solid var(--accent-border)',
            borderRadius: '5px',
            marginBottom: '10px',
            display: 'flex',
            alignItems: 'center',
            gap: '10px',
            fontSize: '11.5px',
        }"
    >
        <span :style="{ color: 'var(--fg)' }">{{ t('admin.users.bulk.selected', { n: selectedCount }) }}</span>
        <button
            type="button"
            @click="emailDialog = true"
            :style="{ background: 'transparent', color: 'var(--fg-dim)', border: '1px solid var(--border)', padding: '3px 8px', borderRadius: '5px', fontSize: '11.5px', cursor: 'pointer', fontFamily: 'inherit', display: 'inline-flex', alignItems: 'center', gap: '5px' }"
        >
            <Icon name="mail" :size="11" />
            {{ t('admin.users.bulk.email') }}
        </button>
        <div :style="{ flex: 1 }"></div>
        <button
            type="button"
            @click="selected = new Set()"
            :style="{ background: 'transparent', color: 'var(--fg-dim)', border: '1px solid var(--border)', padding: '3px 8px', borderRadius: '5px', fontSize: '11.5px', cursor: 'pointer', fontFamily: 'inherit' }"
        >{{ t('common.cancel') }}</button>
    </div>

    <!-- Table -->
    <div class="cmd-card">
      <!-- Desktop: tabular grid. Phones use the stacked card list below. -->
      <div class="cmd-dt-grid">
        <div
            class="cmd-mono cmd-uc"
            :style="{
                display: 'grid',
                gridTemplateColumns: gridCols,
                padding: '8px 16px',
                fontSize: '10px',
                color: 'var(--fg-mute)',
                fontWeight: 500,
                borderBottom: '1px solid var(--border)',
                letterSpacing: '0.06em',
                alignItems: 'center',
            }"
        >
            <div>
                <input
                    type="checkbox"
                    :checked="allSelected"
                    @change="toggleAll"
                    :style="{ accentColor: 'var(--accent)' }"
                />
            </div>
            <div></div>
            <div
                role="button"
                tabindex="0"
                :aria-sort="sortKey === 'first_name' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                :style="{ cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: '4px', userSelect: 'none' }"
                @click="sortBy('first_name')"
                @keydown.enter.prevent="sortBy('first_name')"
                @keydown.space.prevent="sortBy('first_name')"
            >
                <span>{{ t('common.name') }}</span>
                <Icon
                    v-if="sortKey === 'first_name'"
                    name="chevD"
                    :size="9"
                    :style="{ color: 'var(--accent)', transform: sortDir === 'asc' ? 'rotate(180deg)' : 'rotate(0deg)' }"
                />
            </div>
            <div
                role="button"
                tabindex="0"
                :aria-sort="sortKey === 'email' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                :style="{ cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: '4px', userSelect: 'none' }"
                @click="sortBy('email')"
                @keydown.enter.prevent="sortBy('email')"
                @keydown.space.prevent="sortBy('email')"
            >
                <span>{{ t('admin.users.email') }}</span>
                <Icon
                    v-if="sortKey === 'email'"
                    name="chevD"
                    :size="9"
                    :style="{ color: 'var(--accent)', transform: sortDir === 'asc' ? 'rotate(180deg)' : 'rotate(0deg)' }"
                />
            </div>
            <div>{{ t('admin.users.companies_col', 'Arbeidsområder') }}</div>
            <div
                role="button"
                tabindex="0"
                :aria-sort="sortKey === 'storage_used_bytes' ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
                :style="{ cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: '4px', userSelect: 'none' }"
                @click="sortBy('storage_used_bytes')"
                @keydown.enter.prevent="sortBy('storage_used_bytes')"
                @keydown.space.prevent="sortBy('storage_used_bytes')"
            >
                <span>{{ t('common.storage') }}</span>
                <Icon
                    v-if="sortKey === 'storage_used_bytes'"
                    name="chevD"
                    :size="9"
                    :style="{ color: 'var(--accent)', transform: sortDir === 'asc' ? 'rotate(180deg)' : 'rotate(0deg)' }"
                />
            </div>
            <div>{{ t('admin.users.last_seen_col') }}</div>
            <div :style="{ textAlign: 'right' }">{{ t('admin.users.actions') }}</div>
        </div>

        <div
            v-if="loading"
            :style="{ padding: '16px', display: 'flex', flexDirection: 'column', gap: '10px' }"
        >
            <Skeleton v-for="i in 6" :key="i" :width="'100%'" :height="20" :radius="3" />
        </div>

        <template v-else>
            <div
                v-if="rows.length === 0"
                :style="{ padding: '28px 16px', textAlign: 'center', color: 'var(--fg-mute)', fontSize: '12px' }"
            >{{ t('admin.users.no_users_found') }}</div>

            <div
                v-for="u in rows"
                :key="u.id"
                @mouseenter="hoverId = u.id"
                @mouseleave="hoverId = null"
                :style="{
                    display: 'grid',
                    gridTemplateColumns: gridCols,
                    padding: `${rowPadVar} 16px`,
                    alignItems: 'center',
                    fontSize: '12px',
                    borderBottom: '1px solid var(--border)',
                    background: selected.has(u.id) ? 'var(--accent-soft)' : hoverId === u.id ? 'var(--row-hover)' : 'transparent',
                    transition: 'background 0.1s',
                }"
            >
                <div>
                    <input
                        type="checkbox"
                        :checked="selected.has(u.id)"
                        @change="toggleOne(u)"
                        :style="{ accentColor: 'var(--accent)' }"
                    />
                </div>
                <div
                    :style="{
                        width: '22px',
                        height: '22px',
                        borderRadius: '4px',
                        background: avatarColor(u) + '22',
                        color: avatarColor(u),
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        fontSize: '9.5px',
                        fontWeight: 700,
                        fontFamily: 'var(--font-mono)',
                    }"
                >{{ initials(u) }}</div>
                <Link
                    :href="`/admin/users/${u.id}`"
                    :style="{ color: 'var(--fg)', fontWeight: 500, textDecoration: 'none' }"
                >{{ u.first_name }} {{ u.last_name }}</Link>
                <span
                    class="cmd-mono"
                    :style="{ color: 'var(--fg-dim)', fontSize: '11px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }"
                >{{ u.email }}</span>
                <div :style="{ display: 'flex', flexWrap: 'wrap', gap: '4px', alignItems: 'center' }">
                    <RoleBadge v-if="u.is_super_admin" role="SuperAdmin">SUPER</RoleBadge>
                    <span
                        v-for="c in u.workspace_roles"
                        :key="c.id"
                        tabindex="0"
                        class="cmd-mono"
                        :style="{
                            position: 'relative',
                            fontSize: '10.5px',
                            padding: '2px 7px',
                            borderRadius: '3px',
                            background: 'var(--panel2)',
                            color: 'var(--fg-dim)',
                            border: '1px solid var(--border)',
                            cursor: 'default',
                            outline: 'none',
                        }"
                        @mouseenter="hoverWorkspaceKey = `${u.id}-${c.id}`"
                        @mouseleave="hoverWorkspaceKey = null"
                        @focus="hoverWorkspaceKey = `${u.id}-${c.id}`"
                        @blur="hoverWorkspaceKey = null"
                    >
                        {{ c.name }}
                        <!-- Hover tooltip: per-workspace roles for this user. -->
                        <span
                            v-if="hoverWorkspaceKey === `${u.id}-${c.id}`"
                            role="tooltip"
                            :style="{
                                position: 'absolute',
                                bottom: 'calc(100% + 6px)',
                                left: '0',
                                zIndex: 50,
                                background: 'var(--panel2)',
                                border: '1px solid var(--border)',
                                boxShadow: '0 4px 14px rgba(0,0,0,0.3)',
                                borderRadius: '6px',
                                padding: '8px 10px',
                                minWidth: '180px',
                                fontSize: '11px',
                                color: 'var(--fg)',
                                whiteSpace: 'normal',
                                textAlign: 'left',
                                fontFamily: 'var(--font-sans, inherit)',
                            }"
                        >
                            <div
                                class="cmd-mono cmd-uc"
                                :style="{ fontSize: '9.5px', color: 'var(--fg-mute)', letterSpacing: '0.06em', marginBottom: '4px' }"
                            >{{ c.name }} · /w/{{ c.slug }}</div>
                            <div v-if="c.roles.length" :style="{ display: 'flex', flexWrap: 'wrap', gap: '4px' }">
                                <RoleBadge v-for="r in c.roles" :key="r" :role="r" />
                            </div>
                            <span
                                v-else
                                :style="{ fontStyle: 'italic', color: 'var(--fg-mute)' }"
                            >{{ t('admin.users.no_roles_on_workspace', 'Ingen rolle') }}</span>
                        </span>
                    </span>
                    <span
                        v-if="!u.is_super_admin && u.workspace_roles.length === 0"
                        :style="{ fontSize: '11px', color: 'var(--fg-mute)', fontStyle: 'italic' }"
                    >—</span>
                </div>
                <div :style="{ display: 'flex', alignItems: 'center', gap: '8px' }">
                    <div
                        :style="{
                            width: '40px',
                            height: '3px',
                            background: 'var(--border)',
                            borderRadius: '2px',
                            overflow: 'hidden',
                            flexShrink: 0,
                        }"
                    >
                        <div
                            :style="{
                                width: `${storageRatio(u) * 100}%`,
                                height: '100%',
                                background: 'var(--accent)',
                            }"
                        />
                    </div>
                    <span
                        class="cmd-mono"
                        :style="{ fontSize: '10.5px', color: 'var(--fg-dim)' }"
                    >{{ formatBytes(u.storage_used_bytes) }}</span>
                </div>
                <div
                    class="cmd-mono"
                    :style="{ fontSize: '11px', color: 'var(--fg-dim)' }"
                >{{ lastSeen(u) }}</div>
                <div
                    :style="{
                        display: 'flex',
                        gap: '2px',
                        justifyContent: 'flex-end',
                        opacity: hoverId === u.id ? 1 : 0.35,
                        transition: 'opacity 0.1s',
                    }"
                >
                    <Link
                        :href="`/admin/users/${u.id}/edit`"
                        :title="t('common.edit')"
                        :style="{ background: 'transparent', border: 'none', color: 'var(--fg-dim)', cursor: 'pointer', padding: '4px', borderRadius: '3px', display: 'flex', alignItems: 'center', justifyContent: 'center' }"
                    >
                        <Icon name="edit" :size="12" />
                    </Link>
                    <button
                        type="button"
                        :title="t('admin.users.send_test')"
                        @click="sendTest(u)"
                        :style="{ background: 'transparent', border: 'none', color: 'var(--fg-dim)', cursor: 'pointer', padding: '4px', borderRadius: '3px', display: 'flex', alignItems: 'center', justifyContent: 'center' }"
                    >
                        <Icon name="mail" :size="12" />
                    </button>
                    <button
                        v-if="u.banned_at"
                        type="button"
                        :title="t('admin.users.unban')"
                        @click="unban(u)"
                        :style="{ background: 'transparent', border: 'none', color: 'var(--accent)', cursor: 'pointer', padding: '4px', borderRadius: '3px', display: 'flex', alignItems: 'center', justifyContent: 'center' }"
                    >
                        <Icon name="restore" :size="12" />
                    </button>
                    <button
                        v-else-if="canBan(u)"
                        type="button"
                        :title="t('admin.users.ban')"
                        @click="ban(u)"
                        :style="{ background: 'transparent', border: 'none', color: 'var(--fg-dim)', cursor: 'pointer', padding: '4px', borderRadius: '3px', display: 'flex', alignItems: 'center', justifyContent: 'center' }"
                    >
                        <Icon name="shield" :size="12" />
                    </button>
                    <button
                        type="button"
                        :title="t('common.delete')"
                        @click="deleteOne(u)"
                        :style="{ background: 'transparent', border: 'none', color: 'var(--danger)', cursor: 'pointer', padding: '4px', borderRadius: '3px', display: 'flex', alignItems: 'center', justifyContent: 'center' }"
                    >
                        <Icon name="trash" :size="12" />
                    </button>
                </div>
            </div>
        </template>
      </div>

      <!-- Mobile: one card per user. -->
      <div class="cmd-dt-cards">
        <div
            v-if="rows.length === 0"
            :style="{ padding: '28px 16px', textAlign: 'center', color: 'var(--fg-mute)', fontSize: '12px' }"
        >{{ t('admin.users.no_users_found') }}</div>
        <div
            v-for="u in rows"
            :key="`card-${u.id}`"
            class="cmd-dt-card"
            :style="{ background: selected.has(u.id) ? 'var(--accent-soft)' : 'transparent' }"
        >
            <div class="cmd-dt-field">
                <span class="cmd-dt-label">{{ t('common.name') }}</span>
                <span class="cmd-dt-value" :style="{ display: 'flex', alignItems: 'center', gap: '8px', justifyContent: 'flex-end' }">
                    <span :style="{ width: '22px', height: '22px', borderRadius: '4px', background: avatarColor(u) + '22', color: avatarColor(u), display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '9.5px', fontWeight: 700, fontFamily: 'var(--font-mono)', flexShrink: 0 }">{{ initials(u) }}</span>
                    <Link :href="`/admin/users/${u.id}`" :style="{ color: 'var(--fg)', fontWeight: 500, textDecoration: 'none' }">{{ u.first_name }} {{ u.last_name }}</Link>
                </span>
            </div>
            <div class="cmd-dt-field">
                <span class="cmd-dt-label">{{ t('admin.users.email') }}</span>
                <span class="cmd-dt-value cmd-mono" :style="{ fontSize: '11px' }">{{ u.email }}</span>
            </div>
            <div class="cmd-dt-field">
                <span class="cmd-dt-label">{{ t('admin.users.companies_col', 'Arbeidsområder') }}</span>
                <span class="cmd-dt-value" :style="{ display: 'flex', flexWrap: 'wrap', gap: '4px', justifyContent: 'flex-end' }">
                    <RoleBadge v-if="u.is_super_admin" role="SuperAdmin">SUPER</RoleBadge>
                    <span
                        v-for="c in u.workspace_roles"
                        :key="c.id"
                        class="cmd-mono"
                        :style="{ fontSize: '10.5px', padding: '2px 7px', borderRadius: '3px', background: 'var(--panel2)', color: 'var(--fg-dim)', border: '1px solid var(--border)' }"
                    >{{ c.name }}</span>
                    <span
                        v-if="!u.is_super_admin && u.workspace_roles.length === 0"
                        :style="{ fontSize: '11px', color: 'var(--fg-mute)', fontStyle: 'italic' }"
                    >—</span>
                </span>
            </div>
            <div class="cmd-dt-field">
                <span class="cmd-dt-label">{{ t('common.storage') }}</span>
                <span class="cmd-dt-value cmd-mono" :style="{ fontSize: '10.5px' }">{{ formatBytes(u.storage_used_bytes) }}</span>
            </div>
            <div class="cmd-dt-field">
                <span class="cmd-dt-label">{{ t('admin.users.last_seen_col') }}</span>
                <span class="cmd-dt-value cmd-mono" :style="{ fontSize: '11px' }">{{ lastSeen(u) }}</span>
            </div>
            <div class="cmd-dt-card-actions">
                <Link
                    :href="`/admin/users/${u.id}/edit`"
                    :title="t('common.edit')"
                    :style="{ color: 'var(--fg-dim)', padding: '4px', display: 'flex' }"
                ><Icon name="edit" :size="14" /></Link>
                <button
                    type="button"
                    :title="t('admin.users.send_test')"
                    @click="sendTest(u)"
                    :style="{ background: 'transparent', border: 'none', color: 'var(--fg-dim)', cursor: 'pointer', padding: '4px', display: 'flex' }"
                ><Icon name="mail" :size="14" /></button>
                <button
                    v-if="u.banned_at"
                    type="button"
                    :title="t('admin.users.unban')"
                    @click="unban(u)"
                    :style="{ background: 'transparent', border: 'none', color: 'var(--accent)', cursor: 'pointer', padding: '4px', display: 'flex' }"
                ><Icon name="restore" :size="14" /></button>
                <button
                    v-else-if="canBan(u)"
                    type="button"
                    :title="t('admin.users.ban')"
                    @click="ban(u)"
                    :style="{ background: 'transparent', border: 'none', color: 'var(--fg-dim)', cursor: 'pointer', padding: '4px', display: 'flex' }"
                ><Icon name="shield" :size="14" /></button>
                <button
                    type="button"
                    :title="t('common.delete')"
                    @click="deleteOne(u)"
                    :style="{ background: 'transparent', border: 'none', color: 'var(--danger)', cursor: 'pointer', padding: '4px', display: 'flex' }"
                ><Icon name="trash" :size="14" /></button>
            </div>
        </div>
      </div>

        <!-- Footer / pagination -->
        <div
            class="cmd-mono"
            :style="{
                padding: '10px 16px',
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                fontSize: '10.5px',
                color: 'var(--fg-mute)',
            }"
        >
            <span>rows {{ pageStart() }}–{{ pageEnd() }} / {{ users.total ?? rows.length }}</span>
            <div :style="{ display: 'flex', gap: '4px', alignItems: 'center' }">
                <span>page {{ users.current_page ?? 1 }} / {{ users.last_page ?? 1 }}</span>
                <button
                    type="button"
                    :disabled="!prevLink"
                    @click="goToPage(prevLink)"
                    :style="{
                        background: 'transparent',
                        color: prevLink ? 'var(--fg-dim)' : 'var(--fg-mute)',
                        border: '1px solid var(--border)',
                        padding: '2px 7px',
                        borderRadius: '5px',
                        fontSize: '11.5px',
                        cursor: prevLink ? 'pointer' : 'not-allowed',
                        fontFamily: 'inherit',
                    }"
                >‹</button>
                <button
                    type="button"
                    :disabled="!nextLink"
                    @click="goToPage(nextLink)"
                    :style="{
                        background: 'transparent',
                        color: nextLink ? 'var(--fg-dim)' : 'var(--fg-mute)',
                        border: '1px solid var(--border)',
                        padding: '2px 7px',
                        borderRadius: '5px',
                        fontSize: '11.5px',
                        cursor: nextLink ? 'pointer' : 'not-allowed',
                        fontFamily: 'inherit',
                    }"
                >›</button>
            </div>
        </div>
    </div>
    </div>

    <!-- Bulk email composer -->
    <CommandDialog
        :visible="emailDialog"
        :title="t('admin.users.bulk.email_title', { n: selectedCount })"
        width="520px"
        @update:visible="emailDialog = $event"
    >
        <div :style="{ display: 'flex', flexDirection: 'column', gap: '14px' }">
            <Field
                v-model="emailSubject"
                :label="t('admin.users.bulk.email_subject')"
                :placeholder="t('admin.users.bulk.email_subject_placeholder')"
            />
            <div>
                <label
                    for="bulk-email-message"
                    class="cmd-mono cmd-uc"
                    :style="{ display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em', fontWeight: 500 }"
                >{{ t('admin.users.bulk.email_message') }}</label>
                <textarea
                    id="bulk-email-message"
                    v-model="emailMessage"
                    rows="7"
                    maxlength="5000"
                    :placeholder="t('admin.users.bulk.email_message_placeholder')"
                    :style="{
                        width: '100%',
                        background: 'var(--panel2)',
                        border: '1px solid var(--border)',
                        borderRadius: '5px',
                        padding: '8px 10px',
                        color: 'var(--fg)',
                        fontSize: '13px',
                        fontFamily: 'inherit',
                        lineHeight: 1.5,
                        outline: 'none',
                        resize: 'vertical',
                    }"
                />
            </div>
            <p :style="{ margin: 0, fontSize: '11.5px', color: 'var(--fg-mute)', lineHeight: 1.5 }">
                {{ t('admin.users.bulk.email_hint') }}
            </p>
        </div>
        <template #footer>
            <CmdButton variant="ghost" size="sm" @click="emailDialog = false">{{ t('common.cancel') }}</CmdButton>
            <CmdButton
                variant="primary"
                size="sm"
                :loading="emailSending"
                :disabled="!emailSubject.trim() || !emailMessage.trim()"
                @click="sendBulkEmail"
            >{{ t('admin.users.bulk.email_send') }}</CmdButton>
        </template>
    </CommandDialog>
</template>
