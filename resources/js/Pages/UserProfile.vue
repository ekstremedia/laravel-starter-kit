<script setup lang="ts">
/*
 * Public-ish profile for any user the viewer is allowed to see (i.e. they
 * share a workspace with the profile owner). Shows avatar, name, headline,
 * bio, location, a link to website, and the workspaces the viewer + profile
 * owner share.
 */
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import AppLayout from '@/Layouts/CommandLayout.vue';
import Icon from '@/Components/Command/Icon.vue';
import type { PageProps } from '@/types';

interface ProfilePayload {
    public_id: string;
    full_name: string;
    first_name: string;
    last_name: string;
    headline: string | null;
    bio: string | null;
    location: string | null;
    website: string | null;
    avatar_url: string | null;
    avatar_thumb_url: string | null;
    created_at: string | null;
}

interface SharedWorkspace {
    id: number;
    slug: string;
    name: string;
}

interface Props {
    profile: ProfilePayload;
    shared_workspaces: SharedWorkspace[];
    is_self: boolean;
}

const props = defineProps<Props>();

const { t, locale } = useI18n();
const page = usePage<PageProps>();
const viewerLocale = computed(() => locale.value || page.props.locale || 'en');

const initials = computed(() => {
    const f = (props.profile.first_name?.trim() ?? '')[0] ?? '';
    const l = (props.profile.last_name?.trim() ?? '')[0] ?? '';
    return (f + l).toUpperCase() || '?';
});

const memberSince = computed(() => {
    if (!props.profile.created_at) return null;
    const d = new Date(props.profile.created_at);
    if (Number.isNaN(d.getTime())) return null;
    return d.toLocaleDateString(viewerLocale.value, { year: 'numeric', month: 'long' });
});

// Internal site links go through Inertia's <Link>; outbound website is a
// regular <a> with rel="nofollow noopener".
const websiteHref = computed(() => props.profile.website ?? null);
const websiteHostname = computed(() => {
    if (!props.profile.website) return null;
    try {
        return new URL(props.profile.website).hostname.replace(/^www\./, '');
    } catch {
        return props.profile.website;
    }
});
</script>

<template>
    <AppLayout>
        <Head :title="profile.full_name" />

        <div :style="{ maxWidth: '760px', margin: '0 auto', padding: '24px 20px', display: 'flex', flexDirection: 'column', gap: '18px' }">
            <!-- Header card: avatar + name + headline + actions -->
            <div class="cmd-card" :style="{ padding: '24px', display: 'flex', gap: '20px', alignItems: 'flex-start' }">
                <div
                    :style="{
                        width: '96px',
                        height: '96px',
                        borderRadius: '12px',
                        background: 'var(--panel2)',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        overflow: 'hidden',
                        border: '1px solid var(--border)',
                        flexShrink: 0,
                    }"
                >
                    <img
                        v-if="profile.avatar_url"
                        :src="profile.avatar_url"
                        :alt="profile.full_name"
                        :style="{ width: '100%', height: '100%', objectFit: 'cover' }"
                    />
                    <span v-else :style="{ fontSize: '32px', color: 'var(--fg-mute)', fontWeight: 600 }">{{ initials }}</span>
                </div>

                <div :style="{ flex: 1, display: 'flex', flexDirection: 'column', gap: '4px', minWidth: 0 }">
                    <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, color: 'var(--fg)' }">{{ profile.full_name }}</h1>
                    <p
                        v-if="profile.headline"
                        :style="{ margin: 0, fontSize: '13px', color: 'var(--fg-dim)' }"
                    >{{ profile.headline }}</p>
                    <div
                        v-if="profile.location"
                        :style="{ fontSize: '12px', color: 'var(--fg-mute)', marginTop: '4px' }"
                    >{{ profile.location }}</div>
                    <div
                        v-if="memberSince"
                        :style="{ fontSize: '11px', color: 'var(--fg-mute)', marginTop: '2px' }"
                    >
                        {{ t('user_profile.member_since', { date: memberSince }) }}
                    </div>
                </div>

                <Link
                    v-if="is_self"
                    href="/profile"
                    :style="{
                        background: 'var(--panel2)',
                        color: 'var(--fg)',
                        border: '1px solid var(--border)',
                        padding: '6px 12px',
                        borderRadius: '5px',
                        fontSize: '12px',
                        textDecoration: 'none',
                        whiteSpace: 'nowrap',
                    }"
                >{{ t('user_profile.edit_profile') }}</Link>
            </div>

            <!-- Bio -->
            <div v-if="profile.bio" class="cmd-card" :style="{ padding: '20px' }">
                <h2 :style="{ margin: '0 0 10px', fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">{{ t('user_profile.about_title') }}</h2>
                <p :style="{ margin: 0, fontSize: '13px', lineHeight: 1.55, color: 'var(--fg-dim)', whiteSpace: 'pre-wrap' }">{{ profile.bio }}</p>
            </div>

            <!-- Sidebar-style facts: website + shared workspaces -->
            <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '14px' }">
                <div v-if="websiteHref" class="cmd-card" :style="{ padding: '16px' }">
                    <h2 :style="{ margin: '0 0 8px', fontSize: '11px', textTransform: 'uppercase', letterSpacing: '0.06em', fontWeight: 600, color: 'var(--fg-mute)' }">{{ t('user_profile.website') }}</h2>
                    <a
                        :href="websiteHref"
                        target="_blank"
                        rel="nofollow noopener"
                        :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '13px', color: 'var(--accent)', textDecoration: 'none', wordBreak: 'break-all' }"
                    >
                        <Icon name="link" :size="13" />
                        <span>{{ websiteHostname }}</span>
                    </a>
                </div>

                <div v-if="shared_workspaces.length" class="cmd-card" :style="{ padding: '16px' }">
                    <h2 :style="{ margin: '0 0 8px', fontSize: '11px', textTransform: 'uppercase', letterSpacing: '0.06em', fontWeight: 600, color: 'var(--fg-mute)' }">{{ t('user_profile.shared_workspaces') }}</h2>
                    <ul :style="{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: '4px' }">
                        <li v-for="c in shared_workspaces" :key="c.id">
                            <Link
                                :href="`/w/${c.slug}/about`"
                                :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '12.5px', color: 'var(--fg)', textDecoration: 'none' }"
                            >
                                <Icon name="workspace" :size="12" />
                                <span>{{ c.name }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </div>

            <p
                v-if="!profile.bio && !profile.website && !shared_workspaces.length"
                :style="{ textAlign: 'center', fontSize: '12px', color: 'var(--fg-mute)', padding: '20px' }"
            >{{ t('user_profile.empty') }}</p>
        </div>
    </AppLayout>
</template>
