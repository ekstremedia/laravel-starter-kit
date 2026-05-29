<script setup lang="ts">
/*
 * Persistent left rail. 52 px by default, 180 px when the user pins it
 * expanded via the chevron at the bottom. Expanded state persists to
 * starter_kit_settings → rail_expanded.
 *
 * Two modes, picked by route: the app rail everywhere, and a dedicated admin
 * rail on /admin/* (reached from the topbar profile dropdown). The item lists
 * are owned by `useSidebarItems()` — edit that composable to add / remove /
 * reorder entries. This component only handles presentation: active-state,
 * hover tooltip, section labels, collapse toggle, logo/brand, profile tile.
 *
 * On narrow viewports (≤640px) the rail leaves the layout flow entirely so the
 * page goes full-width, and instead becomes an off-canvas drawer toggled by the
 * topbar hamburger (`mobileOpen` prop / `close` emit). In that mode it always
 * shows full labels and the pin toggle is hidden.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { PageProps } from '@/types';
import { useTweaks } from '@/composables/useTweaks';
import { useSidebarItems } from '@/composables/useSidebarItems';
import { isSidebarItem } from '@/types/sidebar';
import type { SidebarEntry } from '@/types/sidebar';
import Icon from './Icon.vue';

const props = defineProps<{ mobileOpen?: boolean }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();
const page = usePage<PageProps>();
const currentPath = computed(() => page.url.split('?')[0]);
const user = computed(() => page.props.auth?.user);
const { state, toggleRail } = useTweaks();
const { appVisible, adminVisible } = useSidebarItems();

// Below 640px the rail is an off-canvas drawer rather than an in-flow column.
const isNarrow = ref(false);
let mql: MediaQueryList | null = null;
const syncNarrow = () => { isNarrow.value = mql?.matches ?? false; };
onMounted(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return;
    mql = window.matchMedia('(max-width: 640px)');
    syncNarrow();
    mql.addEventListener('change', syncNarrow);
});
onBeforeUnmount(() => mql?.removeEventListener('change', syncNarrow));

// In the drawer we always show labels; on desktop we honor the pinned setting.
const expanded = computed(() => (isNarrow.value ? true : state.value.railExpanded));

// On /admin/* the rail swaps to the admin item set; everywhere else it shows
// the app nav. Entry into the admin area is via the topbar profile dropdown.
// Boundary-aware so a future route like /administrator isn't treated as admin.
const isAdminMode = computed(() => currentPath.value === '/admin' || currentPath.value.startsWith('/admin/'));
const visible = computed(() => (isAdminMode.value ? adminVisible.value : appVisible.value));

const initials = computed(() =>
    ((user.value?.first_name?.[0] ?? '') + (user.value?.last_name?.[0] ?? '')).toUpperCase() || '??',
);

const hoverId = ref<string | null>(null);
const isItem = isSidebarItem;
type Entry = SidebarEntry;
</script>

<template>
    <!-- Mobile drawer backdrop. Tap to dismiss. -->
    <Transition name="cmd-backdrop">
        <div
            v-if="isNarrow && mobileOpen"
            class="cmd-rail-backdrop"
            @click="emit('close')"
            :style="{ position: 'fixed', inset: '0', background: 'rgba(0,0,0,0.5)', zIndex: 79 }"
        />
    </Transition>

    <aside
        class="cmd-rail"
        :class="{ 'is-expanded': expanded, 'is-drawer': isNarrow }"
        role="navigation"
        :aria-label="t('rail.aria_label')"
        :aria-hidden="isNarrow && !mobileOpen"
        :style="{
            width: isNarrow ? '264px' : (expanded ? '180px' : '52px'),
            maxWidth: isNarrow ? '82vw' : 'none',
            background: 'var(--bg2)',
            borderRight: '1px solid var(--border)',
            display: 'flex',
            flexDirection: 'column',
            alignItems: expanded ? 'stretch' : 'center',
            padding: expanded ? '12px 10px' : '12px 0',
            flexShrink: 0,
            alignSelf: 'stretch',
            position: isNarrow ? 'fixed' : 'static',
            top: isNarrow ? '0' : 'auto',
            left: isNarrow ? '0' : 'auto',
            bottom: isNarrow ? '0' : 'auto',
            zIndex: isNarrow ? 80 : 'auto',
            transform: isNarrow ? (mobileOpen ? 'translateX(0)' : 'translateX(-102%)') : 'none',
            boxShadow: isNarrow && mobileOpen ? '0 8px 40px rgba(0,0,0,0.5)' : 'none',
            transition: isNarrow
                ? 'transform 0.24s cubic-bezier(0.4, 0, 0.2, 1)'
                : 'width 0.2s cubic-bezier(0.4, 0, 0.2, 1), padding 0.2s cubic-bezier(0.4, 0, 0.2, 1)',
            overflowX: 'hidden',
            overflowY: isNarrow ? 'auto' : 'hidden',
        }"
    >
        <Link
            :href="isAdminMode ? '/admin' : '/home'"
            :title="isAdminMode ? t('rail.administration') : t('rail.brand')"
            :style="{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '10px',
                height: '30px',
                minWidth: '30px',
                padding: expanded ? '0 6px' : '0',
                justifyContent: expanded ? 'flex-start' : 'center',
                borderRadius: '6px',
                background: 'var(--accent)',
                color: '#fff',
                fontWeight: 700,
                fontSize: '12px',
                marginBottom: isAdminMode ? '8px' : '14px',
                fontFamily: 'var(--font-mono)',
                textDecoration: 'none',
                alignSelf: expanded ? 'stretch' : 'center',
                width: expanded ? 'auto' : '30px',
                overflow: 'hidden',
            }"
        >
            <span :style="{ flexShrink: 0 }">{{ isAdminMode ? t('rail.admin_mark') : t('rail.brand_mark') }}</span>
            <Transition name="cmd-rail-text">
                <span
                    v-if="expanded"
                    :style="{ fontSize: '12px', letterSpacing: '-0.01em', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }"
                >{{ isAdminMode ? t('rail.administration') : t('rail.brand') }}</span>
            </Transition>
        </Link>

        <!-- Admin mode: an escape hatch back to the app rail. -->
        <Link
            v-if="isAdminMode"
            href="/home"
            :title="t('rail.back_to_app')"
            :style="{
                display: 'flex',
                alignItems: 'center',
                gap: '10px',
                height: '30px',
                minWidth: '30px',
                padding: expanded ? '0 10px' : '0',
                justifyContent: expanded ? 'flex-start' : 'center',
                borderRadius: '6px',
                marginBottom: '6px',
                color: 'var(--fg-mute)',
                background: 'transparent',
                textDecoration: 'none',
                width: expanded ? 'auto' : '34px',
                alignSelf: expanded ? 'stretch' : 'center',
            }"
            class="cmd-rail-item"
        >
            <Icon name="arrow" :size="14" :style="{ transform: 'rotate(180deg)', flexShrink: 0 }" />
            <Transition name="cmd-rail-text">
                <span
                    v-if="expanded"
                    :style="{ fontSize: '12px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }"
                >{{ t('rail.back_to_app') }}</span>
            </Transition>
        </Link>

        <template v-for="entry in (visible as Entry[])" :key="isItem(entry) ? entry.id : entry.key">
            <template v-if="!isItem(entry)">
                <Transition name="cmd-rail-text">
                    <div
                        v-if="expanded && entry.label"
                        class="cmd-mono cmd-uc"
                        :style="{ fontSize: '9.5px', letterSpacing: '0.06em', color: 'var(--fg-mute)', fontWeight: 500, padding: '0 10px', margin: '10px 0 4px', alignSelf: 'stretch' }"
                    >{{ entry.label }}</div>
                </Transition>
                <div
                    v-if="!(expanded && entry.label)"
                    :style="{ height: '1px', background: 'var(--border)', margin: '6px 0', width: expanded ? '100%' : '20px', alignSelf: expanded ? 'stretch' : 'center' }"
                />
            </template>
            <Link
                v-else
                :href="entry.href"
                @mouseenter="hoverId = entry.id"
                @mouseleave="hoverId = null"
                :style="{
                    display: 'flex',
                    alignItems: 'center',
                    gap: '10px',
                    height: '34px',
                    minWidth: '34px',
                    padding: expanded ? '0 10px' : '0',
                    justifyContent: expanded ? 'flex-start' : 'center',
                    borderRadius: '6px',
                    marginBottom: '2px',
                    color: entry.match(currentPath) ? 'var(--fg)' : 'var(--fg-mute)',
                    background: entry.match(currentPath) ? 'var(--accent-soft)' : 'transparent',
                    position: 'relative',
                    transition: 'background 0.12s, color 0.12s',
                    textDecoration: 'none',
                    width: expanded ? 'auto' : '34px',
                    alignSelf: expanded ? 'stretch' : 'center',
                }"
                class="cmd-rail-item"
            >
                <Icon :name="entry.icon" :size="15" :style="{ flexShrink: 0 }" />

                <span
                    v-if="entry.match(currentPath)"
                    :style="{
                        position: 'absolute',
                        left: expanded ? '-11px' : '-10px',
                        top: '6px',
                        bottom: '6px',
                        width: '2px',
                        background: 'var(--accent)',
                        borderRadius: '2px',
                    }"
                />

                <Transition name="cmd-rail-text">
                    <span
                        v-if="expanded"
                        :style="{
                            fontSize: '12px',
                            fontWeight: entry.match(currentPath) ? 500 : 400,
                            flex: 1,
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            whiteSpace: 'nowrap',
                        }"
                    >{{ entry.label }}</span>
                </Transition>
                <Transition name="cmd-rail-text">
                    <kbd
                        v-if="expanded && state.showKbdHints && entry.kb"
                        class="cmd-mono"
                        :style="{
                            fontSize: '9.5px',
                            padding: '1px 5px',
                            border: '1px solid var(--border)',
                            borderRadius: '3px',
                            color: 'var(--fg-dim)',
                            background: 'var(--bg)',
                            flexShrink: 0,
                        }"
                    >G {{ entry.kb }}</kbd>
                </Transition>

                <span
                    v-if="!expanded && hoverId === entry.id"
                    :style="{
                        position: 'absolute',
                        left: '44px',
                        top: '50%',
                        transform: 'translateY(-50%)',
                        background: 'var(--panel2)',
                        color: 'var(--fg)',
                        padding: '5px 10px',
                        borderRadius: '5px',
                        fontSize: '11.5px',
                        whiteSpace: 'nowrap',
                        zIndex: 30,
                        border: '1px solid var(--border)',
                        display: 'flex',
                        alignItems: 'center',
                        gap: '10px',
                        pointerEvents: 'none',
                        boxShadow: '0 4px 12px rgba(0,0,0,0.3)',
                    }"
                >
                    {{ entry.label }}
                    <kbd
                        v-if="state.showKbdHints && entry.kb"
                        class="cmd-mono"
                        :style="{
                            fontSize: '9.5px',
                            padding: '1px 5px',
                            border: '1px solid var(--border)',
                            borderRadius: '3px',
                            color: 'var(--fg-dim)',
                            background: 'var(--bg)',
                        }"
                    >G {{ entry.kb }}</kbd>
                </span>
            </Link>
        </template>

        <div style="flex: 1" />

        <button
            v-if="!isNarrow"
            type="button"
            @click="toggleRail"
            :title="expanded ? t('rail.collapse') : t('rail.expand')"
            :aria-label="expanded ? t('rail.collapse') : t('rail.expand')"
            :aria-expanded="expanded"
            :style="{
                display: 'flex',
                alignItems: 'center',
                gap: '8px',
                height: '28px',
                padding: expanded ? '0 10px' : '0',
                justifyContent: expanded ? 'flex-start' : 'center',
                borderRadius: '6px',
                background: 'transparent',
                border: '1px solid var(--border)',
                color: 'var(--fg-mute)',
                cursor: 'pointer',
                marginBottom: '8px',
                width: expanded ? 'auto' : '28px',
                minWidth: '28px',
                alignSelf: expanded ? 'stretch' : 'center',
                fontFamily: 'inherit',
            }"
            class="cmd-rail-toggle"
        >
            <span :style="{ display: 'flex', transform: expanded ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s cubic-bezier(0.4, 0, 0.2, 1)' }">
                <Icon name="chevR" :size="11" />
            </span>
            <Transition name="cmd-rail-text">
                <span
                    v-if="expanded"
                    class="cmd-mono cmd-uc"
                    :style="{ fontSize: '10px', letterSpacing: '0.06em' }"
                >{{ t('rail.collapse_label') }}</span>
            </Transition>
        </button>

        <Link
            href="/profile"
            :title="user?.full_name ?? ''"
            :style="{
                display: 'flex',
                alignItems: 'center',
                gap: '10px',
                height: '34px',
                padding: expanded ? '0 6px' : '0',
                justifyContent: expanded ? 'flex-start' : 'center',
                borderRadius: expanded ? '6px' : '50%',
                background: 'var(--accent-soft)',
                border: '1px solid var(--accent-border)',
                color: 'var(--accent)',
                fontSize: '10px',
                fontWeight: 700,
                fontFamily: 'var(--font-mono)',
                textDecoration: 'none',
                width: expanded ? 'auto' : '28px',
                minWidth: '28px',
                alignSelf: expanded ? 'stretch' : 'center',
                overflow: 'hidden',
                transition: 'border-radius 0.2s cubic-bezier(0.4, 0, 0.2, 1)',
            }"
        >
            <span :style="{ flexShrink: 0 }">{{ initials }}</span>
            <Transition name="cmd-rail-text">
                <span
                    v-if="expanded"
                    :style="{ fontSize: '11.5px', color: 'var(--fg)', fontFamily: 'var(--font-ui)', fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }"
                >{{ user?.full_name ?? '' }}</span>
            </Transition>
        </Link>
    </aside>
</template>

<style scoped>
.cmd-rail-item:hover {
    color: var(--fg) !important;
}
.cmd-rail-toggle:hover {
    color: var(--fg) !important;
    background: var(--panel2) !important;
}

/*
 * Label reveal that rides along with the width morph. On expand, labels fade +
 * slide in just after the rail starts widening (small delay); on collapse they
 * fade out quickly while the rail clips them away. Keeps the icons-only ↔
 * labelled transition feeling continuous rather than popping.
 */
.cmd-rail-text-enter-active {
    transition: opacity 0.18s ease 0.05s, transform 0.18s ease 0.05s;
}
.cmd-rail-text-leave-active {
    transition: opacity 0.1s ease, transform 0.1s ease;
}
.cmd-rail-text-enter-from,
.cmd-rail-text-leave-to {
    opacity: 0;
    transform: translateX(-6px);
}

/* Mobile drawer backdrop fade. */
.cmd-backdrop-enter-active,
.cmd-backdrop-leave-active {
    transition: opacity 0.24s ease;
}
.cmd-backdrop-enter-from,
.cmd-backdrop-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .cmd-rail,
    .cmd-rail-text-enter-active,
    .cmd-rail-text-leave-active,
    .cmd-backdrop-enter-active,
    .cmd-backdrop-leave-active {
        transition: none !important;
    }
}
</style>
