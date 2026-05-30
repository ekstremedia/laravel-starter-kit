import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import MenuDropdown from '@/Components/Command/MenuDropdown.vue';

const options = [
    { value: 1, label: 'Vehicle', color: '#3b82f6' },
    { value: 2, label: 'Machine', color: '#f59e0b' },
];

describe('Components/Command/MenuDropdown', () => {
    it('shows the placeholder when nothing is selected', () => {
        const w = mount(MenuDropdown, { props: { modelValue: '', options, placeholder: 'All categories' } });
        expect(w.text()).toContain('All categories');
        // The menu is closed until the trigger is clicked.
        expect(w.find('[role="menu"]').exists()).toBe(false);
    });

    it('opens the menu and lists the options', async () => {
        const w = mount(MenuDropdown, { props: { modelValue: '', options, placeholder: 'All' } });
        await w.find('button[aria-haspopup="menu"]').trigger('click');

        const menu = w.find('[role="menu"]');
        expect(menu.exists()).toBe(true);
        expect(menu.text()).toContain('Vehicle');
        expect(menu.text()).toContain('Machine');
    });

    it('emits the chosen value and closes', async () => {
        const w = mount(MenuDropdown, { props: { modelValue: '', options, placeholder: 'All' } });
        await w.find('button[aria-haspopup="menu"]').trigger('click');

        const items = w.findAll('[role="menuitem"]');
        await items[1].trigger('click'); // Machine
        expect(w.emitted('update:modelValue')?.[0]).toEqual([2]);
        expect(w.find('[role="menu"]').exists()).toBe(false);
    });

    it('offers an empty row that clears the selection', async () => {
        const w = mount(MenuDropdown, { props: { modelValue: 1, options, placeholder: 'All', includeEmpty: true } });
        // The selected option label shows in the trigger.
        expect(w.text()).toContain('Vehicle');

        await w.find('button[aria-haspopup="menu"]').trigger('click');
        // First menuitem is the empty/clear row.
        await w.findAll('[role="menuitem"]')[0].trigger('click');
        expect(w.emitted('update:modelValue')?.[0]).toEqual(['']);
    });
});
