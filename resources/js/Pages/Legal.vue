<script setup lang="ts">
/*
 * Public legal page (Privacy / Terms). Shipped as a styled placeholder the
 * footer links resolve to out of the box — replace the copy with your own.
 * Reuses the marketing page's PublicTopbar + token styling rather than the
 * authenticated CommandLayout shell.
 */
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import { useTweaks } from '@/composables/useTweaks';
import PublicTopbar from '@/Components/Command/PublicTopbar.vue';

const props = defineProps<{ kind: 'privacy' | 'terms' }>();

useTweaks();

const { t, tm, rt } = useI18n();
const appName = import.meta.env.VITE_APP_NAME || t('app.name');
const title = computed(() => t(`legal.${props.kind}.title`));
const intro = computed(() => t(`legal.${props.kind}.intro`));
// tm() returns the raw message array; rt() resolves each (possibly compiled) string.
const sections = computed(() =>
    (tm(`legal.${props.kind}.sections`) as Array<{ heading: string; body: string }>).map((s) => ({
        heading: rt(s.heading),
        body: rt(s.body),
    })),
);
</script>

<template>
    <Head :title="title" />
    <div :style="{ minHeight: '100vh', display: 'flex', flexDirection: 'column', background: 'var(--bg)', color: 'var(--fg)' }">
        <PublicTopbar />

        <main :style="{ flex: 1, width: '100%', maxWidth: '780px', margin: '0 auto', padding: '48px 24px 64px' }">
            <p class="cmd-mono" :style="{ fontSize: '10.5px', letterSpacing: '0.08em', color: 'var(--fg-mute)', textTransform: 'uppercase' }">
                {{ appName }}
            </p>
            <h1 :style="{ fontSize: '28px', fontWeight: 700, letterSpacing: '-0.02em', margin: '8px 0 4px' }">{{ title }}</h1>
            <p :style="{ fontSize: '11px', color: 'var(--fg-mute)' }">{{ t('legal.last_updated') }}</p>

            <p :style="{ fontSize: '14px', lineHeight: 1.7, color: 'var(--fg-dim)', margin: '24px 0' }">{{ intro }}</p>

            <section
                v-for="(s, i) in sections"
                :key="i"
                :style="{ marginBottom: '24px' }"
            >
                <h2 :style="{ fontSize: '15px', fontWeight: 600, margin: '0 0 6px' }">{{ s.heading }}</h2>
                <p :style="{ fontSize: '13.5px', lineHeight: 1.7, color: 'var(--fg-dim)', margin: 0 }">{{ s.body }}</p>
            </section>

            <p
                :style="{
                    marginTop: '40px',
                    padding: '12px 16px',
                    border: '1px dashed var(--border)',
                    borderRadius: '8px',
                    fontSize: '12px',
                    color: 'var(--fg-mute)',
                }"
            >
                {{ t('legal.placeholder_note') }}
            </p>

            <p :style="{ marginTop: '32px' }">
                <Link href="/" :style="{ fontSize: '13px', color: 'var(--accent)', textDecoration: 'none' }">← {{ t('legal.back_home') }}</Link>
            </p>
        </main>
    </div>
</template>
