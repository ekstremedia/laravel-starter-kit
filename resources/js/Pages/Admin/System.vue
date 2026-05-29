<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Icon, { type IconName } from '@/Components/Command/Icon.vue';
import Dot from '@/Components/Command/Dot.vue';
import PageTitle from '@/Components/Command/PageTitle.vue';
import { formatDateTime } from '@/composables/useDateTime';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();

interface Props {
    php: Record<string, string>;
    system: Record<string, string>;
    laravel: Record<string, string | boolean>;
    drivers: Record<string, string>;
    cache_status: Record<string, boolean>;
    extensions: string[];
    health: {
        queue: { last: { nonce: string; at: string } | null; driver: string };
        broadcast: { driver: string };
        redis: { ok: boolean; pong?: string; error?: string };
    };
}
const props = defineProps<Props>();

const queueLast = ref(props.health.queue.last);
const broadcastLast = ref<{ nonce: string; at: string } | null>(null);
const extensionsOpen = ref(false);

function pingQueue() {
    router.post('/admin/health/queue', {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => pollQueue(),
    });
}

function pingBroadcast() {
    router.post('/admin/health/broadcast', {}, { preserveScroll: true, preserveState: true });
}

async function pollQueue() {
    for (let i = 0; i < 10; i++) {
        await new Promise((r) => setTimeout(r, 400));
        const res = await fetch('/admin/health/queue-last', { headers: { Accept: 'application/json' } });
        if (!res.ok) break;
        const json = await res.json();
        if (json.last && json.last.nonce !== queueLast.value?.nonce) {
            queueLast.value = json.last;
            break;
        }
    }
}

let echoChannel: any = null;
onMounted(() => {
    const echo = (window as any).Echo;
    if (echo) {
        echoChannel = echo.private('admin.health').listen('.ping', (e: { nonce: string; at: string }) => {
            broadcastLast.value = e;
        });
    }
});
onUnmounted(() => {
    const echo = (window as any).Echo;
    if (echo && echoChannel) echo.leave('admin.health');
});

const driverIcons: Record<string, IconName> = {
    broadcast: 'server',
    cache: 'key',
    database: 'disk',
    logs: 'log',
    mail: 'mail',
    queue: 'server',
    session: 'user',
    filesystem: 'disk',
};

function chipStyle(tone: 'success' | 'warning' | 'danger' | 'info' | 'muted') {
    const colorMap: Record<string, { color: string; bg: string; border: string }> = {
        success: { color: 'var(--success)', bg: 'rgba(94,229,154,0.12)', border: 'rgba(94,229,154,0.33)' },
        warning: { color: 'var(--warning)', bg: 'rgba(251,191,36,0.12)', border: 'rgba(251,191,36,0.33)' },
        danger: { color: 'var(--danger)', bg: 'rgba(255,138,138,0.12)', border: 'rgba(255,138,138,0.33)' },
        info: { color: 'var(--accent)', bg: 'var(--accent-soft)', border: 'var(--accent-border)' },
        muted: { color: 'var(--fg-dim)', bg: 'var(--panel2)', border: 'var(--border)' },
    };
    const c = colorMap[tone];
    return {
        fontSize: '10.5px',
        color: c.color,
        background: c.bg,
        border: `1px solid ${c.border}`,
        padding: '2px 7px',
        borderRadius: '3px',
    };
}
</script>

