<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from 'primevue/useconfirm';
import ConfirmDialog from 'primevue/confirmdialog';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import CommandDialog from '@/Components/Command/Dialog.vue';
import CmdButton from '@/Components/Command/Button.vue';
import Field from '@/Components/Command/Field.vue';
import Icon, { type IconName } from '@/Components/Command/Icon.vue';
import Tabs from '@/Components/Command/Tabs.vue';
import CategoryChip from '@/Components/Equipment/CategoryChip.vue';
import ColorPicker from '@/Components/Equipment/ColorPicker.vue';
import { useWorkspace } from '@/composables/useWorkspace';
import { useModuleFeatures } from '@/composables/useModuleFeatures';
import { relativeTime, absoluteTime } from '@/utils/time';

defineOptions({ layout: CommandLayout });

interface Category {
    id: number;
    name: string;
    color: string | null;
    description: string | null;
}

interface ActivityRow {
    id: number;
    event: string | null;
    description: string | null;
    created_at: string | null;
    causer: { id: number; name: string | null } | null;
}

const props = defineProps<{
    category: Category;
    equipment: { count: number; items: { id: number; name: string }[] };
    activities: ActivityRow[];
    can_manage: boolean;
}>();

const { t, locale } = useI18n();
const { workspaceUrl } = useWorkspace();
const confirm = useConfirm();

// The Log is composable — an admin can toggle it per module / workspace, so the
// tab follows the resolved feature. (Categories ship no files, so there is no
// Files tab at all.)
const { logEnabled } = useModuleFeatures('equipment_category');

