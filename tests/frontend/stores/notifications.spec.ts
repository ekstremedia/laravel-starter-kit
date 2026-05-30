import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useNotificationsStore } from '@/stores/notifications';

describe('notifications store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('pushes newest-first and exposes the latest item', () => {
        const s = useNotificationsStore();
        s.push({ id: 'a', title: 'First' });
        s.push({ id: 'b', title: 'Second' });
        expect(s.count).toBe(2);
        expect(s.latest?.id).toBe('b');
        expect(s.recent[1].id).toBe('a');
    });

    it('caps the ring buffer at 20 items', () => {
        const s = useNotificationsStore();
        for (let i = 0; i < 25; i++) {
            s.push({ id: `n${i}` });
        }
        expect(s.count).toBe(20);
        expect(s.latest?.id).toBe('n24');
    });

    it('fills id and received_at when missing', () => {
        const s = useNotificationsStore();
        s.push({ title: 'x' });
        expect(s.latest?.id).toBeTruthy();
        expect(typeof s.latest?.received_at).toBe('number');
    });

    it('clears the feed', () => {
        const s = useNotificationsStore();
        s.push({ id: 'a' });
        s.clear();
        expect(s.count).toBe(0);
    });
});
