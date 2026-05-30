<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';

defineOptions({ layout: CommandLayout });

interface WorkspaceRoleCell {
    id: number;
    name: string;
    slug: string;
    roles: string[];
}

interface UserDetail {
    first_name: string;
    is_super_admin: boolean;
    workspace_roles: WorkspaceRoleCell[];
    created_at: string | null;
}

interface Props {
    userDetail: UserDetail;
}

const props = defineProps<Props>();
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
</script>

<template>
    <div>
        <Head :title="t('home.title')" />

        <div :style="{ padding: '32px 40px' }">
            <div :style="{ maxWidth: '780px', margin: '0 auto' }">
                <h1
                    :style="{
                        margin: 0,
                        fontSize: '32px',
                        fontWeight: 700,
                        letterSpacing: '-0.02em',
                        color: 'var(--fg)',
                    }"
                >{{ t('home.welcome', { name: userDetail.first_name }) }}</h1>

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
                        >{{ t('home.member_since') }}</div>
                        <div class="cmd-mono" :style="{ fontSize: '16px', color: 'var(--fg)' }">
                            {{ formatDate(userDetail.created_at) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
