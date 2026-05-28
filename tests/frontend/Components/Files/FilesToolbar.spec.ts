import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';

vi.mock('@/composables/useCustomer', () => ({
    useCustomer: () => ({
        customerUrl: (p: string) => `/c/acme${p}`,
        customer: { value: { files_feature_enabled: true, company_files_enabled: true } },
    }),
}));

import FilesToolbar from '@/Components/Files/FilesToolbar.vue';

const baseProps = {
    scope: 'private' as const,
    basePath: '/files',
    breadcrumbs: [{ id: 1, name: 'Documents' }],
    rootLabel: 'My files',
    search: '',
    viewMode: 'grid' as const,
    permissions: { upload: true, createFolder: true, canViewShared: true },
};

describe('Files/FilesToolbar', () => {
    it('renders the root label and breadcrumb trail', () => {
        const w = mount(FilesToolbar, { props: baseProps });
        expect(w.text()).toContain('My files');
        expect(w.text()).toContain('Documents');
    });

    it('emits update:search while typing and submitSearch on Enter', async () => {
        const w = mount(FilesToolbar, { props: baseProps });
        const input = w.find('input[type="search"]');
        await input.setValue('report');
        expect(w.emitted('update:search')?.[0]).toEqual(['report']);
        await input.trigger('keyup', { key: 'Enter' });
        expect(w.emitted('submitSearch')).toBeTruthy();
    });

    it('emits update:viewMode from the list toggle', async () => {
        const w = mount(FilesToolbar, { props: baseProps });
        await w.find('button[aria-label="files.view_list"]').trigger('click');
        expect(w.emitted('update:viewMode')?.[0]).toEqual(['list']);
    });

    it('emits upload and newFolder from the action buttons', async () => {
        const w = mount(FilesToolbar, { props: baseProps });
        await w.findAll('button').find((b) => b.text().includes('files.upload'))!.trigger('click');
        expect(w.emitted('upload')).toBeTruthy();
        await w.findAll('button').find((b) => b.text().includes('files.new_folder'))!.trigger('click');
        expect(w.emitted('newFolder')).toBeTruthy();
    });

    it('hides the upload and new-folder buttons without permission', () => {
        const w = mount(FilesToolbar, { props: { ...baseProps, permissions: { upload: false, createFolder: false } } });
        expect(w.text()).not.toContain('files.upload');
        expect(w.text()).not.toContain('files.new_folder');
    });
});
