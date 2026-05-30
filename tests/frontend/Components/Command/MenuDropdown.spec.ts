import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, it, expect, afterEach } from 'vitest';
import MenuDropdown from '@/Components/Command/MenuDropdown.vue';

const options = [
    { value: 1, label: 'Vehicle', color: '#3b82f6' },
    { value: 2, label: 'Machine', color: '#f59e0b' },
];

// The menu panel is teleported to <body> (so it overlays dialogs without being
// clipped), so assertions look there rather than inside the component wrapper.
function menuEl(): HTMLElement | null {
    return document.body.querySelector('[role="menu"]');
}
function menuItems(): HTMLElement[] {
    return [...document.body.querySelectorAll<HTMLElement>('[role="menuitem"]')];
}

let wrapper: VueWrapper | null = null;
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
});

describe('Components/Command/MenuDropdown', () => {
    it('shows the placeholder when nothing is selected', () => {
        wrapper = mount(MenuDropdown, { props: { modelValue: '', options, placeholder: 'All categories' } });
        expect(wrapper.text()).toContain('All categories');
        // The menu is closed until the trigger is clicked.
        expect(menuEl()).toBeNull();
    });

    it('opens the menu and lists the options', async () => {
        wrapper = mount(MenuDropdown, { props: { modelValue: '', options, placeholder: 'All' }, attachTo: document.body });
        await wrapper.find('button[aria-haspopup="menu"]').trigger('click');

        const menu = menuEl();
        expect(menu).not.toBeNull();
        expect(menu?.textContent).toContain('Vehicle');
        expect(menu?.textContent).toContain('Machine');
    });

    it('emits the chosen value and closes', async () => {
        wrapper = mount(MenuDropdown, { props: { modelValue: '', options, placeholder: 'All' }, attachTo: document.body });
        await wrapper.find('button[aria-haspopup="menu"]').trigger('click');

        menuItems()[1].click(); // Machine
        await wrapper.vm.$nextTick();
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([2]);
        expect(menuEl()).toBeNull();
    });

    it('offers an empty row that clears the selection', async () => {
        wrapper = mount(MenuDropdown, { props: { modelValue: 1, options, placeholder: 'All', includeEmpty: true }, attachTo: document.body });
        // The selected option label shows in the trigger.
        expect(wrapper.text()).toContain('Vehicle');

        await wrapper.find('button[aria-haspopup="menu"]').trigger('click');
        menuItems()[0].click(); // empty/clear row
        await wrapper.vm.$nextTick();
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['']);
    });
});
