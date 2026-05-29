<script setup lang="ts">
/*
 * The centered error block (eyebrow · code · title · description · CTAs ·
 * request id), shared by the error page's authenticated and guest shells.
 * Given only the status it resolves its own localized title/description — we
 * never surface raw exception messages (they're English in a localized UI and
 * can leak internal paths/slugs).
 */
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Command/Icon.vue';
import CmdButton from '@/Components/Command/Button.vue';
import type { PageProps } from '@/types';

const props = defineProps<{ status: number }>();

const { t } = useI18n();
const page = usePage<PageProps>();
const user = computed(() => page.props.auth?.user);
const requestId = computed(() => page.props.request_id ?? '');

const known = [403, 404, 419, 500, 503];
const code = computed(() => (known.includes(props.status) ? props.status : 500));
const titleKey = computed(() => `errors.${code.value}.title`);
const bodyKey = computed(() => `errors.${code.value}.description`);

function goBack() {
    if (typeof window !== 'undefined' && window.history.length > 1) {
        window.history.back();
        return;
    }
    // Deep links / new tabs have an empty history stack — send "back" somewhere real.
    router.visit(user.value ? '/home' : '/');
}
</script>

<template>
    <div :style="{ maxWidth: '480px', width: '100%', textAlign: 'center', margin: '0 auto' }">
        <div
            class="cmd-mono cmd-uc"
            :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '16px', letterSpacing: '0.08em', fontWeight: 500 }"
        >{{ t('errors.eyebrow') }}</div>

        <h1
            class="cmd-mono"
            :style="{ margin: 0, fontSize: '88px', fontWeight: 700, lineHeight: 1, color: 'var(--fg)', letterSpacing: '-0.04em' }"
        >{{ status }}</h1>

        <h2
            :style="{ margin: '14px 0 0', fontSize: '21px', fontWeight: 600, letterSpacing: '-0.02em', color: 'var(--fg)' }"
        >{{ t(titleKey) }}</h2>

        <p
            :style="{ fontSize: '13.5px', color: 'var(--fg-dim)', margin: '10px auto 24px', maxWidth: '380px', lineHeight: 1.55 }"
        >{{ t(bodyKey) }}</p>

        <div :style="{ display: 'inline-flex', gap: '8px', flexWrap: 'wrap', justifyContent: 'center' }">
            <CmdButton variant="ghost" size="md" @click="goBack">
                <template #icon><Icon name="arrow" :size="12" :style="{ transform: 'rotate(180deg)' }" /></template>
                {{ t('errors.go_back') }}
            </CmdButton>
            <Link :href="user ? '/home' : '/'" :style="{ textDecoration: 'none' }">
                <CmdButton variant="primary" size="md">
                    <template #icon><Icon name="home" :size="12" /></template>
                    {{ user ? t('errors.go_home') : t('errors.go_welcome') }}
                </CmdButton>
            </Link>
            <Link v-if="!user" href="/login" :style="{ textDecoration: 'none' }">
                <CmdButton variant="ghost" size="md">
                    <template #icon><Icon name="key" :size="12" /></template>
                    {{ t('nav.login') }}
                </CmdButton>
            </Link>
        </div>

        <p
            v-if="requestId"
            class="cmd-mono"
            :style="{ marginTop: '30px', fontSize: '10.5px', color: 'var(--fg-mute)' }"
        >{{ t('errors.request_id', { id: requestId }) }}</p>
    </div>
</template>
