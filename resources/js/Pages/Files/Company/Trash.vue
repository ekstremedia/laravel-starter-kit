<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CommandLayout from '@/Layouts/CommandLayout.vue';
import Icon from '@/Components/Command/Icon.vue';
import ConfirmDialog from 'primevue/confirmdialog';
import { useConfirm } from 'primevue/useconfirm';
import { useCommandToasts } from '@/composables/useCommandToasts';
import { useWorkspace } from '@/composables/useWorkspace';
import { humanBytes } from '@/utils/bytes';

defineOptions({ layout: CommandLayout });

interface TrashItem {
    id: number;
    type: 'folder' | 'file';
    name: string;
    size: number;
    mime_type: string | null;
    thumbnail_url: string | null;
    is_image: boolean;
    is_video?: boolean;
    owner: { id: number; name: string; avatar_thumb_url: string | null } | null;
    can_manage: boolean;
}

const props = defineProps<{
    items: TrashItem[];
    retention_days: number;
    can_manage: boolean;
}>();

const { t } = useI18n();
const { workspaceUrl } = useWorkspace();
const { push } = useCommandToasts();
const confirmer = useConfirm();

function restore(item: TrashItem) {
    // Server flashes files.restored; useFlashToast surfaces it. Pushing
    // again here would stack two toasts for the same action.
    router.post(workspaceUrl(`/files/company/trash/${item.id}/restore`), {}, {
        preserveScroll: true,
    });
}

function forceDelete(item: TrashItem) {
    if (!props.can_manage) return;
    confirmer.require({
        group: 'company-trash',
        message: t('files.confirm_delete_forever', { name: item.name }),
        header: t('files.delete_forever'),
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        acceptLabel: t('files.delete_forever'),
        rejectLabel: t('common.cancel'),
        accept: () => {
            router.delete(workspaceUrl(`/files/company/trash/${item.id}`), {
                preserveScroll: true,
                // Success toast comes from the server flash.
                onError: (errors) => {
                    const first = Object.values(errors)[0];
                    push(typeof first === 'string' ? first : t('common.error'), 'danger');
                },
            });
        },
    });
}

// humanBytes lives in @/utils/bytes — imported at the top.

// Match Files/Company/Index.vue's mapping — Command icon set has no
// folder/file glyphs, so we lean on `disk`/`log` and let thumbnails
// carry the item-specific visual when they exist.
function iconFor(item: TrashItem): 'disk' | 'log' {
    return item.type === 'folder' ? 'disk' : 'log';
}
</script>

<template>
    <div>
        <Head :title="t('files.trash')" />
        <ConfirmDialog group="company-trash" />

        <div :style="{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: '16px' }">
            <div>
                <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, color: 'var(--fg)' }">
                    {{ t('files.company_title') }} — {{ t('files.trash') }}
                </h1>
                <div :style="{ fontSize: '11.5px', color: 'var(--fg-mute)', marginTop: '4px' }">
                    {{ t('files.trash_desc', { days: retention_days }) }}
                </div>
            </div>
            <Link
                :href="workspaceUrl('/files/company')"
                :style="{ fontSize: '11.5px', color: 'var(--fg-dim)', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '5px' }"
            >
                <Icon name="chevR" :size="10" :style="{ transform: 'rotate(180deg)' }" />
                {{ t('common.back') }}
            </Link>
        </div>

        <div v-if="items.length === 0" :style="{ padding: '40px 20px', textAlign: 'center', color: 'var(--fg-mute)', background: 'var(--panel)', border: '1px dashed var(--border)', borderRadius: '6px' }">
            {{ t('files.trash_empty') }}
        </div>

        <ul v-else :style="{ listStyle: 'none', padding: 0, margin: 0, display: 'grid', gap: '6px' }">
            <li
                v-for="item in items"
                :key="item.id"
                :style="{ display: 'flex', alignItems: 'center', gap: '12px', padding: '10px 12px', background: 'var(--panel)', border: '1px solid var(--border)', borderRadius: '6px' }"
            >
                <div :style="{ flexShrink: 0, width: '32px', height: '32px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--panel2)', borderRadius: '4px' }">
                    <img v-if="item.thumbnail_url" :src="item.thumbnail_url" :alt="item.name" :style="{ maxWidth: '100%', maxHeight: '100%', borderRadius: '3px' }" />
                    <Icon v-else :name="iconFor(item)" :size="16" :style="{ color: 'var(--fg-dim)' }" />
                </div>
                <div :style="{ flex: 1, minWidth: 0 }">
                    <div :style="{ fontSize: '13px', fontWeight: 500, color: 'var(--fg)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">
                        {{ item.name }}
                    </div>
                    <div :style="{ fontSize: '11px', color: 'var(--fg-dim)', display: 'flex', gap: '8px', marginTop: '2px' }">
                        <span v-if="item.type !== 'folder'">{{ humanBytes(item.size) }}</span>
                        <span v-if="item.owner">{{ t('files.owner_by', { name: item.owner.name }) }}</span>
                    </div>
                </div>
                <div :style="{ display: 'flex', gap: '4px', flexShrink: 0 }">
                    <button
                        @click="restore(item)"
                        :title="t('files.restore')"
                        :style="{ background: 'transparent', border: 'none', color: 'var(--fg-mute)', cursor: 'pointer', padding: '6px', borderRadius: '3px' }"
                    >
                        <Icon name="arrow" :size="12" :style="{ transform: 'rotate(180deg)' }" />
                    </button>
                    <button
                        v-if="can_manage"
                        @click="forceDelete(item)"
                        :title="t('files.delete_forever')"
                        :style="{ background: 'transparent', border: 'none', color: 'var(--danger)', cursor: 'pointer', padding: '6px', borderRadius: '3px' }"
                    >
                        <Icon name="trash" :size="12" />
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>
