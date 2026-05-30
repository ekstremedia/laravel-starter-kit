import { ref, watch } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import type { PageProps, UserSettings, UserSettingValue } from '@/types';

const STORAGE_KEY = import.meta.env.VITE_APP_STORAGE_KEY || 'starter_kit_settings';

// Default settings mirror UserSetting::$defaults on the backend
const defaults: UserSettings = {
    locale: 'en',
    dark_mode: true,
    notification_email_immediate: false,
    notification_digest: 'none',
    notification_chat_messages: true,
    notification_account_updates: true,
    notification_system_alerts: true,
};

// Module-level reactive state — shared across all composable calls
const settings = ref<UserSettings>({ ...defaults });

let dbSyncTimer: ReturnType<typeof setTimeout> | null = null;
let pendingPatch: Record<string, UserSettingValue> = {};
let initialized = false;

function sanitizePartial(partial: Partial<UserSettings>): Record<string, UserSettingValue> {
    return Object.fromEntries(
        Object.entries(partial).filter((entry): entry is [string, UserSettingValue] => entry[1] !== undefined),
    );
}

// Merge-write so we don't clobber keys owned by other composables
// (useTweaks writes theme/accent/density/show_kbd_hints/rail_expanded into
// the same localStorage key).
function mergeWrite(next: Record<string, unknown>) {
    // No localStorage under SSR (and it may be blocked in the browser).
    if (typeof localStorage === 'undefined') {
        return;
    }
    try {
        const existing = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ ...existing, ...next }));
    } catch {
        // localStorage blocked or corrupt — best-effort write
        localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    }
}

export function useSettings() {
    const page = usePage<PageProps>();

    function syncFromServer(serverSettings?: UserSettings) {
        settings.value = { ...defaults, ...serverSettings };
        mergeWrite(settings.value);
    }

    if (!initialized) {
        initialized = true;

        // 1. Load from localStorage immediately (fast, works for guests too).
        //    Skipped under SSR where localStorage is undefined.
        const stored = typeof localStorage !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null;
        if (stored) {
            try {
                settings.value = { ...defaults, ...JSON.parse(stored) };
            } catch {
                // Corrupt localStorage — fall back to defaults
            }
        }

        // Authenticated server settings are synced below via an immediate watch.
    }

    watch(
        () => [page.props.auth?.user?.id, page.props.user_settings] as const,
        ([userId, serverSettings]) => {
            if (userId && serverSettings) {
                syncFromServer(serverSettings);
            }
        },
        { deep: true, immediate: true },
    );

    /**
     * Update one or more settings.
     * Writes to localStorage immediately, then debounces a PATCH to the API.
     */
    function update(partial: Partial<UserSettings>) {
        const sanitizedPartial = sanitizePartial(partial);

        settings.value = { ...settings.value, ...sanitizedPartial };
        mergeWrite(settings.value);

        // Only sync to DB if the user is authenticated
        if (page.props.auth?.user) {
            Object.assign(pendingPatch, sanitizedPartial);
            if (dbSyncTimer) clearTimeout(dbSyncTimer);
            dbSyncTimer = setTimeout(() => {
                const payload = { ...pendingPatch };
                pendingPatch = {};
                router.patch('/settings', payload, {
                    preserveState: true,
                    preserveScroll: true,
                    onError: () => {
                        Object.assign(pendingPatch, payload);
                    },
                });
            }, 600);
        }
    }

    return { settings, update };
}
