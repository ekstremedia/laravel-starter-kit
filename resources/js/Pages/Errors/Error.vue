<script setup lang="ts">
/*
 * Shared error page (403 / 404 / 500 / 503). Registered via the Inertia
 * exception renderer in bootstrap/app.php.
 *
 *   - Authenticated  → full app chrome (CommandLayout: Rail + Topbar) with the
 *                      error centered in the content area, so a logged-in user
 *                      never loses their navigation.
 *   - Guest          → minimal public shell (PublicTopbar) + centered error.
 *
 * The localized status title/description live in ErrorPanel; we deliberately
 * don't surface the raw exception `message` (it's English in a localized UI and
 * can leak internal paths). The prop is kept for the controller contract.
 */
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useTweaks } from '@/composables/useTweaks';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import PublicTopbar from '@/Components/Command/PublicTopbar.vue';
import ErrorPanel from '@/Components/Command/ErrorPanel.vue';
import type { PageProps } from '@/types';

interface Props {
    status: number;
    message?: string;
}
const props = withDefaults(defineProps<Props>(), { message: '' });

useTweaks();

const { t } = useI18n();
const page = usePage<PageProps>();
const user = computed(() => page.props.auth?.user);

const known = [403, 404, 419, 500, 503];
const titleKey = computed(() => `errors.${known.includes(props.status) ? props.status : 500}.title`);
const pageTitle = computed(() => `${props.status} · ${t(titleKey.value)}`);

const centered = {
    minHeight: '62vh',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    padding: '24px',
} as const;
</script>

<template>
    <!-- Logged in: keep the full app chrome (rail + topbar). -->
    <CommandLayout v-if="user">
        <Head :title="pageTitle" />
        <div :style="centered">
            <ErrorPanel :status="status" />
        </div>
    </CommandLayout>

    <!-- Guest: minimal public shell with a clear way back in. -->
    <div
        v-else
        class="cmd-shell"
        :style="{ minHeight: '100vh', background: 'var(--bg)', color: 'var(--fg)', display: 'flex', flexDirection: 'column' }"
    >
        <Head :title="pageTitle" />
        <PublicTopbar />
        <section :style="{ flex: 1, ...centered }">
            <ErrorPanel :status="status" />
        </section>
    </div>
</template>
