<script setup lang="ts">
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from 'primevue/useconfirm';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Field from '@/Components/Command/Field.vue';
import Toggle from '@/Components/Command/Toggle.vue';
import Icon from '@/Components/Command/Icon.vue';
import Dot from '@/Components/Command/Dot.vue';
import PageTitle from '@/Components/Command/PageTitle.vue';
import { useCommandToasts } from '@/composables/useCommandToasts';
import { humanBytes as sharedHumanBytes } from '@/utils/bytes';

defineOptions({ layout: CommandLayout });

const { t } = useI18n();
const { push } = useCommandToasts();
const confirmer = useConfirm();

interface Member {
    id: number;
    public_id: string;
    email: string;
    full_name: string;
    avatar_thumb_url: string | null;
    roles: string[];
}
interface WorkspaceData {
    id: number;
    slug: string;
    name: string;
    headline: string | null;
    about: string | null;
    location: string | null;
    website: string | null;
    status: 'active' | 'suspended';
    files_feature_enabled: boolean;
    company_files_enabled: boolean;
    // Raw DB value: null = unlimited (no cap), -1 = explicit unlimited,
    // 0 = blocked, N>0 = byte cap.
    storage_quota_bytes: number | null;
    storage_used_bytes: number;
    default_member_storage_bytes: number | null;
    users: Member[];
}

const props = defineProps<{
    workspace: WorkspaceData;
    global_files_feature_enabled: boolean;
    global_default_personal_storage_bytes: number | null;
    assignableRoles: string[];
    defaultRole: string;
}>();

// --- Helpers for "GB input + radio mode" ---
// The backend stores raw bytes; the UI talks in GB (with one decimal place) to
// keep the text inputs short. These helpers round-trip null/-1/0/N without
// losing the sentinel distinction.
const BYTES_PER_GB = 1024 * 1024 * 1024;

function bytesToGb(bytes: number | null | undefined): number | '' {
    if (bytes == null || bytes <= 0) return '';
    return Math.round((bytes / BYTES_PER_GB) * 10) / 10;
}

function gbToBytes(gb: number | string | null): number | null {
    if (gb === '' || gb === null || gb === undefined) return null;
    const n = typeof gb === 'string' ? Number(gb) : gb;
    if (!isFinite(n) || n <= 0) return null;
    return Math.round(n * BYTES_PER_GB);
}

// Custom mode requires a positive GB number. gbToBytes returns null for
// empty/invalid/non-positive — treating that as "unlimited" silently
// would let admins block storage by accident, so guard before submit.
function isValidCustomGb(gb: number | string | null): boolean {
    if (gb === '' || gb === null || gb === undefined) return false;
    const n = typeof gb === 'string' ? Number(gb) : gb;
    return isFinite(n) && n > 0;
}

type QuotaMode = 'unlimited' | 'custom' | 'blocked';
type MemberMode = 'inherit' | 'unlimited' | 'custom' | 'blocked';

// Derive initial modes from the raw bytes values.
function initialCompanyMode(bytes: number | null): QuotaMode {
    if (bytes === 0) return 'blocked';
    if (bytes === null || bytes < 0) return 'unlimited';
    return 'custom';
}

function initialMemberMode(bytes: number | null): MemberMode {
    if (bytes === null) return 'inherit';
    if (bytes < 0) return 'unlimited';
    if (bytes === 0) return 'blocked';
    return 'custom';
}

const companyMode = ref<QuotaMode>(initialCompanyMode(props.workspace.storage_quota_bytes));
const companyCustomGb = ref<number | ''>(bytesToGb(props.workspace.storage_quota_bytes));

const memberMode = ref<MemberMode>(initialMemberMode(props.workspace.default_member_storage_bytes));
const memberCustomGb = ref<number | ''>(bytesToGb(props.workspace.default_member_storage_bytes));

