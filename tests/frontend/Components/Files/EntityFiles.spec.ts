import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import { router } from '@inertiajs/vue3';

vi.mock('@/composables/useWorkspace', () => ({
    useWorkspace: () => ({ workspaceUrl: (p: string) => `/w/acme${p}` }),
}));

import EntityFiles from '@/Components/Files/EntityFiles.vue';

const folderUrl = (id: number | null) => (id === null ? '/w/acme/equipment/1' : `/w/acme/equipment/1/folders/${id}`);

const fileRow = (over: Record<string, unknown>) => ({
    id: 0, uuid: 'u', type: 'file', name: 'f', mime_type: null, size: 0, parent_id: null,
    is_image: false, thumbnail_url: null, preview_url: null, original_url: null,
    created_at: null, updated_at: null, ...over,
});

const baseProps = {
    ownerType: 'equipment',
    ownerId: 1,
    files: { data: [
        fileRow({ id: 5, type: 'folder', name: 'Manuals' }),
        fileRow({ id: 6, type: 'file', name: 'spec.pdf', mime_type: 'application/pdf', size: 2048 }),
    ] },
    breadcrumbs: [],
    currentFolder: null,
    usage: { used_bytes: 2048, quota_bytes: null, percent: 0 },
    canManage: true,
    folderUrl,
};

describe('Files/EntityFiles', () => {
    it('renders the documents and the usage bar', () => {
        const w = mount(EntityFiles, { props: baseProps });
        expect(w.text()).toContain('Manuals');
        expect(w.text()).toContain('spec.pdf');
        expect(w.text()).toContain('files.unlimited');
    });

    it('shows upload and new-folder buttons when manageable', () => {
        const w = mount(EntityFiles, { props: baseProps });
        expect(w.text()).toContain('files.upload');
        expect(w.text()).toContain('files.new_folder');
    });

    it('hides management buttons when not manageable', () => {
        const w = mount(EntityFiles, { props: { ...baseProps, canManage: false } });
        expect(w.text()).not.toContain('files.upload');
        expect(w.text()).not.toContain('files.new_folder');
    });

    it('shows the empty state with no documents', () => {
        const w = mount(EntityFiles, { props: { ...baseProps, files: { data: [] } } });
        expect(w.text()).toContain('files.empty_documents');
    });

    it('navigates into a folder on click', async () => {
        const w = mount(EntityFiles, { props: baseProps });
        await w.findAll('.cmd-file-card')[0].trigger('click');
        expect(router.visit).toHaveBeenCalledWith('/w/acme/equipment/1/folders/5');
    });
});
