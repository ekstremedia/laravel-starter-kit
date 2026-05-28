import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import BulkActionBar from '@/Components/Files/BulkActionBar.vue';

describe('Files/BulkActionBar', () => {
    it('is hidden when nothing is selected', () => {
        const w = mount(BulkActionBar, { props: { count: 0 } });
        expect(w.find('.cmd-bulk-bar').exists()).toBe(false);
    });

    it('shows the selected count when items are selected', () => {
        const w = mount(BulkActionBar, { props: { count: 3 } });
        expect(w.text()).toContain('files.bulk.selected count=3');
    });

    it('emits download/move/delete/clear', async () => {
        const w = mount(BulkActionBar, { props: { count: 2, canDelete: true, canMove: true } });
        const buttons = w.findAll('button');
        // download, move, delete, clear
        await buttons[0].trigger('click');
        await buttons[1].trigger('click');
        await buttons[2].trigger('click');
        await buttons[3].trigger('click');
        expect(w.emitted('download')).toHaveLength(1);
        expect(w.emitted('move')).toHaveLength(1);
        expect(w.emitted('delete')).toHaveLength(1);
        expect(w.emitted('clear')).toHaveLength(1);
    });

    it('hides move and delete when not permitted', () => {
        const w = mount(BulkActionBar, { props: { count: 1, canDelete: false, canMove: false } });
        // Only download + clear remain.
        expect(w.findAll('button')).toHaveLength(2);
    });
});