const form = useForm({
    name: props.workspace.name,
    status: props.workspace.status,
    // Coerce each per-workspace flag to false whenever the global feature
    // is off so a stale `true` can't be submitted while the toggle is
    // disabled. Personal and company are otherwise independent.
    files_feature_enabled: props.global_files_feature_enabled && props.workspace.files_feature_enabled,
    company_files_enabled: props.global_files_feature_enabled && props.workspace.company_files_enabled,
    storage_quota_bytes: props.workspace.storage_quota_bytes,
    default_member_storage_bytes: props.workspace.default_member_storage_bytes,
});

const statusOpen = ref(false);

function materializeCompanyQuota(): number | null {
    if (companyMode.value === 'unlimited') return -1;
    if (companyMode.value === 'blocked') return 0;
    return gbToBytes(companyCustomGb.value);
}

function materializeMemberQuota(): number | null {
    if (memberMode.value === 'inherit') return null;
    if (memberMode.value === 'unlimited') return -1;
    if (memberMode.value === 'blocked') return 0;
    return gbToBytes(memberCustomGb.value);
}

// Shared byte formatter + admin-specific "Unlimited" label when no cap
// is set. The admin edit page surfaces an explicit "Unlimited" word
// (not an em dash), so wrap the shared helper with the extra branch.
function humanBytes(n: number | null | undefined): string {
    if (n == null || n < 0) return t('admin.workspaces.unlimited');
    return sharedHumanBytes(n);
}

function save() {
    if (companyMode.value === 'custom' && !isValidCustomGb(companyCustomGb.value)) {
        push(t('admin.workspaces.custom_quota_required'), 'danger');
        return;
    }
    if (memberMode.value === 'custom' && !isValidCustomGb(memberCustomGb.value)) {
        push(t('admin.workspaces.custom_quota_required'), 'danger');
        return;
    }

    form.storage_quota_bytes = materializeCompanyQuota();
    form.default_member_storage_bytes = materializeMemberQuota();

    // Server flashes flash.workspaces.updated via useFlashToast.
    form.put(`/admin/workspaces/${props.workspace.id}`, { preserveScroll: true });
}

// The member-add panel sends a role alongside the email — the backend
// requires at least one assignable role. `memberRole` drives the selector and
// is folded into the `roles` array on submit.
const memberRole = ref<string>(props.defaultRole);
const memberForm = useForm<{ email: string; roles: string[] }>({ email: '', roles: [props.defaultRole] });

function attach() {
    memberForm.roles = [memberRole.value];
    // Server flashes flash.workspaces.member_added — reset the input
    // here but let the flash surface the confirmation toast.
    memberForm.post(`/admin/workspaces/${props.workspace.id}/members`, {
        preserveScroll: true,
        onSuccess: () => {
            memberForm.reset('email');
            memberRole.value = props.defaultRole;
        },
    });
}

function detach(member: Member) {
    confirmer.require({
        group: 'command',
        message: t('admin.workspaces.confirm_detach', { email: member.email, name: props.workspace.name }),
        header: t('admin.workspaces.detach'),
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: t('admin.workspaces.detach'),
        rejectLabel: t('common.cancel'),
        accept: () => {
            // Server flashes flash.workspaces.member_removed.
            router.delete(`/admin/workspaces/${props.workspace.id}/members/${member.id}`, { preserveScroll: true });
        },
    });
}
</script>

