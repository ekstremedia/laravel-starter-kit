<script setup lang="ts">
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

// Live: when another workspace admin toggles a module feature, refresh this
// page's list AND the shared `modules` map (drives the rail / page gating).
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
interface ModuleRow {
    id: number;
    key: string;
    name: string;
    features: FeatureRow[];
}

const props = defineProps<{ module_settings: ModuleRow[] }>();

const FEATURE_LABELS: Record<string, string> = {
    files: t('admin.modules.feature_files'),
    log: t('admin.modules.feature_log'),
};

function toggleFeature(module: ModuleRow, feature: string, value: boolean) {
    // Refresh both the page list and the shared `modules` map so the rail / page
    // surfaces reflect the new override immediately.
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
    return module.features.some((f) => f.overridden);
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
                v-for="module in props.module_settings"
                :key="module.id"
                class="cmd-card"
                :style="{ padding: '16px 18px' }"
            >
                <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '14px' }">
                    <div :style="{ display: 'inline-flex', alignItems: 'center', gap: '8px' }">
                        <Icon name="box" :size="14" :style="{ color: 'var(--fg-mute)' }" />
                        <span :style="{ fontSize: '14px', fontWeight: 600, color: 'var(--fg)' }">{{ module.name }}</span>
                    </div>
                    <button
                        v-if="hasOverride(module)"
                        type="button"
                        :style="{ background: 'transparent', border: '1px solid var(--border)', color: 'var(--fg-dim)', cursor: 'pointer', padding: '3px 9px', borderRadius: '5px', fontSize: '11px', fontFamily: 'inherit' }"
                        @click="resetModule(module)"
                    >
                        {{ t('workspace_modules.reset') }}
                    </button>
                </div>

                <div :style="{ display: 'flex', flexDirection: 'column', gap: '10px' }">
                    <div
                        v-for="f in module.features"
                        :key="f.key"
                        :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px' }"
                    >
                        <div :style="{ display: 'inline-flex', alignItems: 'center', gap: '8px' }">
                            <Toggle :model-value="f.effective" :label="FEATURE_LABELS[f.key] ?? f.key" @update:model-value="(v) => toggleFeature(module, f.key, v)" />
                            <span :style="{ fontSize: '12.5px', color: 'var(--fg)' }">{{ FEATURE_LABELS[f.key] ?? f.key }}</span>
                        </div>
                        <span
                            v-if="f.overridden"
                            :style="{ fontSize: '10.5px', color: 'var(--accent)', textTransform: 'uppercase', letterSpacing: '0.05em' }"
                        >{{ t('workspace_modules.overridden') }}</span>
                        <span
                            v-else
                            :style="{ fontSize: '10.5px', color: 'var(--fg-mute)' }"
                        >{{ t('workspace_modules.inherited', { state: f.platform ? t('workspace_modules.on') : t('workspace_modules.off') }) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