// ── Tabs: Details (default) + Log (when enabled).
const tabs = computed<{ key: string; label: string; icon?: IconName }[]>(() => [
    { key: 'details', label: t('equipment_category.tab_details'), icon: 'tag' as IconName },
    ...(logEnabled.value ? [{ key: 'activity', label: t('equipment_category.tab_log'), icon: 'log' as IconName }] : []),
]);
function initialTab(): string {
    if (typeof window !== 'undefined') {
        const tab = new URLSearchParams(window.location.search).get('tab');
        if (tab && tabs.value.some((x) => x.key === tab)) return tab;
    }
    return 'details';
}
const activeTab = ref<string>(initialTab());
watch(activeTab, (v) => {
    if (typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    url.searchParams.set('tab', v);
    window.history.replaceState({}, '', url.toString());
});

const editOpen = ref(false);
const form = useForm<{ name: string; color: string | null; description: string }>({
    name: props.category.name,
    color: props.category.color,
    description: props.category.description ?? '',
});

function openEdit() {
    form.name = props.category.name;
    form.color = props.category.color;
    form.description = props.category.description ?? '';
    form.clearErrors();
    editOpen.value = true;
}
function submitEdit() {
    form.put(workspaceUrl(`/equipment-categories/${props.category.id}`), {
        preserveScroll: true,
        onSuccess: () => { editOpen.value = false; },
    });
}
function confirmDelete() {
    confirm.require({
        group: 'equipment-category-show',
        message: t('equipment_category.confirm_delete', { name: props.category.name }),
        header: t('equipment_category.delete'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('equipment_category.delete'),
        rejectLabel: t('common.cancel'),
        acceptProps: { severity: 'danger' },
        accept: () => router.delete(workspaceUrl(`/equipment-categories/${props.category.id}`)),
    });
}

function activityLabel(a: ActivityRow): string {
    if (a.event === 'created') return t('equipment_category.activity_created');
    if (a.event === 'deleted') return t('equipment_category.activity_deleted');
    return t('equipment_category.activity_updated');
}
function initials(name: string | null): string {
    if (!name) return '';
    const parts = name.trim().split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase();
}
function avatarColor(name: string | null): string {
    let h = 0;
    for (const ch of name ?? 'system') h = (h * 31 + ch.charCodeAt(0)) % 360;
    return `hsl(${h}, 42%, 46%)`;
}
function relTime(iso: string | null): string {
    return relativeTime(iso, locale.value);
}
function absTime(iso: string | null): string {
    return absoluteTime(iso, locale.value);
}
</script>

<template>
    <div>
        <Head :title="category.name" />
        <ConfirmDialog group="equipment-category-show" />

        <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '12px', marginBottom: '14px' }">
            <Link
                :href="workspaceUrl('/equipment-categories')"
                :style="{ fontSize: '11.5px', color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '5px' }"
            >
                <Icon name="chevR" :size="10" :style="{ transform: 'rotate(180deg)' }" />
                {{ t('equipment_category.back_to_list') }}
            </Link>
            <div v-if="can_manage" :style="{ display: 'flex', gap: '6px' }">
                <CmdButton variant="ghost" size="sm" @click="openEdit">
                    <template #icon><Icon name="edit" :size="12" /></template>
                    {{ t('common.edit') }}
                </CmdButton>
                <CmdButton variant="danger" size="sm" @click="confirmDelete">
                    <template #icon><Icon name="trash" :size="12" /></template>
                    {{ t('equipment_category.delete') }}
                </CmdButton>
            </div>
        </div>

        <div :style="{ display: 'flex', alignItems: 'center', gap: '12px', margin: '0 0 16px' }">
            <span :style="{ flexShrink: 0, width: '14px', height: '14px', borderRadius: '4px', background: category.color || 'var(--fg-mute)', boxShadow: '0 0 0 1px var(--border)' }" />
            <h1 :style="{ margin: 0, fontSize: '22px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">{{ category.name }}</h1>
        </div>

        <Tabs v-model="activeTab" :tabs="tabs" />

        <!-- Details tab -->
        <div v-show="activeTab === 'details'">
            <div class="cmd-card" :style="{ padding: '18px 20px', marginBottom: '20px' }">
                <div v-if="category.description">
                    <div class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em', marginBottom: '4px' }">
                        {{ t('equipment_category.description') }}
                    </div>
                    <p :style="{ margin: 0, fontSize: '13px', color: 'var(--fg-dim)', lineHeight: 1.5, whiteSpace: 'pre-wrap' }">{{ category.description }}</p>
                </div>
                <p v-else :style="{ margin: 0, fontSize: '13px', color: 'var(--fg-mute)' }">{{ t('equipment_category.no_description') }}</p>
            </div>

            <!-- The demo relation: count + list + link to the filtered Equipment list. -->
            <div class="cmd-card" :style="{ padding: '18px 20px' }">
                <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' }">
                    <div class="cmd-mono cmd-uc" :style="{ fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em' }">
                        {{ t('equipment_category.equipment_section', { count: equipment.count }) }}
                    </div>
                    <Link
                        v-if="equipment.count"
                        :href="workspaceUrl(`/equipment?category=${category.id}`)"
                        :style="{ fontSize: '11.5px', color: 'var(--accent)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '4px' }"
                    >
                        {{ t('equipment_category.equipment_view_all') }}
                        <Icon name="chevR" :size="10" />
                    </Link>
                </div>
                <p v-if="!equipment.count" :style="{ margin: 0, fontSize: '13px', color: 'var(--fg-mute)' }">{{ t('equipment_category.equipment_empty') }}</p>
                <div v-else :style="{ display: 'flex', flexDirection: 'column' }">
                    <Link
                        v-for="item in equipment.items"
                        :key="item.id"
                        :href="workspaceUrl(`/equipment/${item.id}`)"
                        :style="{ display: 'flex', alignItems: 'center', gap: '8px', padding: '7px 0', fontSize: '13px', color: 'var(--fg)', textDecoration: 'none', borderTop: '1px solid var(--border)' }"
                    >
                        <Icon name="box" :size="12" :style="{ color: 'var(--fg-mute)', flexShrink: 0 }" />
                        <span :style="{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">{{ item.name }}</span>
                    </Link>
                    <Link
                        v-if="equipment.count > equipment.items.length"
                        :href="workspaceUrl(`/equipment?category=${category.id}`)"
                        :style="{ padding: '8px 0 0', fontSize: '11.5px', color: 'var(--fg-dim)', textDecoration: 'none' }"
                    >
                        {{ t('equipment_category.equipment_more', { n: equipment.count - equipment.items.length }) }}
                    </Link>
                </div>
            </div>
        </div>

        <!-- Log tab -->
        <div v-if="logEnabled" v-show="activeTab === 'activity'">
            <div v-if="!activities.length" class="cmd-card" :style="{ padding: '44px 16px', textAlign: 'center' }">
                <i class="pi pi-history" :style="{ fontSize: '24px', color: 'var(--fg-mute)' }" />
                <p :style="{ margin: '12px 0 0', fontSize: '13px', color: 'var(--fg-mute)' }">{{ t('equipment_category.activity_empty') }}</p>
            </div>
            <div v-else class="cmd-card" :style="{ padding: '20px 22px' }">
                <div v-for="(a, idx) in activities" :key="a.id" :style="{ display: 'flex', gap: '13px' }">
                    <div :style="{ display: 'flex', flexDirection: 'column', alignItems: 'center', flexShrink: 0 }">
                        <span
                            :style="{ width: '30px', height: '30px', borderRadius: '50%', background: a.causer ? avatarColor(a.causer.name) : 'var(--panel2)', color: 'var(--accent-fg)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontSize: '11px', fontWeight: 600 }"
                        >
                            <template v-if="a.causer">{{ initials(a.causer.name) }}</template>
                            <Icon v-else name="cog" :size="13" :style="{ color: 'var(--fg-mute)' }" />
                        </span>
                        <span v-if="idx < activities.length - 1" :style="{ flex: 1, width: '2px', background: 'var(--border)', minHeight: '12px', marginTop: '4px' }" />
                    </div>
                    <div :style="{ flex: 1, minWidth: 0, paddingBottom: idx < activities.length - 1 ? '16px' : '0' }">
                        <div :style="{ fontSize: '13px', color: 'var(--fg)', lineHeight: 1.45 }">
                            <strong v-if="a.causer">{{ a.causer.name }}</strong>
                            <strong v-else>{{ t('equipment_category.activity_system') }}</strong>
                            {{ ' ' }}{{ activityLabel(a) }}
                        </div>
                        <div :style="{ fontSize: '11.5px', color: 'var(--fg-mute)', marginTop: '3px' }" :title="absTime(a.created_at)">
                            {{ relTime(a.created_at) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit dialog -->
        <CommandDialog v-model:visible="editOpen" :title="t('equipment_category.edit')" width="480px">
            <form @submit.prevent="submitEdit" :style="{ display: 'flex', flexDirection: 'column', gap: '12px' }">
                <Field v-model="form.name" :label="t('equipment_category.name')" :error="form.errors.name" required autofocus />
                <div>
                    <label class="cmd-mono cmd-uc" :style="{ display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em', fontWeight: 500 }">{{ t('equipment_category.color') }}</label>
                    <ColorPicker v-model="form.color" />
                    <div v-if="form.errors.color" :style="{ color: 'var(--danger)', fontSize: '11px', marginTop: '4px' }">{{ form.errors.color }}</div>
                </div>
                <div>
                    <label class="cmd-mono cmd-uc" :style="{ display: 'block', fontSize: '10px', color: 'var(--fg-mute)', marginBottom: '6px', letterSpacing: '0.06em', fontWeight: 500 }">{{ t('equipment_category.description') }}</label>
                    <textarea
                        v-model="form.description"
                        rows="3"
                        :placeholder="t('equipment_category.description_placeholder')"
                        :style="{ width: '100%', background: 'var(--panel2)', border: `1px solid ${form.errors.description ? 'var(--danger)' : 'var(--border)'}`, borderRadius: '5px', padding: '8px 10px', color: 'var(--fg)', fontSize: '13px', outline: 'none', fontFamily: 'inherit', resize: 'vertical' }"
                    />
                    <div v-if="form.errors.description" :style="{ color: 'var(--danger)', fontSize: '11px', marginTop: '4px' }">{{ form.errors.description }}</div>
                </div>
                <button type="submit" :style="{ display: 'none' }" aria-hidden="true" />
            </form>
            <template #footer>
                <CmdButton variant="ghost" size="sm" @click="editOpen = false">{{ t('common.cancel') }}</CmdButton>
                <CmdButton variant="primary" size="sm" :loading="form.processing" @click="submitEdit">
                    <template #icon><Icon name="disk" :size="12" /></template>
                    {{ t('common.save') }}
                </CmdButton>
            </template>
        </CommandDialog>
    </div>
</template>
