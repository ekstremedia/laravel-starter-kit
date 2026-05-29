import type { MediaFileRow } from '@/composables/useFileMedia';

/**
 * The row shape consumed by the shared <FileBrowser>. It's a superset of both
 * file surfaces: the private (FileItemResource) fields plus the company-only
 * metadata (CompanyFileItemResource) — `linked`, `owner`, `shared_by`,
 * `can_manage`. Everything scope-specific is optional so a private row (which
 * never sets them) type-checks fine.
 *
 * Extends MediaFileRow so it satisfies useFileMedia's preview logic directly.
 */
export interface FileBrowserItem extends MediaFileRow {
    id: number;
    uuid: string;
    type: 'folder' | 'file';
    name: string;
    mime_type: string | null;
    size: number;
    parent_id: number | null;
    image_processing?: boolean;
    created_at: string | null;
    updated_at: string | null;

    // Private: this file is mirrored into the company tree.
    shared_to_company?: boolean;
    company_link_id?: number | null;

    // Company surface metadata.
    scope?: 'personal' | 'company';
    linked?: boolean;
    link_id?: number | null;
    company_parent_id?: number | null;
    owner?: { id: number; name: string; avatar_thumb_url: string | null } | null;
    shared_by?: { id: number; name: string } | null;
    shared_at?: string | null;
    can_manage?: boolean;
}
