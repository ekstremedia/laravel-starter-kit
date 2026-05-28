import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import { nextTick } from 'vue';
import Dialog from '@/Components/Command/Dialog.vue';

// Render the teleported content in place so queries hit the wrapper.
const mountDialog = (props = {}, slots = {}) =>
    mount(Dialog, {
        props: { visible: true, title: 'Confirm', ...props },
        slots,
        global: { stubs: { teleport: true } },
    });

describe('Command/Dialog', () => {
    it('renders a labelled modal dialog with its title when visible', () => {
        const w = mountDialog();
        const dialog = w.find('[role="dialog"]');
        expect(dialog.exists()).toBe(true);
        expect(dialog.attributes('aria-modal')).toBe('true');
        expect(w.find('h2').text()).toBe('Confirm');
    });

    it('renders nothing when not visible', () => {
        expect(mountDialog({ visible: false }).find('[role="dialog"]').exists()).toBe(false);
    });

    it('emits update:visible=false and close from the close button', async () => {
        const w = mountDialog();
        await w.find('button.cmd-dialog-close').trigger('click');
        expect(w.emitted('update:visible')?.[0]).toEqual([false]);
        expect(w.emitted('close')).toBeTruthy();
    });

    it('closes on backdrop click by default', async () => {
        const w = mountDialog();
        await w.find('[role="dialog"]').trigger('click');
        expect(w.emitted('update:visible')?.[0]).toEqual([false]);
    });

    it('does not close on backdrop click when closeOnBackdrop is false', async () => {
        const w = mountDialog({ closeOnBackdrop: false });
        await w.find('[role="dialog"]').trigger('click');
        expect(w.emitted('update:visible')).toBeUndefined();
    });

    it('closes on the Escape key', async () => {
        const w = mountDialog();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();
        expect(w.emitted('update:visible')?.[0]).toEqual([false]);
    });

    it('renders the header and footer slots', () => {
        const w = mountDialog({}, {
            header: '<div data-testid="h">Head</div>',
            footer: '<div data-testid="f">Foot</div>',
        });
        expect(w.find('[data-testid="h"]').exists()).toBe(true);
        expect(w.find('[data-testid="f"]').exists()).toBe(true);
    });

    it('hides the close button when showClose is false', () => {
        expect(mountDialog({ showClose: false }).find('button.cmd-dialog-close').exists()).toBe(false);
    });
});