<template>
    <div>
    <Head :title="t('admin.system.head_title')" />

    <PageTitle :title="t('admin.system.title')" :subtitle="t('admin.system.desc')" />

    <!-- Health cards: queue / broadcast / redis -->
    <section :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: '16px', marginBottom: '20px' }">
        <div class="cmd-card" :style="{ padding: '16px' }">
            <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '12px' }">
                <div :style="{ display: 'flex', alignItems: 'center', gap: '10px' }">
                    <div :style="{ width: '30px', height: '30px', borderRadius: '6px', background: 'var(--accent-soft)', border: '1px solid var(--accent-border)', color: 'var(--accent)', display: 'flex', alignItems: 'center', justifyContent: 'center' }">
                        <Icon name="server" :size="14" />
                    </div>
                    <div>
                        <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">{{ t('admin.system.queue') }}</div>
                        <div :style="{ fontSize: '11px', color: 'var(--fg-mute)' }">{{ t('admin.system.queue_driver') }}</div>
                    </div>
                </div>
                <span :style="chipStyle('info')">{{ health.queue.driver }}</span>
            </div>
            <div
                class="cmd-mono cmd-uc"
                :style="{ fontSize: '9.5px', color: 'var(--fg-mute)', marginBottom: '5px', fontWeight: 500 }"
            >{{ t('admin.system.last_ping') }}</div>
            <div v-if="queueLast" class="cmd-mono" :style="{ fontSize: '11.5px', color: 'var(--fg)' }">
                {{ formatDateTime(queueLast.at) }}
                <div :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginTop: '2px' }">{{ queueLast.nonce }}</div>
            </div>
            <div v-else :style="{ fontSize: '12px', color: 'var(--fg-mute)', fontStyle: 'italic' }">
                {{ t('admin.system.no_pings') }}
            </div>
            <button
                type="button"
                @click="pingQueue"
                :style="{ width: '100%', marginTop: '14px', background: 'transparent', color: 'var(--fg-dim)', border: '1px solid var(--border)', padding: '6px 11px', borderRadius: '5px', fontSize: '11.5px', cursor: 'pointer', fontFamily: 'inherit' }"
            >{{ t('admin.system.dispatch_ping') }}</button>
        </div>

        <div class="cmd-card" :style="{ padding: '16px' }">
            <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '12px' }">
                <div :style="{ display: 'flex', alignItems: 'center', gap: '10px' }">
                    <div :style="{ width: '30px', height: '30px', borderRadius: '6px', background: 'rgba(14,165,233,0.1)', border: '1px solid rgba(14,165,233,0.3)', color: '#0ea5e9', display: 'flex', alignItems: 'center', justifyContent: 'center' }">
                        <Icon name="bell" :size="14" />
                    </div>
                    <div>
                        <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">{{ t('admin.system.broadcast') }}</div>
                        <div :style="{ fontSize: '11px', color: 'var(--fg-mute)' }">{{ t('admin.system.broadcast_driver') }}</div>
                    </div>
                </div>
                <span :style="chipStyle('info')">{{ health.broadcast.driver }}</span>
            </div>
            <div
                class="cmd-mono cmd-uc"
                :style="{ fontSize: '9.5px', color: 'var(--fg-mute)', marginBottom: '5px', fontWeight: 500 }"
            >{{ t('admin.system.last_event') }}</div>
            <div v-if="broadcastLast" class="cmd-mono" :style="{ fontSize: '11.5px', color: 'var(--fg)' }">
                {{ formatDateTime(broadcastLast.at) }}
                <div :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginTop: '2px' }">{{ broadcastLast.nonce }}</div>
            </div>
            <div v-else :style="{ fontSize: '12px', color: 'var(--fg-mute)', fontStyle: 'italic' }">
                {{ t('admin.system.listening_on') }} <code class="cmd-mono">admin.health</code>…
            </div>
            <button
                type="button"
                @click="pingBroadcast"
                :style="{ width: '100%', marginTop: '14px', background: 'transparent', color: 'var(--fg-dim)', border: '1px solid var(--border)', padding: '6px 11px', borderRadius: '5px', fontSize: '11.5px', cursor: 'pointer', fontFamily: 'inherit' }"
            >{{ t('admin.system.broadcast_ping') }}</button>
        </div>

        <div class="cmd-card" :style="{ padding: '16px' }">
            <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '12px' }">
                <div :style="{ display: 'flex', alignItems: 'center', gap: '10px' }">
                    <div :style="{ width: '30px', height: '30px', borderRadius: '6px', background: 'rgba(255,138,138,0.1)', border: '1px solid rgba(255,138,138,0.3)', color: 'var(--danger)', display: 'flex', alignItems: 'center', justifyContent: 'center' }">
                        <Icon name="disk" :size="14" />
                    </div>
                    <div>
                        <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">{{ t('admin.system.redis') }}</div>
                        <div :style="{ fontSize: '11px', color: 'var(--fg-mute)' }">{{ t('admin.system.redis_driver') }}</div>
                    </div>
                </div>
                <span :style="chipStyle(health.redis.ok ? 'success' : 'danger')">
                    {{ health.redis.ok ? t('admin.system.connected') : t('admin.system.down') }}
                </span>
            </div>
            <div
                class="cmd-mono cmd-uc"
                :style="{ fontSize: '9.5px', color: 'var(--fg-mute)', marginBottom: '5px', fontWeight: 500 }"
            >PING</div>
            <div v-if="health.redis.ok" class="cmd-mono" :style="{ fontSize: '22px', fontWeight: 600, color: 'var(--success)' }">
                → {{ health.redis.pong }}
            </div>
            <div v-else class="cmd-mono" :style="{ fontSize: '12px', color: 'var(--danger)' }">
                {{ health.redis.error }}
            </div>
        </div>
    </section>

    <!-- Key-stat strip: PHP / Laravel / host / extensions -->
    <section
        :style="{
            display: 'grid',
            gridTemplateColumns: 'repeat(4, minmax(0, 1fr))',
            gap: '1px',
            background: 'var(--border)',
            border: '1px solid var(--border)',
            borderRadius: 'var(--radius-card)',
            overflow: 'hidden',
            marginBottom: '20px',
        }"
    >
        <div
            v-for="kpi in [
                { label: t('admin.system.php'), value: php.version, hint: php.sapi },
                { label: t('admin.system.laravel'), value: laravel.version, hint: `${laravel.environment}${laravel.debug ? ' · debug' : ''}` },
                { label: t('admin.system.host'), value: system.hostname, hint: system.os },
                { label: t('admin.system.extensions'), value: extensions.length, hint: 'loaded' },
            ]"
            :key="String(kpi.label)"
            :style="{ background: 'var(--panel)', padding: '14px 16px' }"
        >
            <div class="cmd-mono cmd-uc" :style="{ fontSize: '9.5px', color: 'var(--fg-mute)', marginBottom: '6px', fontWeight: 500 }">{{ kpi.label }}</div>
            <div class="cmd-mono" :style="{ fontSize: '18px', fontWeight: 600, color: 'var(--fg)', letterSpacing: '-0.01em', lineHeight: 1.1, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }">{{ kpi.value }}</div>
            <div :style="{ fontSize: '10.5px', color: 'var(--fg-mute)', marginTop: '4px' }">{{ kpi.hint }}</div>
        </div>
    </section>

    <!-- Drivers grid -->
    <section :style="{ marginBottom: '20px' }">
        <h2
            class="cmd-mono cmd-uc"
            :style="{ fontSize: '10px', color: 'var(--fg-mute)', fontWeight: 500, marginBottom: '10px', letterSpacing: '0.06em' }"
        >{{ t('admin.system.drivers') }}</h2>
        <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '10px' }">
            <div
                v-for="(v, k) in drivers"
                :key="k"
                class="cmd-card"
                :style="{ display: 'flex', alignItems: 'center', gap: '12px', padding: '12px' }"
            >
                <div :style="{ width: '30px', height: '30px', borderRadius: '6px', background: 'var(--accent-soft)', border: '1px solid var(--accent-border)', color: 'var(--accent)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }">
                    <Icon :name="(driverIcons[k] || 'cog') as IconName" :size="14" />
                </div>
                <div :style="{ minWidth: 0 }">
                    <div class="cmd-mono cmd-uc" :style="{ fontSize: '9.5px', color: 'var(--fg-mute)' }">{{ k }}</div>
                    <div class="cmd-mono" :style="{ fontSize: '12px', color: 'var(--fg)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">{{ v }}</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Cache + PHP limits + Laravel detail -->
    <section :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '16px', marginBottom: '20px' }">
        <div class="cmd-card" :style="{ padding: '16px' }">
            <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)', marginBottom: '12px' }">
                {{ t('admin.system.cache_status') }}
            </div>
            <ul :style="{ listStyle: 'none', padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: '8px' }">
                <li
                    v-for="(v, k) in cache_status"
                    :key="k"
                    :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: '12px', textTransform: 'capitalize', color: 'var(--fg)' }"
                >
                    <span>{{ k }}</span>
                    <span :style="chipStyle(v ? 'success' : 'muted')">
                        {{ v ? t('admin.system.cached') : t('admin.system.not_cached') }}
                    </span>
                </li>
            </ul>
        </div>

        <div class="cmd-card" :style="{ padding: '16px' }">
            <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)', marginBottom: '12px' }">
                {{ t('admin.system.php_limits') }}
            </div>
            <dl :style="{ display: 'flex', flexDirection: 'column', gap: '7px', margin: 0, fontSize: '12px' }">
                <div v-for="row in [
                    { label: t('admin.system.upload_max'), value: php.upload_max_filesize },
                    { label: t('admin.system.post_max'), value: php.post_max_size },
                    { label: t('admin.system.memory_limit'), value: php.memory_limit },
                    { label: t('admin.system.execution_time'), value: `${php.max_execution_time}s` },
                    { label: t('admin.system.max_uploads'), value: php.max_file_uploads },
                    { label: t('admin.system.zend'), value: php.zend_version },
                ]" :key="row.label" :style="{ display: 'flex', justifyContent: 'space-between' }">
                    <dt :style="{ color: 'var(--fg-mute)' }">{{ row.label }}</dt>
                    <dd class="cmd-mono" :style="{ color: 'var(--fg)', margin: 0 }">{{ row.value }}</dd>
                </div>
            </dl>
        </div>

        <div class="cmd-card" :style="{ padding: '16px' }">
            <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)', marginBottom: '12px' }">
                {{ t('admin.system.laravel_runtime') }}
            </div>
            <dl :style="{ display: 'flex', flexDirection: 'column', gap: '7px', margin: 0, fontSize: '12px' }">
                <div :style="{ display: 'flex', justifyContent: 'space-between' }"><dt :style="{ color: 'var(--fg-mute)' }">{{ t('admin.system.app_name') }}</dt><dd class="cmd-mono" :style="{ color: 'var(--fg)', margin: 0, maxWidth: '60%', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">{{ laravel.app_name }}</dd></div>
                <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }"><dt :style="{ color: 'var(--fg-mute)' }">{{ t('admin.system.environment') }}</dt><dd :style="{ margin: 0 }"><span :style="chipStyle(laravel.environment === 'production' ? 'danger' : 'success')">{{ laravel.environment }}</span></dd></div>
                <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }"><dt :style="{ color: 'var(--fg-mute)' }">{{ t('admin.system.debug') }}</dt><dd :style="{ margin: 0 }"><span :style="chipStyle(laravel.debug ? 'warning' : 'success')">{{ laravel.debug ? t('admin.system.debug_on') : t('admin.system.debug_off') }}</span></dd></div>
                <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }"><dt :style="{ color: 'var(--fg-mute)' }">{{ t('admin.system.maintenance_mode') }}</dt><dd :style="{ margin: 0 }"><span :style="chipStyle(laravel.maintenance ? 'warning' : 'muted')">{{ laravel.maintenance ? t('admin.system.debug_on') : t('admin.system.debug_off') }}</span></dd></div>
                <div :style="{ display: 'flex', justifyContent: 'space-between' }"><dt :style="{ color: 'var(--fg-mute)' }">{{ t('admin.system.timezone') }}</dt><dd class="cmd-mono" :style="{ color: 'var(--fg)', margin: 0 }">{{ laravel.timezone }}</dd></div>
                <div :style="{ display: 'flex', justifyContent: 'space-between' }"><dt :style="{ color: 'var(--fg-mute)' }">{{ t('admin.system.locale') }}</dt><dd class="cmd-mono" :style="{ color: 'var(--fg)', margin: 0 }">{{ laravel.locale }}</dd></div>
                <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }"><dt :style="{ color: 'var(--fg-mute)' }">{{ t('admin.system.storage_link') }}</dt><dd :style="{ margin: 0 }"><span :style="chipStyle(laravel.storage_linked ? 'success' : 'warning')">{{ laravel.storage_linked ? t('admin.system.linked') : t('admin.system.missing') }}</span></dd></div>
            </dl>
        </div>
    </section>

    <!-- Host + extensions -->
    <section :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: '16px' }">
        <div class="cmd-card" :style="{ padding: '16px' }">
            <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)', marginBottom: '12px' }">
                {{ t('admin.system.host_section') }}
            </div>
            <dl :style="{ display: 'flex', flexDirection: 'column', gap: '7px', margin: 0, fontSize: '12px' }">
                <div v-for="row in [
                    { label: t('admin.system.os_family'), value: system.os_family },
                    { label: t('admin.system.hostname'), value: system.hostname },
                    { label: t('admin.system.server_software'), value: system.server_software },
                    { label: t('admin.system.document_root'), value: system.document_root },
                    { label: t('admin.system.app_url'), value: laravel.url },
                    { label: t('admin.system.ini_file'), value: php.ini_loaded_file },
                ]" :key="row.label" :style="{ display: 'flex', justifyContent: 'space-between', gap: '10px' }">
                    <dt :style="{ color: 'var(--fg-mute)', flexShrink: 0 }">{{ row.label }}</dt>
                    <dd class="cmd-mono" :style="{ color: 'var(--fg)', margin: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', fontSize: '11px' }">{{ row.value }}</dd>
                </div>
            </dl>
        </div>

        <div class="cmd-card" :style="{ padding: '16px' }">
            <div :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '12px' }">
                <div :style="{ fontSize: '13px', fontWeight: 600, color: 'var(--fg)', display: 'flex', alignItems: 'center', gap: '8px' }">
                    {{ t('admin.system.loaded_extensions') }}
                    <span :style="chipStyle('muted')">{{ extensions.length }}</span>
                </div>
                <button
                    type="button"
                    @click="extensionsOpen = !extensionsOpen"
                    :style="{ background: 'transparent', border: 'none', color: 'var(--accent)', cursor: 'pointer', fontSize: '11.5px', fontFamily: 'inherit' }"
                >{{ extensionsOpen ? t('admin.system.hide') : t('admin.system.show_all') }}</button>
            </div>
            <div :style="{ display: 'flex', flexWrap: 'wrap', gap: '4px', maxHeight: extensionsOpen ? '280px' : 'auto', overflowY: extensionsOpen ? 'auto' : 'visible' }">
                <span
                    v-for="e in extensionsOpen ? extensions : extensions.slice(0, 24)"
                    :key="e"
                    class="cmd-mono"
                    :style="{ fontSize: '10px', padding: '1px 6px', background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: '3px', color: 'var(--fg-dim)' }"
                >{{ e }}</span>
                <span
                    v-if="!extensionsOpen && extensions.length > 24"
                    class="cmd-mono"
                    :style="{ fontSize: '10px', padding: '1px 6px', color: 'var(--fg-mute)' }"
                >{{ t('admin.system.more', { n: extensions.length - 24 }) }}</span>
            </div>
        </div>
    </section>
    </div>
</template>
