<script setup lang="ts">
/*
 * Members — workspace-scoped user management.
 *
 * Accessible only to users who hold the workspace-level `Admin` role on the
 * active workspace (or to platform SuperAdmins). Backend routes live in
 * routes/workspace.php under the `members.` name prefix and are gated by
 * EnsureWorkspaceAdmin middleware.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from 'primevue/useconfirm';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CmdButton from '@/Components/Command/Button.vue';
import Icon from '@/Components/Command/Icon.vue';
import MultiSelect from 'primevue/multiselect';
import { useWorkspace } from '@/composables/useWorkspace';
import { useCommandToasts } from '@/composables/useCommandToasts';

defineOptions({ layout: CommandLayout });

interface Member {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    email: string;
    roles: string[];
}

interface Invitation {
    id: number;
    email: string;
    role: string;
    expired: boolean;
    expires_at: string | null;
}

interface Props {
    members: Member[];
    invitations: Invitation[];
    assignable_roles: string[];
}

const props = defineProps<Props>();
const { workspaceUrl } = useWorkspace();
const { push } = useCommandToasts();
const { t } = useI18n();
const confirmer = useConfirm();

const inviteForm = useForm<{ email: string; roles: string[] }>({ email: '', roles: ['User'] });
// Email invitation (works for people without an account yet — they get a link).
const emailInviteForm = useForm<{ email: string; role: string }>({ email: '', role: 'User' });
const pendingId = ref<number | null>(null);

// Local per-row editable copy so opening the dropdown doesn't race the PATCH.
// Kept in sync with `props.members` via a watcher so Inertia partial reloads
// (fresh member added by another admin, a sync from another window) don't
// leave the MultiSelect showing stale roles and tripping the unchanged-check.
const editableRoles = ref<Record<number, string[]>>({});
watch(
    () => props.members,
    (members) => {
        const next: Record<number, string[]> = {};
        for (const m of members) {
            next[m.id] = [...m.roles];
        }
        editableRoles.value = next;
    },
    { immediate: true, deep: true },
);

const roleOptions = computed(() => props.assignable_roles);

function invite() {
    inviteForm.post(workspaceUrl('/members'), {
        preserveScroll: true,
        onSuccess: () => {
            inviteForm.reset('email');
            inviteForm.roles = ['User'];
        },
    });
}

function inviteByEmail() {
    emailInviteForm.post(workspaceUrl('/members/invitations'), {
        preserveScroll: true,
        onSuccess: () => {
            emailInviteForm.reset('email');
            emailInviteForm.role = 'User';
        },
    });
}

function revokeInvitation(invitation: Invitation) {
    router.delete(workspaceUrl(`/members/invitations/${invitation.id}`), { preserveScroll: true });
}

function syncRoles(member: Member) {
    const roles = editableRoles.value[member.id] ?? [];
    const unchanged = roles.length === member.roles.length
        && roles.every((r) => member.roles.includes(r));
    if (unchanged) return;
    pendingId.value = member.id;
    router.patch(
        workspaceUrl(`/members/${member.id}/role`),
        { roles },
        {
            preserveScroll: true,
            onFinish: () => { pendingId.value = null; },
        },
    );
}

function remove(member: Member) {
    confirmer.require({
        group: 'command',
        header: t('common.delete'),
        message: t('admin.users.confirm_remove_member', { email: member.email }),
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: t('common.remove'),
        rejectLabel: t('common.cancel'),
        accept: () => {
            pendingId.value = member.id;
            router.delete(workspaceUrl(`/members/${member.id}`), {
                preserveScroll: true,
                onFinish: () => {
                    pendingId.value = null;
                },
            });
        },
    });
}
</script>

<template>
    <div class="page">
        <Head :title="t('workspace.members.title')" />
        <header class="page__head">
            <div>
                <h1>{{ t('workspace.members.title') }}</h1>
                <p class="muted">{{ t('workspace.members.subtitle') }}</p>
            </div>
        </header>

        <section class="invite">
            <form @submit.prevent="invite" class="invite__form">
                <label class="field">
                    <span>{{ t('workspace.members.email') }}</span>
                    <input
                        v-model="inviteForm.email"
                        type="email"
                        required
                        autocomplete="off"
                        :placeholder="t('workspace.members.email_placeholder')"
                    />
                </label>
                <label class="field">
                    <span>{{ t('workspace.members.roles') }}</span>
                    <MultiSelect
                        v-model="inviteForm.roles"
                        :options="roleOptions"
                        display="chip"
                        :placeholder="t('workspace.members.select_roles')"
                    />
                </label>
                <CmdButton
                    type="submit"
                    variant="primary"
                    size="md"
                    :loading="inviteForm.processing"
                    :disabled="inviteForm.roles.length === 0"
                >
                    <template #icon><Icon name="plus" :size="12" /></template>
                    {{ t('workspace.members.add') }}
                </CmdButton>
            </form>
            <p v-if="inviteForm.errors.email" class="error">{{ inviteForm.errors.email }}</p>
            <p v-else-if="inviteForm.errors.roles" class="error">{{ inviteForm.errors.roles }}</p>
        </section>

        <section class="table">
            <div class="table__row table__row--head">
                <span>{{ t('workspace.members.header_name') }}</span>
                <span>{{ t('workspace.members.header_email') }}</span>
                <span>{{ t('workspace.members.header_roles') }}</span>
                <span></span>
            </div>
            <div v-for="m in props.members" :key="m.id" class="table__row" :class="{ 'table__row--busy': pendingId === m.id }">
                <span>{{ m.full_name }}</span>
                <span class="muted">{{ m.email }}</span>
                <span>
                    <MultiSelect
                        v-model="editableRoles[m.id]"
                        :options="roleOptions"
                        display="chip"
                        :disabled="pendingId === m.id"
                        class="w-full"
                        @hide="syncRoles(m)"
                    />
                </span>
                <span class="table__actions">
                    <CmdButton
                        variant="ghost"
                        size="sm"
                        :disabled="pendingId === m.id"
                        :aria-label="t('workspace.members.remove_aria')"
                        @click="remove(m)"
                    >
                        <template #icon><Icon name="trash" :size="12" /></template>
                    </CmdButton>
                </span>
            </div>
            <div v-if="props.members.length === 0" class="empty">{{ t('workspace.members.empty') }}</div>
        </section>

        <section class="invite">
            <div class="section-head">
                <h2>{{ t('workspace.members.invite_title') }}</h2>
                <p class="muted">{{ t('workspace.members.invite_subtitle') }}</p>
            </div>
            <form @submit.prevent="inviteByEmail" class="invite__form">
                <label class="field">
                    <span>{{ t('workspace.members.email') }}</span>
                    <input
                        v-model="emailInviteForm.email"
                        type="email"
                        required
                        autocomplete="off"
                        :placeholder="t('workspace.members.email_placeholder')"
                    />
                </label>
                <label class="field">
                    <span>{{ t('workspace.members.invite_role') }}</span>
                    <select v-model="emailInviteForm.role">
                        <option v-for="r in roleOptions" :key="r" :value="r">{{ r }}</option>
                    </select>
                </label>
                <CmdButton type="submit" variant="primary" size="md" :loading="emailInviteForm.processing">
                    <template #icon><Icon name="mail" :size="12" /></template>
                    {{ t('workspace.members.invite_send') }}
                </CmdButton>
            </form>
            <p v-if="emailInviteForm.errors.email" class="error">{{ emailInviteForm.errors.email }}</p>
        </section>

        <section class="table">
            <div class="table__row table__row--head table__row--invites">
                <span>{{ t('workspace.members.pending_title') }}</span>
                <span>{{ t('workspace.members.header_roles') }}</span>
                <span></span>
            </div>
            <div v-for="inv in props.invitations" :key="inv.id" class="table__row table__row--invites">
                <span>
                    {{ inv.email }}
                    <span v-if="inv.expired" class="badge badge--warn">{{ t('workspace.members.pending_expired') }}</span>
                </span>
                <span class="muted">{{ inv.role }}</span>
                <span class="table__actions">
                    <CmdButton variant="ghost" size="sm" :aria-label="t('workspace.members.revoke_aria')" @click="revokeInvitation(inv)">
                        <template #icon><Icon name="x" :size="12" /></template>
                        {{ t('workspace.members.revoke') }}
                    </CmdButton>
                </span>
            </div>
            <div v-if="props.invitations.length === 0" class="empty">{{ t('workspace.members.pending_empty') }}</div>
        </section>
    </div>
</template>

<style scoped>
.page {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 24px;
    max-width: 960px;
    margin: 0 auto;
}
.page__head h1 {
    font-size: 20px;
    font-weight: 600;
    margin: 0;
}
.page__head .muted {
    color: var(--fg-mute);
    font-size: 13px;
    margin-top: 4px;
}
.invite__form {
    display: grid;
    grid-template-columns: 1fr 200px auto;
    gap: 12px;
    align-items: end;
}
.field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
}
.field span {
    color: var(--fg-mute);
}
.field input,
.field select,
.table__row select {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 6px 8px;
    color: var(--fg);
    font-size: 13px;
}
button {
    background: var(--accent);
    color: white;
    border: 0;
    border-radius: 6px;
    padding: 8px 14px;
    font-size: 13px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}
button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
button.danger {
    background: transparent;
    color: var(--danger);
    padding: 4px 8px;
}
.error {
    color: var(--danger);
    font-size: 12px;
    margin-top: 8px;
}
.table {
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
}
.table__row {
    display: grid;
    grid-template-columns: 1.2fr 1.4fr 200px 60px;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    font-size: 13px;
    border-top: 1px solid var(--border);
}
.table__row:first-child {
    border-top: 0;
}
.table__row--head {
    background: var(--panel2);
    font-weight: 600;
    color: var(--fg-mute);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.table__row--busy {
    opacity: 0.6;
}
.table__actions {
    display: flex;
    justify-content: flex-end;
}
.muted {
    color: var(--fg-mute);
}
.empty {
    padding: 24px;
    text-align: center;
    color: var(--fg-mute);
    font-size: 13px;
}
.section-head {
    margin-bottom: 12px;
}
.section-head h2 {
    font-size: 15px;
    font-weight: 600;
    margin: 0;
}
.section-head .muted {
    font-size: 12px;
    margin-top: 2px;
}
.table__row--invites {
    grid-template-columns: 1.4fr 200px auto;
}
.field select {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 6px 8px;
    color: var(--fg);
    font-size: 13px;
}
.badge {
    display: inline-block;
    margin-left: 8px;
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
}
.badge--warn {
    background: color-mix(in srgb, var(--danger) 15%, transparent);
    color: var(--danger);
}
</style>
