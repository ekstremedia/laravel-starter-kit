import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { Customer, PageProps } from '@/types';

/**
 * Customer-aware URL helpers used in Vue components.
 *
 * Works in both modes:
 *   - tenancy disabled → `customerUrl('/dashboard')` returns `/dashboard`
 *   - tenancy enabled, active customer → `customerUrl('/dashboard')` returns `/c/<slug>/dashboard`
 *   - tenancy enabled, no active customer (e.g. the picker page) → returns the raw path too
 *
 * `tenancyEnabled` lets layouts conditionally render customer-only UI (the
 * "Customers" nav entry, the picker, etc.).
 */
export function useCustomer() {
    const page = usePage<PageProps>();

    const customer = computed<Customer | null>(() => page.props.customer ?? null);
    const customers = computed<Customer[]>(() => page.props.available_customers ?? []);
    const tenancyEnabled = computed<boolean>(() => page.props.tenancy?.enabled ?? false);

    function customerUrl(path: string): string {
        const normalized = path.startsWith('/') ? path : `/${path}`;

        // Only prefix with /c/<slug> in multi-tenant mode. When tenancy is off
        // the workspace routes are mounted at the root, so paths stay bare even
        // though an (implicit) workspace is still set on the page props.
        if (tenancyEnabled.value && customer.value) {
            return `/c/${customer.value.slug}${normalized}`;
        }

        return normalized;
    }

    return { customer, customers, tenancyEnabled, customerUrl };
}
