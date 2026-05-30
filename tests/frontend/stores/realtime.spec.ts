import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useRealtimeStore } from '@/stores/realtime';

describe('realtime store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        delete (window as unknown as { Echo?: unknown }).Echo;
    });

    it('defaults to disconnected and unbound', () => {
        const s = useRealtimeStore();
        expect(s.status).toBe('disconnected');
        expect(s.bound).toBe(false);
        expect(s.isConnected).toBe(false);
    });

    it('tracks status and clamps the online count', () => {
        const s = useRealtimeStore();
        s.setStatus('connected');
        expect(s.isConnected).toBe(true);
        s.setOnlineCount(-3);
        expect(s.onlineCount).toBe(0);
        s.setOnlineCount(4.9);
        expect(s.onlineCount).toBe(4);
    });

    it('bind() is a no-op when Echo is not configured', () => {
        const s = useRealtimeStore();
        s.bind();
        expect(s.bound).toBe(false);
    });

    it('bind() follows the Echo connection lifecycle', () => {
        const handlers: Record<string, (p: { current: string }) => void> = {};
        (window as unknown as { Echo?: unknown }).Echo = {
            connector: {
                pusher: {
                    connection: {
                        state: 'connecting',
                        bind: (e: string, cb: (p: { current: string }) => void) => {
                            handlers[e] = cb;
                        },
                    },
                },
            },
        };

        const s = useRealtimeStore();
        s.bind();
        expect(s.bound).toBe(true);
        expect(s.status).toBe('connecting');

        handlers.state_change?.({ current: 'connected' });
        expect(s.status).toBe('connected');

        handlers.state_change?.({ current: 'failed' });
        expect(s.status).toBe('disconnected');
    });
});
