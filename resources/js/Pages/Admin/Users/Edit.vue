<script setup lang="ts">
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/CommandLayout.vue';
import MultiSelect from 'primevue/multiselect';
import Password from 'primevue/password';
import CommandDialog from '@/Components/Command/Dialog.vue';
import Field from '@/Components/Command/Field.vue';
import CmdButton from '@/Components/Command/Button.vue';
import Toggle from '@/Components/Command/Toggle.vue';
import Icon from '@/Components/Command/Icon.vue';
import RoleBadge from '@/Components/Command/RoleBadge.vue';

defineOptions({ layout: AdminLayout });

interface WorkspaceItem { id: number; name: string; slug: string; roles?: string[] }
interface Props {
    user: {
        id: number; first_name: string; last_name: string; email: string;
        workspaces: WorkspaceItem[];
    };
    assignable_roles: string[];
    all_workspaces: WorkspaceItem[];
}
const props = defineProps<Props>();
const { t } = useI18n();

const form = useForm({
    first_name: props.user.first_name,
    last_name: props.user.last_name,
    email: props.user.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.put(`/admin/users/${props.user.id}`);
}

const addWorkspaceDialog = ref(false);
const selectedWorkspaceIds = ref<number[]>([]);
const selectedRoles = ref<string[]>(['User']);
const notifyOnAdd = ref(true);
const notifyOnRemove = ref(true);
const removeDialog = ref(false);
const removingWorkspace = ref<WorkspaceItem | null>(null);
const addingWorkspace = ref(false);
const removingWorkspaceRequest = ref(false);

const availableWorkspaces = () => {
    const currentIds = new Set(props.user.workspaces.map(c => c.id));
    return props.all_workspaces.filter(c => !currentIds.has(c.id));
};

function openAddDialog() {
    selectedWorkspaceIds.value = [];
    selectedRoles.value = ['User'];
    notifyOnAdd.value = true;
    addWorkspaceDialog.value = true;
}

function confirmAdd() {
    if (!selectedWorkspaceIds.value.length || !selectedRoles.value.length) return;
    addingWorkspace.value = true;
    router.post(`/admin/users/${props.user.id}/workspaces`, {
        workspace_ids: selectedWorkspaceIds.value,
        roles: selectedRoles.value,
        notify: notifyOnAdd.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { addWorkspaceDialog.value = false; },
        onFinish: () => { addingWorkspace.value = false; },
    });
}

function openRemoveDialog(workspace: WorkspaceItem) {
    removingWorkspace.value = workspace;
    notifyOnRemove.value = true;
    removeDialog.value = true;
}

function confirmRemove() {
    if (!removingWorkspace.value) return;
    removingWorkspaceRequest.value = true;
    router.delete(`/admin/users/${props.user.id}/workspaces/${removingWorkspace.value.id}`, {
        data: { notify: notifyOnRemove.value },
        preserveScroll: true,
        onSuccess: () => {
            removeDialog.value = false;
            removingWorkspace.value = null;
        },
        onFinish: () => { removingWorkspaceRequest.value = false; },
    });
}
</script>

<template>
    <div :style="{ padding: '24px 32px', maxWidth: '1100px', margin: '0 auto' }">
        <Head :title="`Edit ${user.email} · Admin`" />

        <!-- Add workspace dialog -->
        <CommandDialog
            v-model:visible="addWorkspaceDialog"
            :title="t('admin.users.add_to_workspace')"
            width="460px"
        >
            <div :style="{ display: 'flex', flexDirection: 'column', gap: '14px' }">
                <div>
                    <label
                        class="cmd-mono cmd-uc"
                        :style="{ display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em', fontWeight: 500 }"
                    >{{ t('admin.workspaces.title') }}</label>
                    <MultiSelect
                        appendTo="body"
                        v-model="selectedWorkspaceIds"
                        :options="availableWorkspaces()"
                        optionLabel="name"
                        optionValue="id"
                        :placeholder="t('admin.users.select_workspace')"
                        :filter="true"
                        :filterPlaceholder="t('common.search')"
                        display="chip"
                        class="w-full"
                    />
                </div>
                <div>
                    <label
                        class="cmd-mono cmd-uc"
                        :style="{ display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em', fontWeight: 500 }"
                    >{{ t('admin.users.roles') }}</label>
                    <MultiSelect
                        appendTo="body"
                        v-model="selectedRoles"
                        :options="assignable_roles"
                        display="chip"
                        :placeholder="t('admin.users.select_roles')"
                        class="w-full"
                    />
                </div>
                <label :style="{ display: 'flex', alignItems: 'center', gap: '10px', fontSize: '12.5px', color: 'var(--fg)', cursor: 'pointer' }">
                    <Toggle v-model="notifyOnAdd" />
                    <span>{{ t('admin.users.notify_user') }}</span>
                </label>
            </div>
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="addWorkspaceDialog = false">
                    {{ t('common.cancel') }}
                </CmdButton>
                <CmdButton
                    variant="primary"
                    size="sm"
                    :disabled="!selectedWorkspaceIds.length || !selectedRoles.length"
                    :loading="addingWorkspace"
                    @click="confirmAdd"
                >
                    {{ t('common.add') }}
                </CmdButton>
            </template>
        </CommandDialog>

        <!-- Remove workspace dialog -->
        <CommandDialog
            v-model:visible="removeDialog"
            :title="t('admin.users.remove_from_workspace')"
            width="440px"
        >
            <p
                :style="{ margin: '0 0 14px', fontSize: '13px', color: 'var(--fg-dim)', lineHeight: 1.5 }"
                v-html="t('admin.users.confirm_remove_from_workspace_html', {
                    email: user.email,
                    workspace: removingWorkspace?.name ?? '',
                })"
            />
            <label :style="{ display: 'flex', alignItems: 'center', gap: '10px', fontSize: '12.5px', color: 'var(--fg)', cursor: 'pointer' }">
                <Toggle v-model="notifyOnRemove" />
                <span>{{ t('admin.users.notify_user') }}</span>
            </label>
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="removeDialog = false">
                    {{ t('common.cancel') }}
                </CmdButton>
                <CmdButton
                    variant="danger"
                    size="sm"
                    :loading="removingWorkspaceRequest"
                    @click="confirmRemove"
                >
                    {{ t('common.delete') }}
                </CmdButton>
            </template>
        </CommandDialog>

        <div :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '24px' }">
            <h1 :style="{ margin: 0, fontSize: '24px', fontWeight: 600, letterSpacing: '-0.02em', color: 'var(--fg)' }">
                {{ t('admin.users.edit_user') }}
            </h1>
            <Link
                href="/admin/users"
                :style="{ fontSize: '12px', color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '4px' }"
            >{{ t('common.back') }}</Link>
        </div>

        <div
            :style="{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
                gap: '20px',
            }"
        >
            <form
                @submit.prevent="submit"
                class="cmd-card"
                :style="{ padding: '20px', display: 'flex', flexDirection: 'column', gap: '14px' }"
            >
                <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px' }">
                    <Field
                        v-model="form.first_name"
                        :label="t('admin.users.first_name')"
                        :error="form.errors.first_name"
                    />
                    <Field
                        v-model="form.last_name"
                        :label="t('admin.users.last_name')"
                        :error="form.errors.last_name"
                    />
                </div>
                <Field
                    v-model="form.email"
                    type="email"
                    :label="t('admin.users.email')"
                    :error="form.errors.email"
                />
                <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px' }">
                    <div>
                        <label
                            class="cmd-mono cmd-uc"
                            :style="{ display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em', fontWeight: 500 }"
                        >{{ t('admin.users.new_password') }}</label>
                        <Password v-model="form.password" toggleMask :feedback="false" class="w-full" inputClass="w-full" />
                        <p v-if="form.errors.password" :style="{ color: 'var(--danger)', fontSize: '11px', marginTop: '4px' }">{{ form.errors.password }}</p>
                    </div>
                    <div>
                        <label
                            class="cmd-mono cmd-uc"
                            :style="{ display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em', fontWeight: 500 }"
                        >{{ t('admin.users.confirm_password') }}</label>
                        <Password v-model="form.password_confirmation" toggleMask :feedback="false" class="w-full" inputClass="w-full" />
                    </div>
                </div>
                <p :style="{ fontSize: '11.5px', color: 'var(--fg-mute)', margin: 0, lineHeight: 1.45 }">
                    {{ t('admin.users.roles_are_workspace_scoped') }}
                </p>
                <div :style="{ display: 'flex', gap: '8px', marginTop: '4px' }">
                    <CmdButton type="submit" variant="primary" size="md" :loading="form.processing">
                        <template #icon>
                            <Icon name="check" :size="13" />
                        </template>
                        {{ t('common.save') }}
                    </CmdButton>
                    <Link href="/admin/users" :style="{ textDecoration: 'none' }">
                        <CmdButton variant="ghost" size="md">
                            {{ t('common.cancel') }}
                        </CmdButton>
                    </Link>
                </div>
            </form>

            <section
                class="cmd-card"
                :style="{ padding: '20px' }"
            >
                <div :style="{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '14px' }">
                    <h2 :style="{ margin: 0, fontSize: '14px', fontWeight: 600, color: 'var(--fg)' }">
                        {{ t('admin.users.workspace_memberships') }} ({{ user.workspaces.length }})
                    </h2>
                    <CmdButton
                        variant="primary"
                        size="sm"
                        :disabled="availableWorkspaces().length === 0"
                        @click="openAddDialog"
                    >
                        {{ t('common.add') }}
                    </CmdButton>
                </div>

                <ul
                    v-if="user.workspaces.length"
                    :style="{ listStyle: 'none', padding: 0, margin: 0 }"
                >
                    <li
                        v-for="(workspace, i) in user.workspaces"
                        :key="workspace.id"
                        :style="{
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'space-between',
                            gap: '12px',
                            padding: '10px 0',
                            borderTop: i === 0 ? 'none' : '1px solid var(--border)',
                        }"
                    >
                        <div :style="{ minWidth: 0 }">
                            <Link
                                :href="`/admin/workspaces/${workspace.id}/edit`"
                                :style="{ fontSize: '13px', fontWeight: 500, color: 'var(--fg)', textDecoration: 'none' }"
                            >
                                {{ workspace.name }}
                            </Link>
                            <p class="cmd-mono" :style="{ margin: '2px 0 0', fontSize: '11px', color: 'var(--fg-mute)' }">
                                /w/{{ workspace.slug }}
                            </p>
                            <div
                                v-if="workspace.roles && workspace.roles.length"
                                :style="{ display: 'flex', flexWrap: 'wrap', gap: '4px', marginTop: '6px' }"
                            >
                                <RoleBadge v-for="r in workspace.roles" :key="r" :role="r" />
                            </div>
                            <span
                                v-else
                                :style="{ fontSize: '11px', color: 'var(--fg-mute)', fontStyle: 'italic', marginTop: '6px', display: 'inline-block' }"
                            >{{ t('admin.users.no_roles') }}</span>
                        </div>
                        <div :style="{ display: 'flex', gap: '4px', flexShrink: 0 }">
                            <!-- Roles are assigned per-workspace on the Show page's
                                 workspace-memberships MultiSelect. The Edit page
                                 only adds/removes the membership itself. -->
                            <Link
                                :href="`/admin/users/${user.id}`"
                                :style="{ fontSize: '11px', color: 'var(--accent)', textDecoration: 'none', padding: '4px 8px' }"
                            >{{ t('admin.users.edit_roles') }}</Link>
                            <CmdButton
                                variant="ghost"
                                size="sm"
                                :aria-label="t('admin.users.remove_from_workspace_aria', { workspace: workspace.name })"
                                @click="openRemoveDialog(workspace)"
                            >
                                {{ t('common.remove') }}
                            </CmdButton>
                        </div>
                    </li>
                </ul>
                <p
                    v-else
                    :style="{ fontSize: '12.5px', color: 'var(--fg-mute)', fontStyle: 'italic', margin: 0 }"
                >{{ t('admin.users.not_member') }}</p>
            </section>
        </div>
    </div>
</template>
