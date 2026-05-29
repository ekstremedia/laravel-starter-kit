<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CmdDataTable, { type Column as CmdColumn } from '@/Components/Command/DataTable.vue';
import Icon from '@/Components/Command/Icon.vue';
import PageTitle from '@/Components/Command/PageTitle.vue';
import { formatDateTime } from '@/composables/useDateTime';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();

interface Causer { id: number; email: string; first_name: string; last_name: string }
interface Activity {
    id: number;
    log_name: string | null;
    description: string;
    event: string | null;
    subject_type: string | null;
    subject_id: number | null;
    causer: Causer | null;
    created_at: string;
    properties: any;
}
interface PaginatedActivities {
    data: Activity[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    total?: number;
    current_page?: number;
    last_page?: number;
    per_page?: number;
}

interface Props {
    tab: 'activity' | 'logs' | 'pulse' | 'horizon';
    activities?: PaginatedActivities | null;
    filters: {
        user_id?: number | null;
        log_name?: string | null;
        event?: string | null;
        date_from?: string | null;
        date_to?: string | null;
    };
    users?: Causer[];
    logNames?: string[];
    events?: string[];
    endpoints: { logs: string; pulse: string; horizon: string };
}
const props = withDefaults(defineProps<Props>(), {
    activities: null,
    users: () => [],
    logNames: () => [],
    events: () => [],
});

const activeTab = ref<Props['tab']>(props.tab);

watch(activeTab, (next) => {
    if (next === props.tab) return;
    router.get('/admin/monitoring', { tab: next }, { preserveState: true, preserveScroll: true, replace: true });
});

const f = ref({
    user_id: props.filters.user_id ?? null as number | null,
    log_name: props.filters.log_name ?? null as string | null,
    event: props.filters.event ?? null as string | null,
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
});

function apply() {
    router.get(
        '/admin/monitoring',
        {
            tab: 'activity',
            user_id: f.value.user_id || undefined,
            log_name: f.value.log_name || undefined,
            event: f.value.event || undefined,
            date_from: f.value.date_from || undefined,
            date_to: f.value.date_to || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function reset() {
    f.value = { user_id: null, log_name: null, event: null, date_from: '', date_to: '' };
    apply();
}

interface TabItem { key: Props['tab']; label: string; external?: string }

const tabs = computed<TabItem[]>(() => [
    { key: 'activity', label: t('admin.monitoring.tab_activity') },
    { key: 'logs', label: t('admin.monitoring.tab_logs'), external: props.endpoints.logs },
    { key: 'pulse', label: t('admin.monitoring.tab_pulse'), external: props.endpoints.pulse },
    { key: 'horizon', label: t('admin.monitoring.tab_horizon'), external: props.endpoints.horizon },
]);

const iframeUrl = computed(() => {
    if (activeTab.value === 'logs') return props.endpoints.logs;
    if (activeTab.value === 'pulse') return props.endpoints.pulse;
    if (activeTab.value === 'horizon') return props.endpoints.horizon;
    return null;
});

// Rows for Cmd table — identity mapping to get stable id.
const rows = computed(() => props.activities?.data ?? []);

const columns: CmdColumn<Activity>[] = [
    { key: 'created_at', label: t('admin.activity.when'), sortable: true, width: '150px', mono: true },
    { key: 'causer', label: t('admin.activity.user'), sortable: true, width: '200px', getter: (r) => r.causer?.email ?? '—' },
    { key: 'log_name', label: t('admin.activity.log_name'), sortable: true, width: '120px' },
    { key: 'event', label: t('admin.activity.event'), sortable: true, width: '140px' },
    { key: 'description', label: t('admin.activity.description'), sortable: true },
    { key: 'subject', label: t('admin.activity.subject'), width: '140px', getter: (r) => r.subject_type ? `${r.subject_type.split('\\').pop()}#${r.subject_id}` : '' },
];

const search = ref('');
const sortKey = ref('created_at');
const sortDir = ref<'asc' | 'desc'>('desc');

const inputStyle = {
    background: 'var(--panel2)',
    border: '1px solid var(--border)',
    borderRadius: '5px',
    padding: '6px 10px',
    color: 'var(--fg)',
    fontSize: '11.5px',
    outline: 'none',
    fontFamily: 'inherit',
    width: '100%',
} as const;
</script>

<template>
    <div>
        <Head :title="t('admin.monitoring.head_title')" />

        <PageTitle :title="t('admin.monitoring.title')" :subtitle="t('admin.monitoring.description')" />

    <!-- Tabs -->
    <div
        :style="{
            display: 'flex',
            gap: '2px',
            marginBottom: '16px',
            borderBottom: '1px solid var(--border)',
        }"
    >
        <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            @click="activeTab = tab.key"
            :style="{
                background: 'transparent',
                border: 'none',
                borderBottom: activeTab === tab.key ? '2px solid var(--accent)' : '2px solid transparent',
                padding: '8px 14px',
                marginBottom: '-1px',
                fontSize: '12px',
                fontWeight: activeTab === tab.key ? 500 : 400,
                color: activeTab === tab.key ? 'var(--fg)' : 'var(--fg-dim)',
                cursor: 'pointer',
                fontFamily: 'inherit',
            }"
        >{{ tab.label }}</button>
    </div>

    <!-- Activity tab -->
    <div v-if="activeTab === 'activity'">
        <!-- Filters -->
        <div
            class="cmd-card"
            :style="{
                padding: '12px',
                marginBottom: '12px',
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))',
                gap: '8px',
                alignItems: 'end',
            }"
        >
            <select v-model="f.user_id" :style="inputStyle">
                <option :value="null">{{ t('admin.activity.user_all') }}</option>
                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.email }}</option>
            </select>
            <select v-model="f.log_name" :style="inputStyle">
                <option :value="null">{{ t('admin.activity.log_name_all') }}</option>
                <option v-for="l in logNames" :key="l" :value="l">{{ l }}</option>
            </select>
            <select v-model="f.event" :style="inputStyle">
                <option :value="null">{{ t('admin.activity.event_all') }}</option>
                <option v-for="e in events" :key="e" :value="e">{{ e }}</option>
            </select>
            <input type="date" v-model="f.date_from" :placeholder="t('admin.activity.from')" :style="inputStyle" />
            <input type="date" v-model="f.date_to" :placeholder="t('admin.activity.to')" :style="inputStyle" />
            <div :style="{ display: 'flex', gap: '6px' }">
                <button
                    type="button"
                    @click="apply"
                    :style="{ background: 'var(--accent)', color: '#fff', border: 'none', padding: '6px 11px', borderRadius: '5px', fontSize: '11.5px', fontWeight: 500, cursor: 'pointer', fontFamily: 'inherit' }"
                >{{ t('admin.activity.apply') }}</button>
                <button
                    type="button"
                    @click="reset"
                    :style="{ background: 'transparent', color: 'var(--fg-dim)', border: '1px solid var(--border)', padding: '6px 11px', borderRadius: '5px', fontSize: '11.5px', cursor: 'pointer', fontFamily: 'inherit' }"
                >{{ t('admin.activity.reset') }}</button>
            </div>
        </div>

        <CmdDataTable
            :rows="rows"
            :columns="columns"
            v-model:search="search"
            v-model:sort-key="sortKey"
            v-model:sort-dir="sortDir"
            :search-placeholder="t('admin.activity.search_placeholder')"
            :search-keys="['description', 'event', 'log_name']"
            :empty-text="t('admin.activity.empty')"
            :local-sort="false"
            :local-search="true"
        >
            <template #cell:created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
            <template #cell:causer="{ row }">
                <span v-if="row.causer" :style="{ color: 'var(--fg)' }">{{ row.causer.email }}</span>
                <span v-else :style="{ color: 'var(--fg-mute)', fontStyle: 'italic' }">{{ t('admin.activity.system') }}</span>
            </template>
            <template #cell:log_name="{ row }">
                <span
                    v-if="row.log_name"
                    class="cmd-mono"
                    :style="{ fontSize: '10.5px', color: 'var(--accent)', background: 'var(--accent-soft)', border: '1px solid var(--accent-border)', padding: '1px 7px', borderRadius: '3px' }"
                >{{ row.log_name }}</span>
            </template>
            <template #cell:event="{ row }">
                <span
                    v-if="row.event"
                    class="cmd-mono"
                    :style="{ fontSize: '10.5px', color: 'var(--fg-dim)', background: 'var(--panel2)', border: '1px solid var(--border)', padding: '1px 7px', borderRadius: '3px' }"
                >{{ row.event }}</span>
            </template>
            <template #cell:subject="{ row }">
                <span v-if="row.subject_type" class="cmd-mono" :style="{ fontSize: '10.5px', color: 'var(--fg-mute)' }">
                    {{ row.subject_type.split('\\').pop() }}#{{ row.subject_id }}
                </span>
            </template>
        </CmdDataTable>
    </div>

    <!-- Embedded dashboards -->
    <div v-else class="cmd-card" :style="{ overflow: 'hidden' }">
        <div
            :style="{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                padding: '10px 14px',
                borderBottom: '1px solid var(--border)',
                background: 'var(--panel2)',
            }"
        >
            <span :style="{ fontSize: '11px', color: 'var(--fg-dim)' }">{{ t('admin.monitoring.iframe_hint') }}</span>
            <a
                v-if="iframeUrl"
                :href="iframeUrl"
                target="_blank"
                rel="noopener"
                :style="{ display: 'inline-flex', alignItems: 'center', gap: '5px', fontSize: '11.5px', color: 'var(--accent)', textDecoration: 'none' }"
            >
                {{ t('admin.monitoring.open_new_tab') }}
                <Icon name="arrow" :size="11" />
            </a>
        </div>
        <iframe
            v-if="iframeUrl"
            :key="iframeUrl"
            :src="iframeUrl"
            :title="activeTab"
            :style="{ width: '100%', height: 'calc(100vh - 16rem)', minHeight: '520px', background: '#fff', border: 'none' }"
            loading="lazy"
        />
    </div>
    </div>
</template>
