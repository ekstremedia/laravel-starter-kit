<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Dot from '@/Components/Command/Dot.vue';
import Icon from '@/Components/Command/Icon.vue';
import Skeleton from '@/Components/Command/Skeleton.vue';
import Toggle from '@/Components/Command/Toggle.vue';
import { useCommandToasts } from '@/composables/useCommandToasts';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();

type Severity = 'info' | 'warn' | 'danger' | 'success';

interface Settings {
    site_up: boolean;
    registration_open: boolean;
    login_enabled: boolean;
    require_email_verification: boolean;
    default_role: string;
    require_2fa_for_admins: boolean;
    send_welcome_notification: boolean;
    maintenance_message: string | null;
    announcement_banner: string | null;
    announcement_severity: Severity;
    files_feature_enabled: boolean;
    max_share_days: number;
    // null/null = unlimited, -1 = explicit unlimited, 0 = blocked, N>0 = cap.
    default_personal_storage_bytes: number | null;
    default_entity_storage_bytes: number | null;
    max_upload_bytes: number;
}

interface Props {
    settings: Settings;
    roles: string[];
    // Hard ceiling from the running PHP process (upload_max_filesize/post_max_size).
    php_upload_ceiling_bytes: number;
}

const props = defineProps<Props>();
const { push } = useCommandToasts();

const form = useForm({
    site_up: props.settings.site_up,
    registration_open: props.settings.registration_open,
    login_enabled: props.settings.login_enabled,
    require_email_verification: props.settings.require_email_verification,
    default_role: props.settings.default_role,
    require_2fa_for_admins: props.settings.require_2fa_for_admins,
    send_welcome_notification: props.settings.send_welcome_notification,
    maintenance_message: props.settings.maintenance_message ?? '',
    announcement_banner: props.settings.announcement_banner ?? '',
    announcement_severity: props.settings.announcement_severity as Severity,
    files_feature_enabled: props.settings.files_feature_enabled,
    max_share_days: props.settings.max_share_days,
    default_personal_storage_bytes: props.settings.default_personal_storage_bytes,
    default_entity_storage_bytes: props.settings.default_entity_storage_bytes,
    max_upload_bytes: props.settings.max_upload_bytes,
});

// `v-model.number` gives us '' when the user clears the field, but the
// backend wants a proper null (not 0, which means "blocked"). Round-trip
// through a computed that normalises empty/NaN back to null.
const defaultPersonalStorageBytes = computed<number | null>({
    get: () => form.default_personal_storage_bytes,
    set: (v) => {
        form.default_personal_storage_bytes = v === null || Number.isNaN(v as unknown as number) || (v as unknown as string) === '' ? null : Number(v);
    },
});

const defaultEntityStorageBytes = computed<number | null>({
    get: () => form.default_entity_storage_bytes,
    set: (v) => {
        form.default_entity_storage_bytes = v === null || Number.isNaN(v as unknown as number) || (v as unknown as string) === '' ? null : Number(v);
    },
});

// The setting is stored in bytes but edited in whole MB — round-trip through
// a computed. Clamp to the PHP ceiling so the field can't offer more than the
// server accepts (the backend rejects it anyway, but this keeps the UI honest).
const maxUploadMb = computed<number>({
    get: () => Math.round(form.max_upload_bytes / (1024 * 1024)),
    set: (v) => {
        const mb = Number.isNaN(v as unknown as number) || (v as unknown as string) === '' ? 1 : Math.max(1, Number(v));
        form.max_upload_bytes = Math.min(mb * 1024 * 1024, props.php_upload_ceiling_bytes);
    },
});
const phpCeilingMb = computed(() => Math.floor(props.php_upload_ceiling_bytes / (1024 * 1024)));

const dirty = computed(() => form.isDirty);
const loading = ref(true);
setTimeout(() => { loading.value = false; }, 700);

