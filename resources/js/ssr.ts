import { createSSRApp, h, type DefineComponent } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';
import PrimeVue from 'primevue/config';
import ToastService from 'primevue/toastservice';
import ConfirmationService from 'primevue/confirmationservice';
import Aura from '@primevue/themes/aura';
import { i18n } from '@/i18n';

/*
 * Server-side rendering entry. Mirrors app.ts's plugin registration so SSR and
 * client hydration produce identical trees — EXCEPT:
 *   - it never imports ./bootstrap (which touches window and boots Echo/Pusher);
 *   - Pinia is registered WITHOUT the persistedstate plugin (localStorage is
 *     client-only);
 *   - no CSS imports (the client bundle owns styling; PrimeVue/Aura inject into
 *     <head> on the client, outside the hydrated app root, so no mismatch).
 *
 * SSR is opt-in: build with `npm run build:ssr` and enable INERTIA_SSR_ENABLED.
 */
const appName = import.meta.env.VITE_APP_NAME || 'Laravel Starter Kit';

createServer((page) =>
    createInertiaApp({
        page,
        render: renderToString,
        title: (title) => (title ? `${title} - ${appName}` : appName),
        resolve: (name) =>
            resolvePageComponent(
                `./Pages/${name}.vue`,
                import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
            ),
        setup({ App, props, plugin }) {
            return createSSRApp({ render: () => h(App, props) })
                .use(plugin)
                .use(createPinia())
                .use(i18n)
                .use(ToastService)
                .use(ConfirmationService)
                .use(PrimeVue, {
                    theme: {
                        preset: Aura,
                        options: {
                            darkModeSelector: '.dark',
                        },
                    },
                });
        },
    }),
);
