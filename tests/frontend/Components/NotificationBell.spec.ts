import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ref } from 'vue';

const notificationsCount = ref(0);
const decrementNotifications = vi.fn((n: number) => {
    notificationsCount.value = Math.max(0, notificationsCount.value - n);
});
const setNotifications = vi.fn((n: number) => {
    notificationsCount.value = n;
});

vi.mock('@/composables/useUnreadCounts', () => ({
    useUnreadCounts: () => ({
        notificationsCount,
        decrementNotifications,
        setNotifications,
    }),
}));

const workspaceUrl = vi.fn((path: string) => path);
vi.mock('@/composables/useWorkspace', () => ({
    useWorkspace: () => ({ workspaceUrl }),
}));

// No real network calls in unit tests.
vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve(new Response(JSON.stringify({ recent: [] }), { status: 200 }))),
);

import NotificationBell from '@/Components/NotificationBell.vue';

describe('NotificationBell', () => {
    beforeEach(() => {
        notificationsCount.value = 0;
        decrementNotifications.mockClear();
        setNotifications.mockClear();
    });

    it('renders the bell trigger button with no badge when there is no unread', () => {
        const wrapper = mount(NotificationBell);

        expect(wrapper.get('button').exists()).toBe(true);
        expect(wrapper.find('button > span').exists()).toBe(false);
    });

    it('renders the unread badge count', () => {
        notificationsCount.value = 3;
        const wrapper = mount(NotificationBell);

        expect(wrapper.get('button > span').text()).toBe('3');
    });

    it('caps the unread badge at "99+"', () => {
        notificationsCount.value = 250;
        const wrapper = mount(NotificationBell);

        expect(wrapper.get('button > span').text()).toBe('99+');
    });

    it('opens the panel when the trigger is clicked', async () => {
        const wrapper = mount(NotificationBell);

        expect(wrapper.find('[aria-expanded="true"]').exists()).toBe(false);
        await wrapper.get('button').trigger('click');
        expect(wrapper.get('button').attributes('aria-expanded')).toBe('true');
    });

    it('exposes a refresh method via defineExpose', () => {
        const wrapper = mount(NotificationBell);
        const vm = wrapper.vm as unknown as { refresh?: () => void };

        expect(typeof vm.refresh).toBe('function');
    });
});
