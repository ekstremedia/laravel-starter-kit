<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Dot from '@/Components/Command/Dot.vue';
import Icon from '@/Components/Command/Icon.vue';
import Skeleton from '@/Components/Command/Skeleton.vue';
import { useCommandToasts } from '@/composables/useCommandToasts';

defineOptions({ layout: CommandLayout });

interface WorkspaceRoleCell {
    id: number;
    name: string;
    slug: string;
    roles: string[];
}

interface UserDetail {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    email_verified_at: string | null;
    two_factor_enabled: boolean;
    is_super_admin: boolean;
    workspace_roles: WorkspaceRoleCell[];
    created_at: string | null;
}

interface ActivityRow {
    id: number;
    created_at: string | null;
    description: string;
    event: string | null;
    log_name: string | null;
}

interface Props {
    userDetail: UserDetail;
    activity: ActivityRow[];
}

const props = defineProps<Props>();
const { push } = useCommandToasts();
const { t } = useI18n();

// "Primary" role surfaces SuperAdmin first (platform-level), then the first
// workspace-scoped role we know about (Admin on any workspace beats Editor on
// any workspace, beats User). The match is on the raw identifier; the return
// is a translated label so the badge respects the user's locale. Falls back
// to `home.role_fallback` for accounts with no workspace role yet.
const primaryRole = computed(() => {
    if (props.userDetail.is_super_admin) return t('roles.super_admin');
    const ranking: Array<['Admin' | 'Editor' | 'User', string]> = [
        ['Admin', 'roles.admin'],
        ['Editor', 'roles.editor'],
        ['User', 'roles.user'],
    ];
    for (const [id, key] of ranking) {
        if (props.userDetail.workspace_roles.some((c) => c.roles.includes(id))) return t(key);
    }
    return t('home.role_fallback');
});

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleDateString('nb-NO', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatTime(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleTimeString('nb-NO', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

const headerMeta = computed(() => {
    const now = new Date();
    const date = now.toLocaleDateString('nb-NO', { day: '2-digit', month: '2-digit', year: 'numeric' });
    const time = now.toLocaleTimeString('nb-NO', { hour: '2-digit', minute: '2-digit', timeZoneName: 'short' });
    return `${date} · ${time}`;
});

function openProfile() {
    push(t('home.opening_profile'), 'info');
    router.visit('/profile');
}
</script>

<template>
    <div>
        <Head :title="t('home.title')" />

        <div :style="{ padding: '32px 40px' }">
            <div :style="{ maxWidth: '780px', margin: '0 auto' }">
            <div
                class="cmd-mono"
                :style="{
                    fontSize: '10.5px',
                    color: 'var(--fg-mute)',
                    marginBottom: '10px',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '10px',
                }"
            >
                <span>{{ headerMeta }}</span>
                <span>·</span>
                <span :style="{ display: 'flex', alignItems: 'center', gap: '4px' }">
                    <Dot color="var(--success)" :size="5" />{{ t('home.all_operational') }}
                </span>
            </div>

            <h1
                :style="{
                    margin: 0,
                    fontSize: '32px',
                    fontWeight: 700,
                    letterSpacing: '-0.02em',
                    color: 'var(--fg)',
                }"
            >{{ t('home.welcome', { name: userDetail.first_name }) }}</h1>
            <p :style="{ fontSize: '13px', color: 'var(--fg-dim)', margin: '8px 0 0' }">
                {{ t('home.subtitle') }}
            </p>

            <div
                :style="{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(2, 1fr)',
                    gap: '1px',
                    background: 'var(--border)',
                    border: '1px solid var(--border)',
                    borderRadius: '6px',
                    marginTop: '24px',
                    overflow: 'hidden',
                }"
            >
                <div :style="{ background: 'var(--panel)', padding: '14px 16px' }">
                    <div
                        class="cmd-mono cmd-uc"
                        :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '8px', fontWeight: 500 }"
                    >{{ t('home.role') }}</div>
                    <span
                        class="cmd-mono"
                        :style="{
                            fontSize: '11px',
                            color: 'var(--accent)',
                            background: 'var(--accent-soft)',
                            border: '1px solid var(--accent-border)',
                            padding: '2px 8px',
                            borderRadius: '3px',
                        }"
                    >{{ primaryRole }}</span>
                </div>

                <div :style="{ background: 'var(--panel)', padding: '14px 16px' }">
                    <div
                        class="cmd-mono cmd-uc"
                        :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '8px', fontWeight: 500 }"
                    >{{ t('home.email') }}</div>
                    <div :style="{ display: 'flex', alignItems: 'center', gap: '7px' }">
                        <Dot :color="userDetail.email_verified_at ? 'var(--success)' : 'var(--warning)'" :size="6" />
                        <span :style="{ fontSize: '13px', color: 'var(--fg)' }">
                            {{ userDetail.email_verified_at ? t('home.email_verified') : t('home.email_unverified') }}
                        </span>
                    </div>
                </div>

                <div :style="{ background: 'var(--panel)', padding: '14px 16px' }">
                    <div
                        class="cmd-mono cmd-uc"
                        :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '8px', fontWeight: 500 }"
                    >{{ t('home.two_factor') }}</div>
                    <div :style="{ display: 'flex', alignItems: 'center', gap: '7px' }">
                        <Dot :color="userDetail.two_factor_enabled ? 'var(--success)' : 'var(--warning)'" :size="6" />
                        <span :style="{ fontSize: '13px', color: 'var(--fg)' }">
                            {{ userDetail.two_factor_enabled ? t('home.two_factor_on') : t('home.two_factor_off') }}
                        </span>
                    </div>
                </div>

                <div :style="{ background: 'var(--panel)', padding: '14px 16px' }">
                    <div
                        class="cmd-mono cmd-uc"
                        :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '8px', fontWeight: 500 }"
                    >{{ t('home.member_since') }}</div>
                    <div class="cmd-mono" :style="{ fontSize: '16px', color: 'var(--fg)' }">
                        {{ formatDate(userDetail.created_at) }}
                    </div>
                </div>
            </div>

            <div :style="{ marginTop: '24px', display: 'flex', gap: '6px' }">
                <button
                    type="button"
                    @click="openProfile"
                    :style="{
                        background: 'var(--accent)',
                        color: '#fff',
                        border: 'none',
                        padding: '5px 11px',
                        borderRadius: '5px',
                        fontSize: '11.5px',
                        fontWeight: 500,
                        cursor: 'pointer',
                        fontFamily: 'inherit',
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: '5px',
                    }"
                >
                    {{ t('home.manage_profile') }}
                    <Icon name="arrow" :size="12" />
                </button>
                <Link
                    href="/settings/tokens"
                    :style="{
                        background: 'transparent',
                        color: 'var(--fg-dim)',
                        border: '1px solid var(--border)',
                        padding: '5px 10px',
                        borderRadius: '5px',
                        fontSize: '11.5px',
                        fontFamily: 'inherit',
                        textDecoration: 'none',
                        display: 'inline-flex',
                        alignItems: 'center',
                    }"
                >{{ t('home.security') }}</Link>
            </div>

            <div
                class="cmd-mono cmd-uc"
                :style="{
                    marginTop: '32px',
                    fontSize: '10px',
                    color: 'var(--fg-mute)',
                    marginBottom: '10px',
                    fontWeight: 500,
                }"
            >{{ t('home.recent_activity') }}</div>

            <div class="cmd-mono" :style="{ fontSize: '11.5px' }">
                <div
                    v-if="activity.length === 0"
                    :style="{ padding: '10px 0', color: 'var(--fg-mute)' }"
                >{{ t('home.no_activity') }}</div>
                <div
                    v-for="row in activity"
                    :key="row.id"
                    :style="{
                        display: 'grid',
                        gridTemplateColumns: '90px 1fr',
                        gap: '12px',
                        padding: '5px 0',
                    }"
                >
                    <span :style="{ color: 'var(--fg-mute)' }">{{ formatTime(row.created_at) }}</span>
                    <span :style="{ color: 'var(--fg-dim)' }">{{ row.description }}</span>
                </div>
            </div>
        </div>
    </div>
    </div>
</template>
