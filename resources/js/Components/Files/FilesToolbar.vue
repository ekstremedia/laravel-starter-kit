<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CmdButton from '@/Components/Command/Button.vue';
import Icon from '@/Components/Command/Icon.vue';
import ScopeSwitcher from '@/Components/Files/ScopeSwitcher.vue';
import { useWorkspace } from '@/composables/useWorkspace';
import type { PageProps } from '@/types';

/**
 * Shared header for every Files surface (Private + Shared). Owns the
 * breadcrumbs, scope switcher, search input, grid/list toggle, and the
 * primary upload / new-folder buttons. Emits user intents; the hosting
 * page still decides how to react — which keeps this component free of
 * router / DB coupling and makes it easy to drop into the Trash view
 * later without dragging upload logic along.
 *
 * Breadcrumb paths (`basePath + /{id}`) and the "root" link also go
 * through the scope-aware `basePath` prop, so the same component works
 * for both /files and /files/company without branching internally.
 */
interface Breadcrumb { id: number; name: string }

const props = defineProps<{
    scope: 'private' | 'shared';
    // URL prefix used for the crumb links — e.g. `/files` or `/files/company`.
    basePath: string;
    breadcrumbs: Breadcrumb[];
    rootLabel: string;
    search: string;
    viewMode: 'grid' | 'list';
    // Gate the buttons: every page computes these from role/permission
    // state, so the toolbar stays presentational.
    permissions: {
        upload: boolean;
        createFolder: boolean;
        canViewShared?: boolean;
    };
    uploadLabel?: string;
    newFolderLabel?: string;
}>();

const emit = defineEmits<{
    'update:search': [value: string];
    'update:viewMode': [value: 'grid' | 'list'];
    submitSearch: [];
    upload: [];
    newFolder: [];
}>();

const { t } = useI18n();
const { workspaceUrl, workspace } = useWorkspace();
const page = usePage<PageProps>();

// The scope pill only earns its place when a Shared tab actually exists:
// workspaces on, the feature enabled for this workspace, and the viewer permitted.
// Otherwise the whole row is dropped (no lone "Private" chip).
const showScopeSwitcher = computed<boolean>(() =>
    (page.props.workspaces?.enabled ?? false)
    && !!workspace.value?.files_feature_enabled
    && !!workspace.value?.company_files_enabled
    && (props.permissions.canViewShared ?? false),
);

const searchModel = computed({
    get: () => props.search,
    set: (v: string) => emit('update:search', v),
});

const viewModeModel = computed({
    get: () => props.viewMode,
    set: (v: 'grid' | 'list') => emit('update:viewMode', v),
});

const uploadText = computed(() => props.uploadLabel ?? t('files.upload'));
const newFolderText = computed(() => props.newFolderLabel ?? t('files.new_folder'));
</script>

<template>
    <header :style="{ display: 'flex', flexDirection: 'column', gap: '10px', marginBottom: '16px' }">
        <!-- Scope pill lives with the breadcrumbs so toggling scope is
             always one click from anywhere in the tree. Dropped entirely when
             there's no Shared surface to switch to (single-tenant / no perm). -->
        <div v-if="showScopeSwitcher" :style="{ display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap' }">
            <ScopeSwitcher :active="scope" :permissions="{ canViewShared: permissions.canViewShared }" />
        </div>

        <div :style="{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: '16px', flexWrap: 'wrap' }">
            <div :style="{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '13px', flexWrap: 'wrap' }">
                <Link
                    :href="workspaceUrl(basePath)"
                    :style="{ color: 'var(--accent)', textDecoration: 'none', fontWeight: 500 }"
                >{{ rootLabel }}</Link>
                <template v-for="(b, i) in breadcrumbs" :key="b.id">
                    <Icon name="chevR" :size="11" :style="{ color: 'var(--fg-mute)' }" />
                    <Link
                        v-if="i < breadcrumbs.length - 1"
                        :href="workspaceUrl(`${basePath}/${b.id}`)"
                        :style="{ color: 'var(--accent)', textDecoration: 'none' }"
                    >{{ b.name }}</Link>
                    <span v-else :style="{ color: 'var(--fg)', fontWeight: 500 }">{{ b.name }}</span>
                </template>
            </div>

            <div :style="{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '8px' }">
                <input
                    v-model="searchModel"
                    type="search"
                    :placeholder="t('files.search_placeholder')"
                    :aria-label="t('files.search_placeholder')"
                    @keyup.enter="emit('submitSearch')"
                    :style="{
                        width: '192px',
                        background: 'var(--panel2)',
                        border: '1px solid var(--border)',
                        borderRadius: '5px',
                        padding: '6px 10px',
                        fontSize: '12px',
                        color: 'var(--fg)',
                        fontFamily: 'inherit',
                    }"
                />

                <div :style="{ display: 'inline-flex', background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: '5px', padding: '2px' }">
                    <button
                        type="button"
                        @click="viewModeModel = 'grid'"
                        :title="t('files.view_grid')"
                        :aria-label="t('files.view_grid')"
                        :aria-pressed="viewMode === 'grid'"
                        :style="{ background: viewMode === 'grid' ? 'var(--panel)' : 'transparent', border: 'none', padding: '4px 8px', borderRadius: '3px', color: viewMode === 'grid' ? 'var(--fg)' : 'var(--fg-dim)', cursor: 'pointer' }"
                    ><i class="pi pi-th-large" :style="{ fontSize: '11px' }" /></button>
                    <button
                        type="button"
                        @click="viewModeModel = 'list'"
                        :title="t('files.view_list')"
                        :aria-label="t('files.view_list')"
                        :aria-pressed="viewMode === 'list'"
                        :style="{ background: viewMode === 'list' ? 'var(--panel)' : 'transparent', border: 'none', padding: '4px 8px', borderRadius: '3px', color: viewMode === 'list' ? 'var(--fg)' : 'var(--fg-dim)', cursor: 'pointer' }"
                    ><i class="pi pi-list" :style="{ fontSize: '11px' }" /></button>
                </div>

                <CmdButton
                    v-if="permissions.upload"
                    variant="primary"
                    size="sm"
                    @click="emit('upload')"
                >
                    <template #icon>
                        <Icon name="plus" :size="12" />
                    </template>
                    {{ uploadText }}
                </CmdButton>

                <CmdButton
                    v-if="permissions.createFolder"
                    variant="ghost"
                    size="sm"
                    @click="emit('newFolder')"
                >
                    <template #icon>
                        <i class="pi pi-folder-plus" :style="{ fontSize: '11px' }" />
                    </template>
                    {{ newFolderText }}
                </CmdButton>

                <!-- Page-specific extras (e.g. personal Files tacks on
                     a Trash link with a badge, Shared Files doesn't). -->
                <slot name="afterActions" />
            </div>
        </div>
    </header>
</template>
