import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { PageProps, ModuleFeatures } from '@/types';

const DISABLED: ModuleFeatures = { enabled: false, files: false, log: false };

/**
 * Read a module's resolved {enabled, files, log} from the shared `modules` prop
 * (workspace override → platform toggle → capability; see ModuleRegistry). Lets
 * a module page conditionally render its Files / Log surfaces without each page
 * re-deriving the lookup. Unknown keys resolve to all-off.
 */
export function useModuleFeatures(key: string) {
    const page = usePage<PageProps>();
    const features = computed<ModuleFeatures>(() => page.props.modules?.[key] ?? DISABLED);

    return {
        features,
        enabled: computed(() => features.value.enabled),
        filesEnabled: computed(() => features.value.files),
        logEnabled: computed(() => features.value.log),
    };
}
