import { onBeforeUnmount, onMounted, ref } from 'vue';

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

/**
 * Surfaces the browser's "Add to home screen" / install affordance. Captures
 * the deferred `beforeinstallprompt` event so a button can trigger it on demand
 * (Chrome/Edge require a user gesture). `canInstall` stays false where the
 * browser doesn't fire the event (Safari/iOS, already-installed, PWA disabled),
 * so callers can hide the button. Listener is mounted client-side only —
 * SSR-safe.
 */
export function useInstallPrompt() {
    const canInstall = ref(false);
    let deferred: BeforeInstallPromptEvent | null = null;

    function onPrompt(e: Event) {
        e.preventDefault(); // stop Chrome's default mini-infobar; we drive it
        deferred = e as BeforeInstallPromptEvent;
        canInstall.value = true;
    }

    function onInstalled() {
        deferred = null;
        canInstall.value = false;
    }

    async function promptInstall(): Promise<'accepted' | 'dismissed' | 'unavailable'> {
        if (!deferred) {
            return 'unavailable';
        }
        await deferred.prompt();
        const { outcome } = await deferred.userChoice;
        deferred = null;
        canInstall.value = false;
        return outcome;
    }

    onMounted(() => {
        window.addEventListener('beforeinstallprompt', onPrompt);
        window.addEventListener('appinstalled', onInstalled);
    });
    onBeforeUnmount(() => {
        window.removeEventListener('beforeinstallprompt', onPrompt);
        window.removeEventListener('appinstalled', onInstalled);
    });

    return { canInstall, promptInstall };
}
