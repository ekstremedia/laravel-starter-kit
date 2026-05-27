import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'path';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    let devServerHost = env.VITE_DEV_SERVER_HOST || 'localhost';

    if (! env.VITE_DEV_SERVER_HOST && env.APP_URL) {
        try {
            devServerHost = new URL(env.APP_URL).hostname;
        } catch {
            devServerHost = 'localhost';
        }
    }

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.ts'],
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
        ],
        resolve: {
            alias: {
                '@': resolve(__dirname, 'resources/js'),
            },
        },
        server: {
            host: '0.0.0.0',
            port: 5173,
            // Fail loudly if 5173 is taken instead of silently drifting to
            // 5174 — the published Docker host port is fixed at 5173, so a
            // drifted port would serve assets the browser can't reach.
            strictPort: true,
            hmr: {
                host: devServerHost,
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
