import './bootstrap';
import '../css/app.css';
import 'primeicons/primeicons.css';

import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';
import piniaPluginPersistedstate from 'pinia-plugin-persistedstate';
import PrimeVue from 'primevue/config';
import ToastService from 'primevue/toastservice';
import ConfirmationService from 'primevue/confirmationservice';
import Aura from '@primevue/themes/aura';
import { i18n } from '@/i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel Starter Kit';

createInertiaApp({
    title: (title) => title ? `${title} - ${appName}` : appName,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const pinia = createPinia();
        // localStorage-backed persistence for any store that opts in with
        // `persist: true`. Client-only — registered here but not in ssr.ts.
        pinia.use(piniaPluginPersistedstate);

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
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
            })
            .mount(el);
    },
    progress: {
        color: '#6366f1',
    },
});