// Section registry — the single place to add a settings section. Add an id +
// label here and a matching <template v-if="active === 'id'"> block below;
// the sidebar, routing and active-state all derive from this list. Order
// matters: the file system leads since it's the most-used feature.
type SectionId = 'fs' | 'access' | 'policy' | 'banner';
const sections = computed<{ id: SectionId; label: string }[]>(() => [
    { id: 'fs', label: t('admin.app_settings.filesystem') },
    { id: 'access', label: t('admin.app_settings.access') },
    { id: 'policy', label: t('admin.app_settings.policy') },
    { id: 'banner', label: t('admin.app_settings.announcement') },
]);
const active = ref<SectionId>('fs');

const severityOptions = computed<{ id: Severity; label: string }[]>(() => [
    { id: 'info', label: t('admin.app_settings.severity_info') },
    { id: 'warn', label: t('admin.app_settings.severity_warn') },
    { id: 'danger', label: t('admin.app_settings.severity_danger') },
    { id: 'success', label: t('admin.app_settings.severity_success') },
]);

function save() {
    form.patch('/admin/settings', {
        preserveScroll: true,
        onSuccess: () => {
            push(t('admin.app_settings.toast_saved'), 'success');
            form.defaults();
        },
        onError: () => push(t('admin.app_settings.toast_error'), 'danger'),
    });
}

function discard() {
    form.reset();
    push(t('admin.app_settings.toast_discarded'), 'info');
}

const roleOpen = ref(false);
</script>

