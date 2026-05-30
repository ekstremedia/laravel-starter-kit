/// <reference types="vite/client" />
/// <reference types="vite-plugin-pwa/client" />

import type axios from 'axios';
import type Echo from 'laravel-echo';
import type Pusher from 'pusher-js';

declare module '*.vue' {
    import type { DefineComponent } from 'vue';
    const component: DefineComponent<{}, {}, any>;
    export default component;
}

interface ImportMetaEnv {
    readonly VITE_REVERB_APP_KEY?: string;
    readonly VITE_REVERB_HOST?: string;
    readonly VITE_REVERB_PORT?: string;
    readonly VITE_REVERB_SCHEME?: 'http' | 'https';
}

declare global {
    interface Window {
        axios: typeof axios;
        Echo?: Echo<'reverb'>;
        Pusher?: typeof Pusher;
    }
}
