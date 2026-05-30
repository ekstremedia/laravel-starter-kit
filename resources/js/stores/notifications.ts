import { defineStore } from 'pinia';

/**
 * A small in-memory feed of the most recent notifications pushed over the
 * user's private Echo channel. The unread *counts* still live in
 * useUnreadCounts (which is wired into Inertia page props); this store owns the
 * genuinely-new bit — the recent *items* — so a dropdown or toast can show
 * "what just arrived" without re-fetching. Capped to a ring buffer so it never
 * grows unbounded. SSR-safe (state is plain data; nothing touches the DOM).
 */
export interface RecentNotification {
    id: string;
    type?: string;
    title?: string;
    message?: string;
    icon?: string;
    received_at: number;
}

const MAX_RECENT = 20;

export const useNotificationsStore = defineStore('notifications', {
    state: () => ({
        recent: [] as RecentNotification[],
    }),
    getters: {
        latest: (s): RecentNotification | null => s.recent[0] ?? null,
        count: (s): number => s.recent.length,
    },
    actions: {
        push(n: Partial<RecentNotification>): void {
            const received_at = n.received_at ?? Date.now();
            this.recent.unshift({
                id: n.id ?? `n-${received_at}`,
                type: n.type,
                title: n.title,
                message: n.message,
                icon: n.icon,
                received_at,
            });
            if (this.recent.length > MAX_RECENT) {
                this.recent.length = MAX_RECENT;
            }
        },
        clear(): void {
            this.recent = [];
        },
    },
});
