import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { Workspace, PageProps } from '@/types';

/**
 * Workspace-aware URL helpers used in Vue components.
 *
 * Works in both modes:
 *   - workspaces disabled → `workspaceUrl('/dashboard')` returns `/dashboard`
 *   - workspaces enabled, active workspace → `workspaceUrl('/dashboard')` returns `/w/<slug>/dashboard`
 *   - workspaces enabled, no active workspace (e.g. the picker page) → returns the raw path too
 *
 * `tenancyEnabled` lets layouts conditionally render workspace-only UI (the
 * "Workspaces" nav entry, the picker, etc.).
 */
export function useWorkspace() {
    const page = usePage<PageProps>();

    const workspace = computed<Workspace | null>(() => page.props.workspace ?? null);
    const workspaces = computed<Workspace[]>(() => page.props.available_workspaces ?? []);
    const tenancyEnabled = computed<boolean>(() => page.props.workspaces?.enabled ?? false);

    function workspaceUrl(path: string): string {
        const normalized = path.startsWith('/') ? path : `/${path}`;

        // Only prefix with /w/<slug> in multi-tenant mode. When workspaces is off
        // the workspace routes are mounted at the root, so paths stay bare even
        // though an (implicit) workspace is still set on the page props.
        if (tenancyEnabled.value && workspace.value) {
            return `/w/${workspace.value.slug}${normalized}`;
        }

        return normalized;
    }

    return { workspace, workspaces, tenancyEnabled, workspaceUrl };
}
