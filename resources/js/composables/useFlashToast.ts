import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import type { PageProps } from '@/types';

export function useFlashToast() {
    const page = usePage<PageProps>();
    const toast = useToast();

    watch(
        () => ({
            success: page.props.flash?.success,
            error: page.props.flash?.error,
        }),
        (flash) => {
            if (flash.success) {
                toast.add({ severity: 'success', summary: 'Success', detail: flash.success, life: 4000 });
            }
            if (flash.error) {
                toast.add({ severity: 'error', summary: 'Error', detail: flash.error, life: 6000 });
            }
            // NOTE: `flash.status` is intentionally NOT toasted. Fortify sets it to internal
            // keys (e.g. 'profile-information-updated', 'password-updated', 'verification-link-sent')
            // that are not user-facing copy; pages render their own localized feedback
            // (Profile.vue success toast; ForgotPassword/VerifyEmail show it inline). Toasting it
            // raw produced a duplicate "info" toast alongside the page's own success toast.
        },
        { immediate: true },
    );
}
