import type { IconName } from '@/Components/Command/Icon.vue';

export interface SidebarItem {
    id: string;
    href: string;
    label: string;
    icon: IconName;
    kb?: string;
    match: (path: string) => boolean;
    hideWhen?: () => boolean;
}

export interface SidebarSeparator {
    separator: true;
    key: string;
    // Optional group heading. When the rail is expanded this renders as an
    // uppercase section label (e.g. "Workspace", "Access"); collapsed, it
    // falls back to the plain hairline divider.
    label?: string;
}

export type SidebarEntry = SidebarItem | SidebarSeparator;

export function isSidebarItem(entry: SidebarEntry): entry is SidebarItem {
    return !('separator' in entry);
}