<template>
    <div>
        <Head :title="`${workspace.name} · Admin`" />

        <div :style="{ marginBottom: '4px' }">
        <PageTitle :title="workspace.name">
            <template #subtitle>
                <span :style="{ display: 'inline-flex', alignItems: 'center', gap: '8px' }">
                    <code :style="{ background: 'var(--panel2)', border: '1px solid var(--border)', padding: '1px 6px', borderRadius: '3px', color: 'var(--fg-dim)' }">/w/{{ workspace.slug }}</code>
                    <span>·</span>
                    <span :style="{ display: 'inline-flex', alignItems: 'center', gap: '5px' }">
                        <Dot :color="workspace.status === 'active' ? 'var(--success)' : 'var(--warning)'" :size="5" />
                        <span :style="{ color: workspace.status === 'active' ? 'var(--fg)' : 'var(--fg-dim)' }">{{ workspace.status }}</span>
                    </span>
                </span>
            </template>
            <template #actions>
                <Link
                    href="/admin/workspaces"
                    :style="{ fontSize: '11.5px', color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '5px' }"
                >
                    <Icon name="chevR" :size="10" :style="{ transform: 'rotate(180deg)' }" />
                    {{ t('common.back') }}
                </Link>
            </template>
        </PageTitle>
    </div>

    <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: '16px' }">
        <!-- Settings -->
        <section class="cmd-card" :style="{ padding: '20px' }">
            <h2 :style="{ fontSize: '14px', fontWeight: 600, color: 'var(--fg)', margin: '0 0 16px' }">
                {{ t('admin.workspaces.settings') }}
            </h2>
            <form @submit.prevent="save" :style="{ display: 'flex', flexDirection: 'column', gap: '14px' }">
                <Field
                    v-model="form.name"
                    :label="t('common.name')"
                    :error="form.errors.name"
                />

                <div>
                    <div
                        class="cmd-mono cmd-uc"
                        :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', fontWeight: 500, letterSpacing: '0.06em' }"
                    >{{ t('common.status') }}</div>
                    <div :style="{ position: 'relative' }">
                        <button
                            type="button"
                            @click="statusOpen = !statusOpen"
                            :style="{
                                width: '100%',
                                background: 'var(--panel2)',
                                border: '1px solid var(--border)',
                                borderRadius: '5px',
                                padding: '8px 10px',
                                color: 'var(--fg)',
                                fontSize: '13px',
                                cursor: 'pointer',
                                display: 'flex',
                                justifyContent: 'space-between',
                                alignItems: 'center',
                                fontFamily: 'inherit',
                            }"
                        >
                            <span :style="{ textTransform: 'capitalize' }">{{ form.status }}</span>
                            <Icon name="chevD" :size="11" />
                        </button>
                        <div
                            v-if="statusOpen"
                            :style="{
                                position: 'absolute',
                                top: '100%',
                                left: 0,
                                right: 0,
                                marginTop: '2px',
                                zIndex: 10,
                                background: 'var(--panel)',
                                border: '1px solid var(--border)',
                                borderRadius: '5px',
                                overflow: 'hidden',
                                boxShadow: '0 8px 24px rgba(0,0,0,0.35)',
                            }"
                        >
                            <div
                                v-for="opt in (['active', 'suspended'] as const)"
                                :key="opt"
                                @click="form.status = opt; statusOpen = false"
                                :style="{
                                    padding: '7px 10px',
                                    fontSize: '12px',
                                    cursor: 'pointer',
                                    background: opt === form.status ? 'var(--accent-soft)' : 'transparent',
                                    color: 'var(--fg)',
                                    textTransform: 'capitalize',
                                }"
                            >{{ opt }}</div>
                        </div>
                    </div>
                    <p :style="{ fontSize: '11px', color: 'var(--fg-mute)', marginTop: '5px' }">{{ t('admin.workspaces.suspended_hint') }}</p>
                </div>

                <div :style="{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '16px', paddingTop: '6px' }">
                    <div :style="{ flex: 1, minWidth: 0 }">
                        <div :style="{ fontSize: '12.5px', fontWeight: 500, color: 'var(--fg)', display: 'inline-flex', alignItems: 'center', gap: '6px' }">
                            <Icon name="disk" :size="12" :style="{ color: 'var(--accent)' }" />
                            {{ t('admin.workspaces.files_enabled') }}
                        </div>
                        <div :style="{ fontSize: '11px', color: 'var(--fg-dim)', marginTop: '2px' }">{{ t('admin.workspaces.files_enabled_hint') }}</div>
                        <i18n-t
                            v-if="!global_files_feature_enabled"
                            keypath="admin.workspaces.files_global_disabled_hint"
                            tag="p"
                            :style="{ fontSize: '11px', color: 'var(--warning)', marginTop: '5px' }"
                        >
                            <template #appSettings>
                                <Link
                                    href="/admin/settings"
                                    :style="{ color: 'var(--warning)', textDecoration: 'underline' }"
                                >{{ t('admin.workspaces.app_settings_link') }}</Link>
                            </template>
                        </i18n-t>
                    </div>
                    <Toggle
                        v-model="form.files_feature_enabled"
                        :disabled="!global_files_feature_enabled"
                        :label="t('admin.workspaces.files_feature')"
                    />
                </div>
                <p v-if="form.errors.files_feature_enabled" :style="{ fontSize: '11px', color: 'var(--danger)', marginTop: '-6px' }">
                    {{ form.errors.files_feature_enabled }}
                </p>

                <!-- Company files is an independent toggle — one, the
                     other, or both can run per workspace. Only gated on
                     the global Files feature (enforced server-side). -->
                <div :style="{ borderTop: '1px solid var(--border)', paddingTop: '14px', marginTop: '2px' }">
                    <div :style="{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '16px' }">
                        <div :style="{ flex: 1, minWidth: 0 }">
                            <div :style="{ fontSize: '12.5px', fontWeight: 500, color: 'var(--fg)', display: 'inline-flex', alignItems: 'center', gap: '6px' }">
                                <Icon name="disk" :size="12" :style="{ color: 'var(--accent)' }" />
                                {{ t('admin.workspaces.company_files') }}
                            </div>
                            <div :style="{ fontSize: '11px', color: 'var(--fg-dim)', marginTop: '2px' }">{{ t('admin.workspaces.company_files_hint') }}</div>
                        </div>
                        <Toggle
                            v-model="form.company_files_enabled"
                            :disabled="!global_files_feature_enabled"
                            :label="t('admin.workspaces.company_files')"
                        />
                    </div>

                    <div v-if="form.company_files_enabled" :style="{ marginTop: '14px', display: 'flex', flexDirection: 'column', gap: '14px' }">
                        <!-- Company storage quota -->
                        <div>
                            <div class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', fontWeight: 500, letterSpacing: '0.06em' }">
                                {{ t('admin.workspaces.company_storage_quota') }}
                            </div>
                            <div :style="{ display: 'flex', gap: '14px', alignItems: 'center', flexWrap: 'wrap', marginBottom: '6px' }">
                                <label :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: 'var(--fg)', cursor: 'pointer' }">
                                    <input type="radio" value="unlimited" v-model="companyMode" />
                                    {{ t('admin.workspaces.unlimited') }}
                                </label>
                                <label :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: 'var(--fg)', cursor: 'pointer' }">
                                    <input type="radio" value="custom" v-model="companyMode" />
                                    {{ t('admin.workspaces.custom') }}
                                </label>
                                <label :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: 'var(--fg-dim)', cursor: 'pointer' }">
                                    <input type="radio" value="blocked" v-model="companyMode" />
                                    {{ t('admin.workspaces.blocked') }}
                                </label>
                            </div>
                            <div v-if="companyMode === 'custom'" :style="{ display: 'flex', alignItems: 'center', gap: '6px' }">
                                <input
                                    type="number"
                                    min="0"
                                    step="0.1"
                                    v-model.number="companyCustomGb"
                                    :style="{ width: '120px', background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: '5px', padding: '7px 10px', color: 'var(--fg)', fontSize: '12.5px', outline: 'none', fontFamily: 'inherit' }"
                                />
                                <span :style="{ fontSize: '11.5px', color: 'var(--fg-mute)' }">GB</span>
                            </div>
                            <p :style="{ fontSize: '11px', color: 'var(--fg-mute)', marginTop: '6px' }">
                                {{ t('admin.workspaces.company_storage_used', { used: humanBytes(workspace.storage_used_bytes) }) }}
                            </p>
                        </div>

                        <!-- Default member storage -->
                        <div>
                            <div class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', fontWeight: 500, letterSpacing: '0.06em' }">
                                {{ t('admin.workspaces.default_member_storage') }}
                            </div>
                            <div :style="{ display: 'flex', gap: '14px', alignItems: 'center', flexWrap: 'wrap', marginBottom: '6px' }">
                                <label :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: 'var(--fg)', cursor: 'pointer' }">
                                    <input type="radio" value="inherit" v-model="memberMode" />
                                    {{ t('admin.workspaces.inherit_global') }}
                                </label>
                                <label :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: 'var(--fg)', cursor: 'pointer' }">
                                    <input type="radio" value="unlimited" v-model="memberMode" />
                                    {{ t('admin.workspaces.unlimited') }}
                                </label>
                                <label :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: 'var(--fg)', cursor: 'pointer' }">
                                    <input type="radio" value="custom" v-model="memberMode" />
                                    {{ t('admin.workspaces.custom') }}
                                </label>
                                <label :style="{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: 'var(--fg-dim)', cursor: 'pointer' }">
                                    <input type="radio" value="blocked" v-model="memberMode" />
                                    {{ t('admin.workspaces.blocked') }}
                                </label>
                            </div>
                            <div v-if="memberMode === 'custom'" :style="{ display: 'flex', alignItems: 'center', gap: '6px' }">
                                <input
                                    type="number"
                                    min="0"
                                    step="0.1"
                                    v-model.number="memberCustomGb"
                                    :style="{ width: '120px', background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: '5px', padding: '7px 10px', color: 'var(--fg)', fontSize: '12.5px', outline: 'none', fontFamily: 'inherit' }"
                                />
                                <span :style="{ fontSize: '11.5px', color: 'var(--fg-mute)' }">GB / user</span>
                            </div>
                            <p :style="{ fontSize: '11px', color: 'var(--fg-mute)', marginTop: '6px' }">
                                {{ t('admin.workspaces.global_default_member_storage', { value: humanBytes(global_default_personal_storage_bytes) }) }}
                            </p>
                        </div>
                    </div>
                </div>

                <div>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        :style="{
                            background: 'var(--accent)',
                            color: '#fff',
                            border: 'none',
                            padding: '7px 12px',
                            borderRadius: '5px',
                            fontSize: '12px',
                            fontWeight: 500,
                            cursor: form.processing ? 'not-allowed' : 'pointer',
                            opacity: form.processing ? 0.6 : 1,
                            fontFamily: 'inherit',
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: '6px',
                        }"
                    >
                        <Icon name="arrow" :size="12" />
                        {{ t('common.save') }}
                    </button>
                </div>
            </form>
        </section>

        <!-- Members -->
        <section class="cmd-card" :style="{ padding: '20px' }">
            <h2 :style="{ fontSize: '14px', fontWeight: 600, color: 'var(--fg)', margin: '0 0 16px' }">
                {{ t('admin.workspaces.member_count', { count: workspace.users.length }) }}
            </h2>

            <form @submit.prevent="attach" :style="{ display: 'flex', gap: '6px', marginBottom: '14px' }">
                <input
                    v-model="memberForm.email"
                    type="email"
                    :placeholder="t('admin.workspaces.add_member_placeholder')"
                    :style="{
                        flex: 1,
                        background: 'var(--panel2)',
                        border: '1px solid var(--border)',
                        borderRadius: '5px',
                        padding: '7px 10px',
                        color: 'var(--fg)',
                        fontSize: '12.5px',
                        outline: 'none',
                        fontFamily: 'inherit',
                    }"
                />
                <select
                    v-model="memberRole"
                    :aria-label="t('admin.workspaces.member_role')"
                    :style="{
                        background: 'var(--panel2)',
                        border: '1px solid var(--border)',
                        borderRadius: '5px',
                        padding: '7px 10px',
                        color: 'var(--fg)',
                        fontSize: '12.5px',
                        outline: 'none',
                        fontFamily: 'inherit',
                        cursor: 'pointer',
                    }"
                >
                    <option v-for="r in assignableRoles" :key="r" :value="r">{{ r }}</option>
                </select>
                <button
                    type="submit"
                    :disabled="memberForm.processing"
                    :style="{
                        background: 'var(--accent)',
                        color: '#fff',
                        border: 'none',
                        padding: '7px 11px',
                        borderRadius: '5px',
                        fontSize: '11.5px',
                        fontWeight: 500,
                        cursor: memberForm.processing ? 'not-allowed' : 'pointer',
                        opacity: memberForm.processing ? 0.6 : 1,
                        fontFamily: 'inherit',
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: '5px',
                    }"
                >
                    <Icon name="plus" :size="12" />
                    {{ t('common.add') }}
                </button>
            </form>
            <p v-if="memberForm.errors.email || memberForm.errors.roles" :style="{ fontSize: '11px', color: 'var(--danger)', marginTop: '-10px', marginBottom: '10px' }">
                {{ memberForm.errors.email || memberForm.errors.roles }}
            </p>

            <ul
                v-if="workspace.users.length"
                :style="{ listStyle: 'none', padding: 0, margin: 0 }"
            >
                <li
                    v-for="member in workspace.users"
                    :key="member.id"
                    :style="{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '10px',
                        padding: '10px 0',
                        borderBottom: '1px solid var(--border)',
                    }"
                >
                    <Link
                        :href="`/u/${member.public_id}`"
                        :style="{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '10px',
                            flex: 1,
                            minWidth: 0,
                            textDecoration: 'none',
                            color: 'inherit',
                        }"
                    >
                        <div
                            :style="{
                                width: '32px',
                                height: '32px',
                                borderRadius: '50%',
                                background: 'var(--panel2)',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                overflow: 'hidden',
                                flexShrink: 0,
                                border: '1px solid var(--border)',
                            }"
                        >
                            <img
                                v-if="member.avatar_thumb_url"
                                :src="member.avatar_thumb_url"
                                :alt="member.full_name"
                                :style="{ width: '100%', height: '100%', objectFit: 'cover' }"
                            />
                            <Icon v-else name="user" :size="14" />
                        </div>
                        <div :style="{ minWidth: 0, flex: 1 }">
                            <div :style="{ fontSize: '12.5px', fontWeight: 500, color: 'var(--fg)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">
                                {{ member.full_name }}
                            </div>
                            <div class="cmd-mono" :style="{ fontSize: '11px', color: 'var(--fg-dim)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">
                                {{ member.email }}
                            </div>
                        </div>
                    </Link>
                    <div :style="{ display: 'flex', flexWrap: 'wrap', gap: '4px', justifyContent: 'flex-end' }">
                        <span
                            v-for="role in (member.roles.length ? member.roles : [t('admin.workspaces.no_role')])"
                            :key="role"
                            :style="{
                                fontSize: '10px',
                                padding: '2px 7px',
                                borderRadius: '999px',
                                background: member.roles.length ? 'var(--panel2)' : 'transparent',
                                color: member.roles.length ? 'var(--fg-dim)' : 'var(--fg-mute)',
                                border: '1px solid var(--border)',
                                whiteSpace: 'nowrap',
                                fontWeight: 500,
                            }"
                        >{{ role }}</span>
                    </div>
                    <button
                        type="button"
                        :title="t('common.remove')"
                        @click="detach(member)"
                        :style="{ background: 'transparent', border: 'none', color: 'var(--fg-mute)', cursor: 'pointer', padding: '6px', borderRadius: '3px', display: 'flex', alignItems: 'center', justifyContent: 'center' }"
                        class="cmd-member-remove"
                    >
                        <Icon name="trash" :size="12" />
                    </button>
                </li>
            </ul>
            <p v-else :style="{ fontSize: '12px', color: 'var(--fg-mute)', padding: '20px 0', textAlign: 'center' }">
                {{ t('admin.workspaces.no_members') }}
            </p>
        </section>
    </div>
    </div>
</template>

<style scoped>
.cmd-member-remove:hover { color: var(--danger) !important; }
</style>
