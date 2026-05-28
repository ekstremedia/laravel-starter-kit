<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/CommandLayout.vue';
import CommandDialog from '@/Components/Command/Dialog.vue';
import CmdButton from '@/Components/Command/Button.vue';
import Field from '@/Components/Command/Field.vue';
import Icon from '@/Components/Command/Icon.vue';
import Dot from '@/Components/Command/Dot.vue';
import Toggle from '@/Components/Command/Toggle.vue';
import MultiSelect from 'primevue/multiselect';
import { formatDateTime } from '@/composables/useDateTime';
import { useConfirm } from 'primevue/useconfirm';

defineOptions({ layout: AdminLayout });

interface ActivityItem { id: number; log_name: string | null; description: string; event: string | null; created_at: string }
interface CustomerMembership { id: number; name: string; slug: string; roles: string[] }
interface Props {
    user: {
        id: number; first_name: string; last_name: string; full_name: string; email: string;
        email_verified_at: string | null; banned_at: string | null; banned_reason: string | null;
        last_login_at: string | null; created_at: string; two_factor_enabled: boolean;
        is_super_admin: boolean; avatar_url: string | null; avatar_thumb_url: string | null;
        unread_notifications_count: number;
        platform_permissions: string[];
        customers: CustomerMembership[];
    };
    assignable_roles: string[];
    activity: ActivityItem[];
}
const props = defineProps<Props>();
const { t } = useI18n();

const isAdmin = props.user.is_super_admin;
const confirm = useConfirm();
const roleUpdating = ref<number | null>(null);

// Local editable copy of each customer's roles — separate from the server
// prop so opening the dropdown doesn't flicker while the PATCH is in flight.
const editableRoles = ref<Record<number, string[]>>(
    Object.fromEntries(props.user.customers.map((c) => [c.id, [...c.roles]])),
);

function syncCustomerRoles(customer: CustomerMembership) {
    const roles = editableRoles.value[customer.id] ?? [];
    const unchanged = roles.length === customer.roles.length
        && roles.every((r) => customer.roles.includes(r));
    if (unchanged) return;
    roleUpdating.value = customer.id;
    router.patch(
        `/admin/users/${props.user.id}/customers/${customer.id}/role`,
        { roles },
        {
            preserveScroll: true,
            onFinish: () => { roleUpdating.value = null; },
        },
    );
}

// Platform-level capabilities (column-backed, not Spatie). Optimistic local
// state so the toggle responds immediately; props refresh on the PATCH reload.
const platformPermUpdating = ref(false);
const emailTemplatesGranted = ref(props.user.platform_permissions.includes('manage_email_templates'));

function toggleEmailTemplates(value: boolean) {
    const previous = emailTemplatesGranted.value;
    emailTemplatesGranted.value = value;
    platformPermUpdating.value = true;
    router.patch(
        `/admin/users/${props.user.id}/platform-permission`,
        { capability: 'manage_email_templates', enabled: value },
        {
            preserveScroll: true,
            // Revert the optimistic toggle if the request fails.
            onError: () => { emailTemplatesGranted.value = previous; },
            onFinish: () => { platformPermUpdating.value = false; },
        },
    );
}

const banDialog = ref(false);
const notifyDialog = ref(false);

const banForm = useForm({ reason: '' });
const notifyForm = useForm({ message: '' });

function action(path: string) {
    router.post(`/admin/users/${props.user.id}/${path}`, {}, { preserveScroll: true });
}

function submitBan() {
    banForm.post(`/admin/users/${props.user.id}/ban`, {
        preserveScroll: true,
        onSuccess: () => {
            banDialog.value = false;
            banForm.reset();
        },
    });
}

function submitNotify() {
    notifyForm.post(`/admin/users/${props.user.id}/notify-test`, {
        preserveScroll: true,
        onSuccess: () => {
            notifyDialog.value = false;
            notifyForm.reset();
        },
    });
}

function confirmDestructive(message: string, path: string) {
    confirm.require({
        group: 'command',
        message, header: 'Confirm', icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger', accept: () => action(path),
    });
}

function destroy() {
    confirm.require({
        group: 'command',
        message: `Permanently delete ${props.user.email}?`,
        header: 'Delete user', icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: () => router.delete(`/admin/users/${props.user.id}`),
    });
}

function formatDate(iso: string | null) {
    return formatDateTime(iso);
}