<template>
    <Head :title="t('admin.app_settings.title')" />

    <div :style="{ display: 'flex', gap: '16px', minHeight: 'calc(100vh - 42px - 48px)' }">
        <!-- Section sidebar -->
        <aside
            :style="{
                width: 'var(--settings-aside-w)',
                padding: '14px 8px',
                background: 'var(--bg2)',
                flexShrink: 0,
                borderRadius: 'var(--radius-card)',
                border: '1px solid var(--border)',
                alignSelf: 'flex-start',
            }"
        >
            <div
                class="cmd-mono cmd-uc"
                :style="{ fontSize: '9px', color: 'var(--fg-mute)', padding: '0 8px 8px', fontWeight: 500 }"
            >{{ t('admin.app_settings.sections_label') }}</div>
            <div
                v-for="s in sections"
                :key="s.id"
                @click="active = s.id"
                :style="{
                    padding: '6px 10px',
                    fontSize: '12px',
                    borderRadius: '4px',
                    cursor: 'pointer',
                    marginBottom: '1px',
                    background: active === s.id ? 'var(--accent-soft)' : 'transparent',
                    color: active === s.id ? 'var(--fg)' : 'var(--fg-dim)',
                }"
            >{{ s.label }}</div>
        </aside>

        <!-- Content -->
        <div :style="{ flex: 1, minWidth: 0 }">
            <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: '20px' }">
                <div>
                    <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">{{ t('admin.app_settings.title') }}</h1>
                    <div
                        class="cmd-mono"
                        :style="{ marginTop: '3px', fontSize: '11.5px', color: 'var(--fg-mute)' }"
                    >{{ t('admin.app_settings.subtitle') }}</div>
                </div>
                <div :style="{ display: 'flex', gap: '6px', alignItems: 'center' }">
                    <span
                        v-if="dirty"
                        :style="{ fontSize: '11px', color: 'var(--warning)', display: 'flex', alignItems: 'center', gap: '5px' }"
                    >
                        <Dot color="var(--warning)" :size="5" />
                        {{ t('admin.app_settings.unsaved') }}
                    </span>
                    <button
                        v-if="dirty"
                        type="button"
                        @click="discard"
                        :style="{ background: 'transparent', color: 'var(--fg-dim)', border: '1px solid var(--border)', padding: '5px 10px', borderRadius: '5px', fontSize: '11.5px', cursor: 'pointer', fontFamily: 'inherit' }"
                    >{{ t('admin.app_settings.discard') }}</button>
                    <button
                        type="button"
                        :disabled="form.processing || !dirty"
                        @click="save"
                        :style="{
                            background: 'var(--accent)',
                            color: '#fff',
                            border: 'none',
                            padding: '5px 11px',
                            borderRadius: '5px',
                            fontSize: '11.5px',
                            fontWeight: 500,
                            cursor: form.processing || !dirty ? 'not-allowed' : 'pointer',
                            opacity: form.processing || !dirty ? 0.55 : 1,
                            fontFamily: 'inherit',
                        }"
                    >{{ t('common.save') }}</button>
                </div>
            </div>

            <div :style="{ maxWidth: '640px' }">
                <template v-if="loading">
                    <div v-for="i in 3" :key="i" :style="{ marginBottom: '20px' }">
                        <Skeleton :width="'100%'" :height="120" :radius="6" />
                    </div>
                </template>

                <template v-else>
                    <!-- Tilgang -->
                    <section v-show="active === 'access'">
                        <div :style="{ fontSize: '15px', fontWeight: 600, marginBottom: '3px', color: 'var(--fg)' }">{{ t('admin.app_settings.access') }}</div>
                        <div :style="{ fontSize: '12px', color: 'var(--fg-dim)', marginBottom: '16px' }">
                            {{ t('admin.app_settings.access_desc') }}
                        </div>
                        <div :style="{ display: 'flex', flexDirection: 'column', gap: '14px' }">
                            <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '16px' }">
                                <div :style="{ flex: 1 }">
                                    <div :style="{ fontSize: '12.5px', fontWeight: 500, color: 'var(--fg)' }">{{ t('admin.app_settings.site_up') }}</div>
                                    <div :style="{ fontSize: '11px', color: 'var(--fg-dim)', marginTop: '2px' }">
                                        {{ t('admin.app_settings.site_up_desc') }}
                                    </div>
                                </div>
                                <Toggle v-model="form.site_up" :label="t('admin.app_settings.site_up')" />
                            </div>

                            <div>
                                <div
                                    class="cmd-mono cmd-uc"
                                    :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em' }"
                                >{{ t('admin.app_settings.maintenance_message') }}</div>
                                <input
                                    v-model="form.maintenance_message"
                                    type="text"
                                    :style="{
                                        width: '100%',
                                        background: 'var(--panel2)',
                                        border: '1px solid var(--border)',
                                        borderRadius: '5px',
                                        padding: '7px 10px',
                                        color: 'var(--fg)',
                                        fontSize: '12px',
                                        outline: 'none',
                                        fontFamily: 'inherit',
                                    }"
                                />
                                <div v-if="form.errors.maintenance_message" :style="{ color: 'var(--danger)', fontSize: '11px', marginTop: '4px' }">
                                    {{ form.errors.maintenance_message }}
                                </div>
                            </div>

                            <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '16px' }">
                                <div :style="{ flex: 1 }">
                                    <div :style="{ fontSize: '12.5px', fontWeight: 500, color: 'var(--fg)' }">{{ t('admin.app_settings.login_enabled') }}</div>
                                    <div :style="{ fontSize: '11px', color: 'var(--fg-dim)', marginTop: '2px' }">
                                        {{ t('admin.app_settings.login_enabled_desc') }}
                                    </div>
                                </div>
                                <Toggle v-model="form.login_enabled" :label="t('admin.app_settings.login_enabled')" />
                            </div>

                            <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '16px' }">
                                <div :style="{ flex: 1 }">
                                    <div :style="{ fontSize: '12.5px', fontWeight: 500, color: 'var(--fg)' }">{{ t('admin.app_settings.registration_open') }}</div>
                                    <div :style="{ fontSize: '11px', color: 'var(--fg-dim)', marginTop: '2px' }">
                                        {{ t('admin.app_settings.registration_open_desc') }}
                                    </div>
                                </div>
                                <Toggle v-model="form.registration_open" :label="t('admin.app_settings.registration_open')" />
                            </div>

                            <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '16px' }">
                                <div :style="{ flex: 1 }">
                                    <div :style="{ fontSize: '12.5px', fontWeight: 500, color: 'var(--fg)' }">{{ t('admin.app_settings.require_verification') }}</div>
                                    <div :style="{ fontSize: '11px', color: 'var(--fg-dim)', marginTop: '2px' }">
                                        {{ t('admin.app_settings.require_verification_desc') }}
                                    </div>
                                </div>
                                <Toggle v-model="form.require_email_verification" :label="t('admin.app_settings.require_verification')" />
                            </div>
                        </div>
                    </section>

                    <!-- Retningslinjer -->
                    <section v-show="active === 'policy'">
                        <div :style="{ fontSize: '15px', fontWeight: 600, marginBottom: '3px', color: 'var(--fg)' }">{{ t('admin.app_settings.policy') }}</div>
                        <div :style="{ fontSize: '12px', color: 'var(--fg-dim)', marginBottom: '16px' }">
                            {{ t('admin.app_settings.policy_desc') }}
                        </div>
                        <div :style="{ display: 'flex', flexDirection: 'column', gap: '14px' }">
                            <div>
                                <div
                                    class="cmd-mono cmd-uc"
                                    :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em' }"
                                >{{ t('admin.app_settings.default_role') }}</div>
                                <div :style="{ position: 'relative' }">
                                    <button
                                        type="button"
                                        @click="roleOpen = !roleOpen"
                                        :style="{
                                            width: '100%',
                                            background: 'var(--panel2)',
                                            border: '1px solid var(--border)',
                                            borderRadius: '5px',
                                            padding: '7px 10px',
                                            color: 'var(--fg)',
                                            fontSize: '12px',
                                            cursor: 'pointer',
                                            display: 'flex',
                                            justifyContent: 'space-between',
                                            alignItems: 'center',
                                            fontFamily: 'inherit',
                                        }"
                                    >
                                        <span>{{ form.default_role }}</span>
                                        <Icon name="chevD" :size="12" />
                                    </button>
                                    <div
                                        v-if="roleOpen"
                                        :style="{
                                            position: 'absolute',
                                            top: '100%',
                                            left: 0,
                                            right: 0,
                                            marginTop: '2px',
                                            zIndex: 10,
                                            overflow: 'hidden',
                                            background: 'var(--panel)',
                                            border: '1px solid var(--border)',
                                            borderRadius: 'var(--radius-card)',
                                        }"
                                    >
                                        <div
                                            v-for="r in roles"
                                            :key="r"
                                            @click="form.default_role = r; roleOpen = false"
                                            :style="{
                                                padding: '7px 10px',
                                                fontSize: '12px',
                                                cursor: 'pointer',
                                                background: r === form.default_role ? 'var(--accent-soft)' : 'transparent',
                                                color: 'var(--fg)',
                                            }"
                                        >{{ r }}</div>
                                    </div>
                                </div>
                            </div>

                            <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '16px' }">
                                <div :style="{ flex: 1 }">
                                    <div :style="{ fontSize: '12.5px', fontWeight: 500, color: 'var(--fg)' }">{{ t('admin.app_settings.require_2fa_admin') }}</div>
                                    <div :style="{ fontSize: '11px', color: 'var(--fg-dim)', marginTop: '2px' }">
                                        {{ t('admin.app_settings.require_2fa_admin_desc') }}
                                    </div>
                                </div>
                                <Toggle v-model="form.require_2fa_for_admins" :label="t('admin.app_settings.require_2fa_admin')" />
                            </div>

                            <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '16px' }">
                                <div :style="{ flex: 1 }">
                                    <div :style="{ fontSize: '12.5px', fontWeight: 500, color: 'var(--fg)' }">{{ t('admin.app_settings.send_welcome') }}</div>
                                    <div :style="{ fontSize: '11px', color: 'var(--fg-dim)', marginTop: '2px' }">
                                        {{ t('admin.app_settings.send_welcome_desc') }}
                                    </div>
                                </div>
                                <Toggle v-model="form.send_welcome_notification" :label="t('admin.app_settings.send_welcome')" />
                            </div>
                        </div>
                    </section>

                    <!-- Annonsering -->
                    <section v-show="active === 'banner'">
                        <div :style="{ fontSize: '15px', fontWeight: 600, marginBottom: '3px', color: 'var(--fg)' }">{{ t('admin.app_settings.announcement') }}</div>
                        <div :style="{ fontSize: '12px', color: 'var(--fg-dim)', marginBottom: '16px' }">
                            {{ t('admin.app_settings.announcement_desc') }}
                        </div>
                        <div :style="{ display: 'flex', flexDirection: 'column', gap: '14px' }">
                            <div>
                                <div
                                    class="cmd-mono cmd-uc"
                                    :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em' }"
                                >{{ t('admin.app_settings.banner_text') }}</div>
                                <input
                                    v-model="form.announcement_banner"
                                    type="text"
                                    :placeholder="t('admin.app_settings.banner_placeholder')"
                                    :style="{
                                        width: '100%',
                                        background: 'var(--panel2)',
                                        border: '1px solid var(--border)',
                                        borderRadius: '5px',
                                        padding: '7px 10px',
                                        color: 'var(--fg)',
                                        fontSize: '12px',
                                        outline: 'none',
                                        fontFamily: 'inherit',
                                    }"
                                />
                            </div>

                            <div>
                                <div
                                    class="cmd-mono cmd-uc"
                                    :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em' }"
                                >{{ t('admin.app_settings.severity') }}</div>
                                <div :style="{ display: 'inline-flex', gap: '1px', background: 'var(--border)', padding: '1px', borderRadius: '4px' }">
                                    <button
                                        v-for="o in severityOptions"
                                        :key="o.id"
                                        type="button"
                                        @click="form.announcement_severity = o.id"
                                        :style="{
                                            padding: '5px 14px',
                                            fontSize: '11px',
                                            cursor: 'pointer',
                                            fontFamily: 'inherit',
                                            borderRadius: '3px',
                                            border: 'none',
                                            background: form.announcement_severity === o.id ? 'var(--panel2)' : 'var(--panel)',
                                            color: form.announcement_severity === o.id ? 'var(--fg)' : 'var(--fg-dim)',
                                        }"
                                    >{{ o.label }}</button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Filsystem -->
                    <section v-show="active === 'fs'">
                        <div :style="{ fontSize: '15px', fontWeight: 600, marginBottom: '3px', color: 'var(--fg)' }">{{ t('admin.app_settings.filesystem') }}</div>
                        <div :style="{ fontSize: '12px', color: 'var(--fg-dim)', marginBottom: '16px' }">
                            {{ t('admin.app_settings.filesystem_desc') }}
                        </div>
                        <div :style="{ display: 'flex', flexDirection: 'column', gap: '14px' }">
                            <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '16px' }">
                                <div :style="{ flex: 1 }">
                                    <div :style="{ fontSize: '12.5px', fontWeight: 500, color: 'var(--fg)' }">{{ t('admin.app_settings.files_feature_enabled') }}</div>
                                    <div :style="{ fontSize: '11px', color: 'var(--fg-dim)', marginTop: '2px' }">
                                        {{ t('admin.app_settings.files_feature_enabled_desc') }}
                                    </div>
                                </div>
                                <Toggle v-model="form.files_feature_enabled" :label="t('admin.app_settings.files_feature_enabled')" />
                            </div>

                            <div>
                                <div
                                    class="cmd-mono cmd-uc"
                                    :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em' }"
                                >{{ t('admin.app_settings.max_upload') }}</div>
                                <div :style="{ display: 'flex', alignItems: 'center', gap: '8px' }">
                                    <input
                                        v-model.number="maxUploadMb"
                                        type="number"
                                        min="1"
                                        :max="phpCeilingMb"
                                        class="cmd-mono"
                                        :style="{
                                            width: '110px',
                                            background: 'var(--panel2)',
                                            border: '1px solid var(--border)',
                                            borderRadius: '5px',
                                            padding: '7px 10px',
                                            color: 'var(--fg)',
                                            fontSize: '12px',
                                            outline: 'none',
                                        }"
                                    />
                                    <span :style="{ fontSize: '12px', color: 'var(--fg-dim)' }">MB</span>
                                </div>
                                <p :style="{ fontSize: '11px', color: 'var(--fg-mute)', marginTop: '4px' }">
                                    {{ t('admin.app_settings.max_upload_desc', { max: phpCeilingMb }) }}
                                </p>
                                <p v-if="form.errors.max_upload_bytes" :style="{ fontSize: '11px', color: 'var(--danger)', marginTop: '4px' }">
                                    {{ form.errors.max_upload_bytes }}
                                </p>
                            </div>

                            <div>
                                <div
                                    class="cmd-mono cmd-uc"
                                    :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em' }"
                                >{{ t('admin.app_settings.max_share_days') }}</div>
                                <input
                                    v-model.number="form.max_share_days"
                                    type="number"
                                    min="1"
                                    max="30"
                                    class="cmd-mono"
                                    :style="{
                                        width: '90px',
                                        background: 'var(--panel2)',
                                        border: '1px solid var(--border)',
                                        borderRadius: '5px',
                                        padding: '7px 10px',
                                        color: 'var(--fg)',
                                        fontSize: '12px',
                                        outline: 'none',
                                    }"
                                />
                            </div>

                            <div>
                                <div
                                    class="cmd-mono cmd-uc"
                                    :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em' }"
                                >{{ t('admin.app_settings.default_personal_storage') }}</div>
                                <input
                                    v-model.number="defaultPersonalStorageBytes"
                                    type="number"
                                    min="-1"
                                    class="cmd-mono"
                                    :placeholder="t('admin.app_settings.storage_placeholder')"
                                    :style="{
                                        width: '100%',
                                        maxWidth: '360px',
                                        background: 'var(--panel2)',
                                        border: '1px solid var(--border)',
                                        borderRadius: '5px',
                                        padding: '7px 10px',
                                        color: 'var(--fg)',
                                        fontSize: '12px',
                                        outline: 'none',
                                    }"
                                />
                                <p :style="{ fontSize: '11px', color: 'var(--fg-mute)', marginTop: '4px' }">
                                    {{ t('admin.app_settings.default_personal_storage_desc') }}
                                </p>
                            </div>

                            <div>
                                <div
                                    class="cmd-mono cmd-uc"
                                    :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em' }"
                                >{{ t('admin.app_settings.default_entity_storage') }}</div>
                                <input
                                    v-model.number="defaultEntityStorageBytes"
                                    type="number"
                                    min="-1"
                                    class="cmd-mono"
                                    :placeholder="t('admin.app_settings.storage_placeholder')"
                                    :style="{
                                        width: '100%',
                                        maxWidth: '360px',
                                        background: 'var(--panel2)',
                                        border: '1px solid var(--border)',
                                        borderRadius: '5px',
                                        padding: '7px 10px',
                                        color: 'var(--fg)',
                                        fontSize: '12px',
                                        outline: 'none',
                                    }"
                                />
                                <p :style="{ fontSize: '11px', color: 'var(--fg-mute)', marginTop: '4px' }">
                                    {{ t('admin.app_settings.default_entity_storage_desc') }}
                                </p>
                            </div>
                        </div>
                    </section>
                </template>
            </div>
        </div>
    </div>
</template>
