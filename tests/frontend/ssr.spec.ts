// @vitest-environment node
import { describe, it, expect, vi } from 'vitest';

// The global test setup mocks vue-i18n (omitting createI18n); restore the real
// module here so @/i18n can build the SSR i18n instance.
vi.mock('vue-i18n', async () => await vi.importActual('vue-i18n'));

// Capture the render callback instead of letting Inertia's createServer start a
// real HTTP listener during the test.
const captured = vi.hoisted(() => ({ fn: undefined as undefined | ((page: unknown) => unknown) }));
vi.mock('@inertiajs/vue3/server', () => ({
    default: (fn: (page: unknown) => unknown) => {
        captured.fn = fn;
    },
}));

describe('ssr entry', () => {
    it('loads in a Node environment (no window/localStorage) without throwing', async () => {
        // Proves there is no top-level browser-global access in ssr.ts or the
        // modules it imports at evaluation time.
        expect(typeof window).toBe('undefined');
        expect(typeof localStorage).toBe('undefined');

        await expect(import('@/ssr')).resolves.toBeDefined();

        // The SSR entry wired a render server callback.
        expect(captured.fn).toBeTypeOf('function');
    });
});
