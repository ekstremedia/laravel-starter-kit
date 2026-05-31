<script setup lang="ts">
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Toggle from '@/Components/Command/Toggle.vue';
import PageTitle from '@/Components/Command/PageTitle.vue';
import Icon from '@/Components/Command/Icon.vue';
import { useWorkspace } from '@/composables/useWorkspace';
import { useLiveReload } from '@/composables/useLiveReload';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();
const { workspace, workspaceUrl } = useWorkspace();

// Live: when another workspace admin toggles a module, refresh this page's list
// AND the shared `modules` map (drives the rail / page gating).
useLiveReload(
    () => (workspace.value ? `workspace.${workspace.value.id}.resources` : null),
    { resource: 'module_settings', only: ['module_settings', 'modules'] },
);

interface FeatureRow {
    key: string;
    platform: boolean;
    effective: boolean;
    overridden: boolean;
}
interface EnabledState {
    effective: boolean;
    platform: boolean;
    overridden: boolean;
}
interface ModuleRow {
    id: number;
    key: string;
    name: string;
    parent_key: string | null;
    enabled: EnabledState;
    features: FeatureRow[];
    children?: ModuleRow[];
}

const props = defineProps<{ module_settings: ModuleRow[] }>();

const FEATURE_LABELS: Record<string, string> = {
    files: t('admin.modules.feature_files'),
    log: t('admin.modules.feature_log'),
};

// One unified list of toggle rows per module: the module's own on/off first,
// then each shipped feature. The `enabled` row's key is 'enabled' — the same
// value the controller accepts as the toggle target — so a single patch handler
// drives them all.
interface ToggleRow {
    key: string;
    label: string;
    value: boolean;
    platform: boolean;
    overridden: boolean;
    isEnabled: boolean;
}
function toggleRows(module: ModuleRow): ToggleRow[] {
    return [
        {
            key: 'enabled',
            label: t('workspace_modules.enabled'),
            value: module.enabled.effective,
            platform: module.enabled.platform,
            overridden: module.enabled.overridden,
            isEnabled: true,
        },
        ...module.features.map((f) => ({
            key: f.key,
            label: FEATURE_LABELS[f.key] ?? f.key,
            value: f.effective,
            platform: f.platform,
            overridden: f.overridden,
            isEnabled: false,
        })),
    ];
}

// Flatten each top-level module + its children into one card. `cascadeOff` marks
// a child whose parent is currently off — its toggles are shown but locked.
interface RenderRow {
    module: ModuleRow;
    isChild: boolean;
    cascadeOff: boolean;
}
const groups = computed(() =>
    props.module_settings.map((top) => ({
        id: top.id,
        rows: [
            { module: top, isChild: false, cascadeOff: false },
            ...(top.children ?? []).map((child) => ({ module: child, isChild: true, cascadeOff: !top.enabled.effective })),
        ] as RenderRow[],
    })),
);

// The module on/off is locked only by a disabled parent. A feature is also
// locked whenever its own module is off (an off module's features are moot).
function rowLocked(row: RenderRow, toggle: ToggleRow): boolean {
    if (toggle.isEnabled) {
        return row.cascadeOff;
    }
    return row.cascadeOff || !row.module.enabled.effective;
}

function patchModule(module: ModuleRow, feature: string, value: boolean) {
    router.patch(
        workspaceUrl(`/settings/modules/${module.id}`),
        { feature, enabled: value },
        { preserveScroll: true, preserveState: true, only: ['module_settings', 'modules', 'flash'] },
    );
}
function resetModule(module: ModuleRow) {
    router.delete(workspaceUrl(`/settings/modules/${module.id}`), { preserveScroll: true, preserveState: true });
}

function hasOverride(module: ModuleRow): boolean {
    return module.enabled.overridden || module.features.some((f) => f.overridden);
}
function inheritedLabel(platform: boolean): string {
    return t('workspace_modules.inherited', { state: platform ? t('workspace_modules.on') : t('workspace_modules.off') });
}
</script>

<template>
    <div>
        <Head :title="t('workspace_modules.head_title')" />

        <PageTitle :title="t('workspace_modules.title')" :subtitle="t('workspace_modules.subtitle')" />

        <p :style="{ margin: '0 0 16px', fontSize: '12.5px', color: 'var(--fg-mute)', maxWidth: '640px', lineHeight: 1.5 }">
            {{ t('workspace_modules.intro') }}
        </p>

        <div v-if="!props.module_settings.length" class="cmd-card" :style="{ padding: '40px 16px', textAlign: 'center' }">
            <p :style="{ margin: 0, fontSize: '13px', color: 'var(--fg-mute)' }">{{ t('workspace_modules.empty') }}</p>
        </div>

        <div v-else :style="{ display: 'flex', flexDirection: 'column', gap: '10px' }">
            <div
                v-for="group in groups"
                :key="group.id"
                class="cmd-card"
                :style="{ padding: '16px 18px' }"
            >
                <!-- Parent module, then any grouped children nested below it. -->
                <div
                    v-for="row in group.rows"
                    :key="row.module.id"
                    :style="row.isChild
                        ? { marginTop: '14px', paddingTop: '14px', paddingLeft: '14px', borderTop: '1px solid var(--border)', borderLeft: '2px solid var(--border)' }
                        : undefined"
                >
                    <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' }">
                        <div :style="{ display: 'inline-flex', alignItems: 'center', gap: '8px' }">
                            <Icon :name="row.isChild ? 'tag' : 'box'" :size="14" :style="{ color: 'var(--fg-mute)' }" />
                            <span :style="{ fontSize: '14px', fontWeight: 600, color: row.cascadeOff ? 'var(--fg-mute)' : 'var(--fg)' }">{{ row.module.name }}</span>
                        </div>
                        <button
                            v-if="hasOverride(row.module)"
                            type="button"
                            :style="{ background: 'transparent', border: '1px solid var(--border)', color: 'var(--fg-dim)', cursor: 'pointer', padding: '3px 9px', borderRadius: '5px', fontSize: '11px', fontFamily: 'inherit' }"
                            @click="resetModule(row.module)"
                        >
                            {{ t('workspace_modules.reset') }}
                        </button>
                    </div>

                    <div :style="{ display: 'flex', flexDirection: 'column', gap: '10px' }">
                        <div
                            v-for="tgl in toggleRows(row.module)"
                            :key="tgl.key"
                            :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', opacity: rowLocked(row, tgl) && !tgl.isEnabled ? 0.5 : 1 }"
                        >
                            <div :style="{ display: 'inline-flex', alignItems: 'center', gap: '8px' }">
                                <Toggle
                                    :model-value="tgl.value"
                                    :disabled="rowLocked(row, tgl)"
                                    :label="tgl.label"
                                    @update:model-value="(v) => patchModule(row.module, tgl.key, v)"
                                />
                                <span :style="{ fontSize: '12.5px', fontWeight: tgl.isEnabled ? 600 : 400, color: 'var(--fg)' }">{{ tgl.label }}</span>
                            </div>
                            <span
                                v-if="tgl.overridden"
                                :style="{ fontSize: '10.5px', color: 'var(--accent)', textTransform: 'uppercase', letterSpacing: '0.05em' }"
                            >{{ t('workspace_modules.overridden') }}</span>
                            <span
                                v-else
                                :style="{ fontSize: '10.5px', color: 'var(--fg-mute)' }"
                            >{{ inheritedLabel(tgl.platform) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
