import { defineStore } from 'pinia';

/**
 * Live-connection state for the app's WebSocket (Reverb/Echo) link. Pinia owns
 * this because nothing else does — it's genuinely-new shared state that drives
 * the topbar "live" indicator and lets any component reason about whether
 * real-time updates are currently flowing. Holds only primitives, so it is
 * safe to instantiate under SSR; the Echo binding happens client-side only.
 */
export type ConnectionStatus = 'connecting' | 'connected' | 'disconnected';

// The Reverb client is pusher-protocol, so its connection exposes the pusher-js
// state machine. Typed loosely to avoid depending on pusher-js internals.
interface PusherConnection {
    state: string;
    bind(event: string, cb: (payload: { current: string; previous?: string }) => void): void;
}

function mapState(state: string): ConnectionStatus {
    if (state === 'connected') return 'connected';
    if (state === 'connecting' || state === 'initialized' || state === 'unavailable') return 'connecting';
    return 'disconnected';
}

export const useRealtimeStore = defineStore('realtime', {
    state: () => ({
        status: 'disconnected' as ConnectionStatus,
        onlineCount: 0,
        // True once bind() has attached to a real Echo connection — lets the UI
        // hide the indicator entirely when WebSockets aren't configured.
        bound: false,
    }),
    getters: {
        isConnected: (s): boolean => s.status === 'connected',
    },
    actions: {
        setStatus(status: ConnectionStatus): void {
            this.status = status;
        },
        setOnlineCount(n: number): void {
            this.onlineCount = Math.max(0, Math.floor(n));
        },
        /**
         * Attach to the live Echo (Reverb) connection lifecycle. No-op when Echo
         * isn't configured or under SSR, and idempotent. Call once from the
         * authenticated layout's onMounted.
         */
        bind(): void {
            if (this.bound || typeof window === 'undefined') {
                return;
            }

            const echo = (window as unknown as {
                Echo?: { connector?: { pusher?: { connection?: PusherConnection } } };
            }).Echo;
            const conn = echo?.connector?.pusher?.connection;
            if (!conn) {
                return;
            }

            this.bound = true;
            this.setStatus(mapState(conn.state));
            conn.bind('state_change', ({ current }) => this.setStatus(mapState(current)));
        },
    },
});
