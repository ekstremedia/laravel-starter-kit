import { onBeforeUnmount, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { PageProps } from '@/types';

export type NotificationPayload = {
    id?: string;
    type?: string;
    title?: string;
    message?: string;
    icon?: string;
    conversation_id?: number;
    [key: string]: unknown;
};

/**
 * Subscribe to the authenticated user's private Echo channel and fire a
 * callback for every incoming notification broadcast. Re-binds when the
 * auth user changes (e.g. login, impersonation).
 */
export function useUserChannel(onNotification: (payload: NotificationPayload) => void) {
    const page = usePage<PageProps>();
    let currentUserId: number | null = null;

    function join(userId: number) {
        leave();
        if (typeof window === 'undefined') return; // SSR: no Echo
        const echo = window.Echo;
        if (!echo) return;
        currentUserId = userId;
        echo.private(`App.Models.User.${userId}`).notification((n: NotificationPayload) => {
            onNotification(n);
        });
    }

    function leave() {
        if (currentUserId !== null) {
            if (typeof window !== 'undefined') {
                window.Echo?.leave(`App.Models.User.${currentUserId}`);
            }
            currentUserId = null;
        }
    }

    watch(
        () => page.props.auth?.user?.id,
        (id) => {
            if (typeof id === 'number') {
                join(id);
            } else {
                leave();
            }
        },
        { immediate: true },
    );

    onBeforeUnmount(() => leave());
}
