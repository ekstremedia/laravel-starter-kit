<script setup lang="ts">
/*
 * Self-serve workspace onboarding form.
 *
 * Shown to a freshly-registered, non-invited user in multi-tenant `create_own`
 * mode (WorkspaceLandingController redirects a zero-workspace user here). The
 * name is prefilled with a suggestion but fully editable; submitting creates
 * the workspace and makes the user its Admin (WorkspaceOnboardingController).
 */
import { Head, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Field from '@/Components/Command/Field.vue';
import CmdButton from '@/Components/Command/Button.vue';
import Icon from '@/Components/Command/Icon.vue';

defineOptions({ layout: CommandLayout });

const props = defineProps<{ suggestedName: string }>();
const { t } = useI18n();

// Prefilled with the suggestion ("Ada's space") — the user can edit it freely.
const form = useForm({ name: props.suggestedName });

function submit() {
    form.post('/onboarding/workspace');
}
</script>

<template>
    <div :style="{ minHeight: '70vh', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '24px' }">
        <Head :title="t('onboarding.workspace.title')" />

        <form
            @submit.prevent="submit"
            class="cmd-card"
            :style="{ width: '100%', maxWidth: '460px', padding: '28px', display: 'flex', flexDirection: 'column', gap: '16px' }"
        >
            <div :style="{ display: 'flex', flexDirection: 'column', gap: '8px' }">
                <div
                    :style="{
                        width: '40px',
                        height: '40px',
                        borderRadius: 'var(--radius-control, 8px)',
                        background: 'var(--panel2)',
                        border: '1px solid var(--border)',
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        color: 'var(--accent)',
                    }"
                >
                    <Icon name="workspace" :size="20" />
                </div>
                <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">
                    {{ t('onboarding.workspace.title') }}
                </h1>
                <p :style="{ margin: 0, fontSize: '13px', lineHeight: 1.5, color: 'var(--fg-mute)' }">
                    {{ t('onboarding.workspace.description') }}
                </p>
            </div>

            <Field
                v-model="form.name"
                :label="t('onboarding.workspace.name_label')"
                :error="form.errors.name"
                autofocus
            />

            <CmdButton type="submit" variant="primary" size="md" :loading="form.processing" :disabled="!form.name.trim()">
                <template #icon><Icon name="plus" :size="12" /></template>
                {{ t('onboarding.workspace.submit') }}
            </CmdButton>
        </form>
    </div>
</template>