interface Tone { color: string; bg: string; border: string }
const tones: Record<string, Tone> = {
    accent: { color: 'var(--accent)', bg: 'var(--accent-soft)', border: 'var(--accent-border)' },
    success: { color: 'var(--success)', bg: 'rgba(94,229,154,0.12)', border: 'rgba(94,229,154,0.33)' },
    warning: { color: 'var(--warning)', bg: 'rgba(251,191,36,0.12)', border: 'rgba(251,191,36,0.33)' },
    danger: { color: 'var(--danger)', bg: 'rgba(255,138,138,0.12)', border: 'rgba(255,138,138,0.33)' },
    mute: { color: 'var(--fg-dim)', bg: 'var(--panel2)', border: 'var(--border)' },
};

function chipStyle(tone: Tone) {
    return {
        display: 'inline-flex',
        alignItems: 'center',
        gap: '4px',
        padding: '2px 8px',
        fontSize: '11px',
        fontFamily: 'var(--font-mono)',
        color: tone.color,
        background: tone.bg,
        border: `1px solid ${tone.border}`,
        borderRadius: '3px',
    };
}
</script>

<template>
    <div :style="{ padding: '24px 32px', maxWidth: '1100px', margin: '0 auto' }">
        <Head :title="`${user.full_name} · Admin`" />

        <!-- Ban dialog -->
        <CommandDialog
            v-model:visible="banDialog"
            :title="t('admin.users.ban_header')"
            width="480px"
        >
            <p :style="{ margin: '0 0 12px', fontSize: '13px', color: 'var(--fg-dim)', lineHeight: 1.5 }">
                {{ t('admin.users.ban_desc') }}
            </p>
            <textarea
                v-model="banForm.reason"
                rows="3"
                :placeholder="t('admin.users.ban_reason')"
                :style="{
                    width: '100%',
                    background: 'var(--panel2)',
                    border: '1px solid var(--border)',
                    borderRadius: '5px',
                    padding: '8px 10px',
                    color: 'var(--fg)',
                    fontSize: '13px',
                    outline: 'none',
                    fontFamily: 'inherit',
                    resize: 'vertical',
                    minHeight: '72px',
                }"
            ></textarea>
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="banDialog = false">
                    {{ t('common.cancel') }}
                </CmdButton>
                <CmdButton variant="danger" size="sm" :loading="banForm.processing" @click="submitBan">
                    {{ t('admin.users.ban') }}
                </CmdButton>
            </template>
        </CommandDialog>

        <!-- Test notification dialog -->
        <CommandDialog
            v-model:visible="notifyDialog"
            :title="t('admin.users.test_notification_header')"
            width="480px"
        >
            <Field
                v-model="notifyForm.message"
                :placeholder="t('admin.users.test_notification_message')"
            />
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="notifyDialog = false">
                    {{ t('common.cancel') }}
                </CmdButton>
                <CmdButton variant="primary" size="sm" :loading="notifyForm.processing" @click="submitNotify">
                    {{ t('admin.users.send') }}
                </CmdButton>
            </template>
        </CommandDialog>

        <div :style="{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: '8px', marginBottom: '20px' }">
            <Link
                href="/admin/users"
                :style="{ fontSize: '12px', color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '6px' }"
            >
                <Icon name="arrow" :size="12" :style="{ transform: 'rotate(180deg)' }" />
                {{ t('admin.users.all_users') }}
            </Link>
            <div :style="{ display: 'flex', gap: '6px' }">
                <Link :href="`/admin/users/${user.id}/edit`" :style="{ textDecoration: 'none' }">
                    <CmdButton variant="ghost" size="sm">
                        <template #icon><Icon name="edit" :size="12" /></template>
                        {{ t('common.edit') }}
                    </CmdButton>
                </Link>
                <CmdButton variant="danger" size="sm" @click="destroy">
                    <template #icon><Icon name="trash" :size="12" /></template>
                    {{ t('common.delete') }}
                </CmdButton>
            </div>
        </div>

        <!-- Header card -->
        <div class="cmd-card" :style="{ padding: '20px', marginBottom: '20px' }">
            <div :style="{ display: 'flex', gap: '16px', alignItems: 'flex-start', flexWrap: 'wrap' }">
                <img
                    v-if="user.avatar_url"
                    :src="user.avatar_url"
                    :alt="user.full_name"
                    :style="{ width: '88px', height: '88px', borderRadius: '50%', objectFit: 'cover', border: '2px solid var(--border)', flexShrink: 0 }"
                />
                <div
                    v-else
                    :style="{
                        width: '88px',
                        height: '88px',
                        borderRadius: '50%',
                        background: 'var(--accent)',
                        color: '#fff',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        fontSize: '26px',
                        fontWeight: 600,
                        fontFamily: 'var(--font-mono)',
                        flexShrink: 0,
                    }"
                >
                    {{ user.first_name[0] }}{{ user.last_name[0] }}
                </div>
                <div :style="{ flex: 1, minWidth: 0 }">
                    <h1 :style="{ margin: 0, fontSize: '22px', fontWeight: 600, letterSpacing: '-0.02em', color: 'var(--fg)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">
                        {{ user.full_name }}
                    </h1>
                    <p :style="{ margin: '3px 0 0', fontSize: '13px', color: 'var(--fg-dim)', overflow: 'hidden', textOverflow: 'ellipsis' }">
                        {{ user.email }}
                    </p>
                    <div :style="{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '5px', marginTop: '12px' }">
                        <span v-if="user.is_super_admin" :style="chipStyle(tones.danger)">SuperAdmin</span>
                        <span v-if="user.email_verified_at" :style="chipStyle(tones.success)">verified</span>
                        <span v-else :style="chipStyle(tones.warning)">unverified</span>
                        <span v-if="user.two_factor_enabled" :style="chipStyle(tones.success)">2FA on</span>
                        <span v-if="user.banned_at" :style="chipStyle(tones.danger)">banned {{ formatDate(user.banned_at) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="cmd-stack-sm" :style="{ display: 'grid', gridTemplateColumns: 'repeat(3, minmax(0, 1fr))', gap: '16px' }">
            <!-- Actions -->
            <section class="cmd-card" :style="{ padding: '16px', display: 'flex', flexDirection: 'column', gap: '6px' }">
                <h2 :style="{ margin: '0 0 8px', fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">
                    {{ t('admin.users.actions') }}
                </h2>

                <CmdButton
                    v-if="!user.email_verified_at"
                    variant="ghost"
                    size="sm"
                    full-width
                    @click="action('verify')"
                >
                    <template #icon><Icon name="check" :size="12" /></template>
                    <span :style="{ flex: 1, textAlign: 'left' }">{{ t('admin.users.mark_verified') }}</span>
                </CmdButton>
                <CmdButton
                    v-else
                    variant="ghost"
                    size="sm"
                    full-width
                    @click="confirmDestructive('Clear email verification?', 'unverify')"
                >
                    <template #icon><Icon name="x" :size="12" /></template>
                    <span :style="{ flex: 1, textAlign: 'left' }">{{ t('admin.users.clear_verification') }}</span>
                </CmdButton>

                <CmdButton
                    v-if="!user.email_verified_at"
                    variant="ghost"
                    size="sm"
                    full-width
                    @click="action('resend-verification')"
                >
                    <template #icon><Icon name="mail" :size="12" /></template>
                    <span :style="{ flex: 1, textAlign: 'left' }">{{ t('admin.users.resend_verification') }}</span>
                </CmdButton>

                <CmdButton
                    v-if="!user.banned_at && !isAdmin"
                    variant="danger"
                    size="sm"
                    full-width
                    @click="banDialog = true"
                >
                    <template #icon><Icon name="shield" :size="12" /></template>
                    <span :style="{ flex: 1, textAlign: 'left' }">{{ t('admin.users.ban') }}</span>
                </CmdButton>
                <CmdButton
                    v-else-if="user.banned_at"
                    variant="ghost"
                    size="sm"
                    full-width
                    @click="action('unban')"
                >
                    <template #icon><Icon name="restore" :size="12" /></template>
                    <span :style="{ flex: 1, textAlign: 'left' }">{{ t('admin.users.unban') }}</span>
                </CmdButton>

                <CmdButton variant="ghost" size="sm" full-width @click="action('send-password-reset')">
                    <template #icon><Icon name="key" :size="12" /></template>
                    <span :style="{ flex: 1, textAlign: 'left' }">{{ t('admin.users.send_password_reset') }}</span>
                </CmdButton>

                <CmdButton
                    v-if="user.two_factor_enabled"
                    variant="ghost"
                    size="sm"
                    full-width
                    @click="confirmDestructive('Reset 2FA for this user? They will need to set it up again.', 'reset-2fa')"
                >
                    <template #icon><Icon name="shield" :size="12" /></template>
                    <span :style="{ flex: 1, textAlign: 'left' }">{{ t('admin.users.reset_2fa') }}</span>
                </CmdButton>

                <CmdButton variant="ghost" size="sm" full-width @click="notifyDialog = true">
                    <template #icon><Icon name="bell" :size="12" /></template>
                    <span :style="{ flex: 1, textAlign: 'left' }">{{ t('admin.users.send_test') }}</span>
                </CmdButton>

                <CmdButton
                    v-if="!isAdmin"
                    variant="ghost"
                    size="sm"
                    full-width
                    @click="action('impersonate')"
                >
                    <template #icon><Icon name="user" :size="12" /></template>
                    <span :style="{ flex: 1, textAlign: 'left' }">{{ t('admin.users.impersonate') }}</span>
                </CmdButton>
            </section>

            <!-- Meta -->
            <section class="cmd-card" :style="{ padding: '16px', gridColumn: 'span 2' }">
                <h2 :style="{ margin: '0 0 12px', fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">
                    {{ t('admin.users.metadata') }}
                </h2>
                <dl
                    :style="{ display: 'grid', gridTemplateColumns: '160px 1fr', rowGap: '6px', columnGap: '12px', fontSize: '12.5px', margin: 0 }"
                >
                    <dt class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em' }">{{ t('admin.users.user_id') }}</dt>
                    <dd class="cmd-mono" :style="{ margin: 0, color: 'var(--fg)' }">{{ user.id }}</dd>
                    <dt class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em' }">{{ t('admin.users.email') }}</dt>
                    <dd class="cmd-mono" :style="{ margin: 0, color: 'var(--fg)', overflow: 'hidden', textOverflow: 'ellipsis' }">{{ user.email }}</dd>
                    <dt class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em' }">{{ t('admin.users.verified_at') }}</dt>
                    <dd class="cmd-mono" :style="{ margin: 0, color: 'var(--fg)' }">{{ formatDate(user.email_verified_at) }}</dd>
                    <dt class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em' }">{{ t('dashboard.member_since') }}</dt>
                    <dd class="cmd-mono" :style="{ margin: 0, color: 'var(--fg)' }">{{ formatDate(user.created_at) }}</dd>
                    <dt class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em' }">{{ t('admin.users.last_login') }}</dt>
                    <dd class="cmd-mono" :style="{ margin: 0, color: 'var(--fg)' }">{{ formatDate(user.last_login_at) }}</dd>
                    <dt class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em' }">{{ t('admin.users.two_factor') }}</dt>
                    <dd :style="{ margin: 0, color: 'var(--fg)', display: 'inline-flex', alignItems: 'center', gap: '6px' }">
                        <Dot :color="user.two_factor_enabled ? 'var(--success)' : 'var(--fg-mute)'" :size="5" />
                        <span class="cmd-mono">{{ user.two_factor_enabled ? 'enabled' : 'off' }}</span>
                    </dd>
                    <dt class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em' }">{{ t('admin.users.banned_at') }}</dt>
                    <dd class="cmd-mono" :style="{ margin: 0, color: 'var(--fg)' }">{{ formatDate(user.banned_at) }}</dd>
                    <template v-if="user.banned_reason">
                        <dt class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em' }">{{ t('admin.users.ban_reason_label') }}</dt>
                        <dd :style="{ margin: 0, color: 'var(--fg)' }">{{ user.banned_reason }}</dd>
                    </template>
                    <dt class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em' }">{{ t('admin.users.unread_notifications') }}</dt>
                    <dd class="cmd-mono" :style="{ margin: 0, color: 'var(--fg)' }">{{ user.unread_notifications_count }}</dd>
                </dl>
            </section>

            <!-- Platform access (grantable capabilities) -->
            <section class="cmd-card" :style="{ padding: '16px', gridColumn: 'span 3' }">
                <h2 :style="{ margin: '0 0 4px', fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">
                    {{ t('admin.users.platform_access') }}
                </h2>
                <p :style="{ margin: '0 0 14px', fontSize: '12px', color: 'var(--fg-dim)' }">
                    {{ t('admin.users.platform_access_desc') }}
                </p>

                <div
                    :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '16px', padding: '12px 14px', border: '1px solid var(--border)', borderRadius: '6px', background: 'var(--bg)' }"
                >
                    <div :style="{ minWidth: 0 }">
                        <div :style="{ fontSize: '13px', fontWeight: 500, color: 'var(--fg)' }">
                            {{ t('admin.users.cap_manage_email_templates') }}
                        </div>
                        <div :style="{ fontSize: '11.5px', color: 'var(--fg-mute)', marginTop: '2px' }">
                            {{ t('admin.users.cap_manage_email_templates_desc') }}
                        </div>
                    </div>
                    <div :style="{ display: 'flex', alignItems: 'center', gap: '8px', flexShrink: 0 }">
                        <span v-if="isAdmin" :style="{ fontSize: '11.5px', color: 'var(--fg-mute)', fontStyle: 'italic' }">
                            {{ t('admin.users.granted_via_superadmin') }}
                        </span>
                        <Toggle
                            v-else
                            :model-value="emailTemplatesGranted"
                            :disabled="platformPermUpdating"
                            :label="t('admin.users.cap_manage_email_templates')"
                            @update:model-value="toggleEmailTemplates"
                        />
                    </div>
                </div>
            </section>

            <!-- Customer memberships with per-customer role -->
            <section
                class="cmd-card"
                :style="{ padding: '16px', gridColumn: 'span 3' }"
            >
                <div :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '12px' }">
                    <h2 :style="{ margin: 0, fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">
                        {{ t('admin.users.customer_memberships') }} ({{ user.customers.length }})
                    </h2>
                    <Link
                        :href="`/admin/users/${user.id}/edit`"
                        :style="{ fontSize: '12px', color: 'var(--accent)', textDecoration: 'none' }"
                    >
                        {{ t('admin.users.manage_memberships') }} →
                    </Link>
                </div>
                <div
                    v-if="user.customers.length"
                    :style="{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) minmax(260px, 320px)', rowGap: '1px', background: 'var(--border)', border: '1px solid var(--border)', borderRadius: '6px', overflow: 'visible' }"
                >
                    <template v-for="c in user.customers" :key="c.id">
                        <div :style="{ background: 'var(--bg)', padding: '10px 12px', display: 'flex', alignItems: 'center', gap: '10px' }">
                            <Icon name="customer" :size="12" />
                            <div :style="{ display: 'flex', flexDirection: 'column', minWidth: 0 }">
                                <Link
                                    :href="`/admin/customers/${c.id}/edit`"
                                    :style="{ fontSize: '13px', fontWeight: 500, color: 'var(--fg)', textDecoration: 'none', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }"
                                >{{ c.name }}</Link>
                                <span class="cmd-mono" :style="{ fontSize: '11px', color: 'var(--fg-mute)' }">/c/{{ c.slug }}</span>
                            </div>
                        </div>
                        <div :style="{ background: 'var(--bg)', padding: '8px 12px', display: 'flex', alignItems: 'center' }">
                            <MultiSelect
                                v-model="editableRoles[c.id]"
                                :options="assignable_roles"
                                :disabled="roleUpdating === c.id"
                                display="chip"
                                :placeholder="t('admin.users.select_roles', 'Velg roller')"
                                class="w-full"
                                :style="{ width: '100%' }"
                                @hide="syncCustomerRoles(c)"
                            />
                        </div>
                    </template>
                </div>
                <p
                    v-else
                    :style="{ fontSize: '12.5px', color: 'var(--fg-mute)', fontStyle: 'italic', margin: 0 }"
                >{{ t('admin.users.not_member') }}</p>
            </section>

            <!-- Activity -->
            <section class="cmd-card" :style="{ padding: '16px', gridColumn: 'span 3' }">
                <div :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '10px' }">
                    <h2 :style="{ margin: 0, fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">
                        {{ t('admin.users.recent_activity') }}
                    </h2>
                    <Link
                        :href="`/admin/activity?user_id=${user.id}`"
                        :style="{ fontSize: '11.5px', color: 'var(--accent)', textDecoration: 'none' }"
                    >{{ t('admin.users.open_in_log') }}</Link>
                </div>
                <ul v-if="activity.length" :style="{ listStyle: 'none', padding: 0, margin: 0 }">
                    <li
                        v-for="(a, i) in activity"
                        :key="a.id"
                        :style="{
                            padding: '8px 0',
                            display: 'flex',
                            alignItems: 'flex-start',
                            gap: '10px',
                            fontSize: '12.5px',
                            borderTop: i === 0 ? 'none' : '1px solid var(--border)',
                        }"
                    >
                        <span v-if="a.log_name" :style="chipStyle(tones.mute)">{{ a.log_name }}</span>
                        <div :style="{ flex: 1 }">
                            <p :style="{ margin: 0, color: 'var(--fg)' }">{{ a.description }}</p>
                            <p class="cmd-mono" :style="{ margin: '2px 0 0', fontSize: '11px', color: 'var(--fg-mute)' }">
                                {{ formatDate(a.created_at) }} · {{ a.event ?? '—' }}
                            </p>
                        </div>
                    </li>
                </ul>
                <p
                    v-else
                    :style="{ fontSize: '12.5px', color: 'var(--fg-mute)', fontStyle: 'italic', margin: 0 }"
                >{{ t('admin.users.no_activity') }}</p>
            </section>
        </div>
    </div>
</template>
