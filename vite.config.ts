import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';
import { resolve } from 'path';

export default defineConfig(({ mode, isSsrBuild }) => {
    const env = loadEnv(mode, process.cwd(), '');
    let devServerHost = env.VITE_DEV_SERVER_HOST || 'localhost';

    if (! env.VITE_DEV_SERVER_HOST && env.APP_URL) {
        try {
            devServerHost = new URL(env.APP_URL).hostname;
        } catch {
            devServerHost = 'localhost';
        }
    }

    // Vite listens on this port inside the container AND it's the port the
    // browser loads assets/HMR from, so docker-compose publishes it 1:1
    // (VITE_HOST_PORT:VITE_HOST_PORT). Keep them equal — running a second stack
    // just needs a different VITE_HOST_PORT in that project's .env.
    const vitePort = Number(env.VITE_HOST_PORT) || 5173;

    // PWA is opt-in: only wire the plugin when explicitly enabled, so dev/HMR is
    // never disrupted by a service worker caching stale assets.
    const pwaEnabled = env.VITE_PWA_ENABLED === 'true';

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.ts'],
                // Opt-in SSR entry — only built by `npm run build:ssr` (the
                // `vite build --ssr` pass), emitted to bootstrap/ssr.
                ssr: 'resources/js/ssr.ts',
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
            tailwindcss(),
            VitePWA({
                // Always registered so the `virtual:pwa-register` module resolves
                // in every build, but inert unless VITE_PWA_ENABLED=true. Keeps
                // dev/HMR free of a service worker.
                disable: !pwaEnabled,
                // Don't silently swap the SW mid-session in an auth app —
                // prompt, and register manually (guarded) from app.ts.
                registerType: 'prompt',
                injectRegister: false,
                // The SW lives under /build but must control the whole
                // origin (nginx adds Service-Worker-Allowed: / for it).
                scope: '/',
                manifest: {
                            name: env.VITE_APP_NAME || 'Laravel Starter Kit',
                            short_name: env.VITE_APP_NAME || 'Starter',
                            description: 'Laravel + Vue + Inertia starter kit',
                            scope: '/',
                            start_url: '/',
                            display: 'standalone',
                            background_color: '#0b0d10',
                            theme_color: '#0b0d10',
                            icons: [
                                { src: '/icons/pwa-192x192.png', sizes: '192x192', type: 'image/png' },
                                { src: '/icons/pwa-512x512.png', sizes: '512x512', type: 'image/png' },
                                { src: '/icons/maskable-icon-512x512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
                            ],
                        },
                        workbox: {
                            // Precache the built JS/CSS/fonts only. The static
                            // offline shell is added explicitly so the fallback
                            // works without network.
                            globPatterns: ['**/*.{js,css,woff2}'],
                            additionalManifestEntries: [{ url: '/offline.html', revision: 'offline-v1' }],
                            cleanupOutdatedCaches: true,
                            // Serve the offline shell for failed navigations, but
                            // NEVER for auth/admin/api/realtime routes — those just
                            // fail rather than show a cached page.
                            navigateFallback: '/offline.html',
                            navigateFallbackDenylist: [
                                /^\/admin/, /^\/api/, /^\/broadcasting/, /^\/login/,
                                /^\/logout/, /^\/horizon/, /^\/pulse/, /^\/storage/,
                            ],
                            // Cross-origin webfonts are safe to cache; we
                            // deliberately do NOT runtime-cache HTML, JSON, /storage
                            // media, or any POST — no authenticated data is stored.
                            runtimeCaching: [
                                {
                                    urlPattern: ({ url }) => url.origin === 'https://fonts.bunny.net',
                                    handler: 'StaleWhileRevalidate',
                                    options: { cacheName: 'webfonts', expiration: { maxEntries: 20 } },
                                },
                            ],
                        },
                    }),
        ],
        resolve: {
            alias: {
                '@': resolve(__dirname, 'resources/js'),
            },
        },
        build: {
            rollupOptions: {
                output: {
                    // Client only: split the rarely-changing critical-path
                    // vendors into stable chunks so an app-code change doesn't
                    // bust their long-term cache. ApexCharts/leaflet/markdown-it
                    // are deliberately NOT matched — they're loaded via dynamic
                    // import() and get their own chunks automatically; pinning
                    // them would pull them back onto the critical path. (Vite 8 /
                    // rolldown wants manualChunks as a function.) The SSR build
                    // wants a single bundle, so skip chunking there.
                    manualChunks: isSsrBuild
                        ? undefined
                        : (id: string) => {
                            if (!id.includes('node_modules')) return;
                            if (/[\\/]node_modules[\\/]@?vue[\\/]/.test(id)) return 'vendor-vue';
                            if (id.includes('@inertiajs')) return 'vendor-inertia';
                            if (/[\\/]node_modules[\\/]@?primevue[\\/]/.test(id)) return 'vendor-primevue';
                            if (id.includes('vue-i18n') || id.includes('@intlify')) return 'vendor-i18n';
                        },
                },
            },
        },
        server: {
            host: '0.0.0.0',
            port: vitePort,
            // Fail loudly if the port is taken instead of silently drifting to
            // the next one — the published Docker host port is mapped 1:1, so a
            // drifted port would serve assets the browser can't reach.
            strictPort: true,
            hmr: {
                host: devServerHost,
                clientPort: vitePort,
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
                // Docker bind mounts on macOS/Windows don't reliably deliver
                // native filesystem events, so edits (notably to *.ts like the
                // i18n files) can go unseen until Vite restarts. Polling trades
                // a little CPU for dependable HMR across hosts.
                usePolling: true,
                interval: 300,
            },
        },
    };
});
