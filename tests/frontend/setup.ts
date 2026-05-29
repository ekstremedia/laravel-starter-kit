import { vi } from 'vitest';
import { config } from '@vue/test-utils';

// Minimal i18n stub so components calling t() don't crash
config.global.mocks = {
    $t: (key: string) => key,
};

// Composition-API useI18n() — return the key as the translation so assertions
// can match on stable strings without loading the real locale bundles.
vi.mock('vue-i18n', () => ({
    useI18n: () => ({
        t: (key: string, params?: Record<string, unknown>) => {
            if (!params) return key;
            return (
                key +
                ' ' +
                Object.entries(params)
                    .map(([k, v]) => `${k}=${String(v)}`)
                    .join(' ')
            );
        },
    }),
}));

// Silence Inertia if imported. Components that rely on page props (usePage)
// get a minimal shape via the mocked return value. Individual specs override
// this when they need richer props.
vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual<any>('@inertiajs/vue3');
    return {
        ...actual,
        usePage: () => ({ props: { auth: { user: null }, user_settings: { locale: 'en', dark_mode: true }, flash: {}, debug: { easy_login_enabled: false }, locale: 'en' } }),
        // `on` returns an unsubscribe fn in Inertia v3 — mirror that so
        // composables that register navigation listeners can clean up.
        router: { post: vi.fn(), delete: vi.fn(), get: vi.fn(), patch: vi.fn(), put: vi.fn(), visit: vi.fn(), on: vi.fn(() => vi.fn()) },
    };
});

// PrimeVue components lean on an app-level config plugin that we don't
// register in unit tests. Stub the ones our components import so mounting
// doesn't explode with "Cannot read properties of undefined (reading 'config')".
//
// vi.hoisted keeps the helper reachable from the vi.mock factories, which
// Vitest hoists above ordinary const declarations during module evaluation.
const passthroughComponent = vi.hoisted(
    () => (name: string, template: string) => ({
        default: { name, inheritAttrs: true, template },
    }),
);
vi.mock('primevue/inputtext', () => ({
    default: {
        name: 'InputText',
        inheritAttrs: true,
        props: {
            value: { type: [String, Number], default: '' },
            modelValue: { type: [String, Number], default: '' },
        },
        template: `<input :value="value ?? modelValue" />`,
    },
}));
vi.mock('primevue/iconfield', () => passthroughComponent('IconField', `<div class="p-iconfield"><slot /></div>`));
vi.mock('primevue/inputicon', () => passthroughComponent('InputIcon', `<span class="p-inputicon"><slot /></span>`));
vi.mock('primevue/confirmdialog', () => passthroughComponent('ConfirmDialog', `<div data-testid="confirm-dialog" />`));
vi.mock('primevue/useconfirm', () => ({
    useConfirm: () => ({ require: vi.fn(), close: vi.fn() }),
}));
vi.mock('primevue/dialog', () => ({
    default: {
        name: 'Dialog',
        props: ['visible', 'header', 'modal', 'style', 'pt'],
        emits: ['update:visible', 'show'],
        template: `
            <div v-if="visible" role="dialog" :aria-label="header">
                <header v-if="header || $slots.header"><slot name="header">{{ header }}</slot></header>
                <div class="dialog-body"><slot /></div>
                <footer v-if="$slots.footer"><slot name="footer" /></footer>
            </div>
        `,
    },
}));
vi.mock('primevue/menu', () => ({
    default: {
        name: 'Menu',
        props: ['model', 'popup'],
        methods: {
            toggle() {
                /* noop for tests */
            },
        },
        template: `
            <ul class="p-menu">
                <li v-for="(item, i) in (model || [])" :key="i" :class="item.class">
                    <template v-if="item.separator"><hr /></template>
                    <a v-else :href="item.url || '#'" @click.prevent="item.command && item.command()">
                        <i v-if="item.icon" :class="item.icon" />
                        <span>{{ item.label }}</span>
                    </a>
                </li>
            </ul>
        `,
    },
}));
vi.mock('primevue/usetoast', () => ({
    useToast: () => ({ add: vi.fn(), remove: vi.fn(), removeAllGroups: vi.fn() }),
}));
