import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import type { PageProps } from '@/types';

/**
 * Surfaces server flash messages (`flash.success` / `flash.error`) as toasts.
 *
 * Watches the flash VALUES directly — not a freshly-built `{ success, error }`
 * object — so a toast fires only when the message actually changes. The old
 * object-literal getter returned a new reference on every evaluation, so it
 * re-fired on any unrelated `page.props` update (e.g. a live partial reload
 * triggered by a WebSocket broadcast), re-showing the still-present flash and
 * producing duplicate toasts.
 *
 * `flash.status` is intentionally NOT toasted: Fortify sets it to internal keys
 * (e.g. 'profile-information-updated', 'verification-link-sent') that aren't
 * user-facing copy; pages render their own localized feedback (Profile.vue;
 * ForgotPassword/VerifyEmail show it inline).
 */
export function useFlashToast() {
    const page = usePage<PageProps>();
    const toast = useToast();

    watch(
        () => page.props.flash?.success,
        (success) => {
            if (success) {
                toast.add({ severity: 'success', summary: 'Success', detail: success, life: 4000 });
            }
        },
        { immediate: true },
    );

    watch(
        () => page.props.flash?.error,
        (error) => {
            if (error) {
                toast.add({ severity: 'error', summary: 'Error', detail: error, life: 6000 });
            }
        },
        { immediate: true },
    );
}
