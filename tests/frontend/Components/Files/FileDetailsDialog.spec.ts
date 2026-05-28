import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import FileDetailsDialog from '@/Components/Files/FileDetailsDialog.vue';

// Mock Leaflet + its asset imports so the dynamic import() in the component
// resolves without a real map engine.
vi.mock('leaflet', () => {
    const chain = { setView: vi.fn().mockReturnThis(), addTo: vi.fn().mockReturnThis(), remove: vi.fn(), invalidateSize: vi.fn() };
    return {
        map: vi.fn(() => chain),
        tileLayer: vi.fn(() => chain),
        marker: vi.fn(() => chain),
        Icon: { Default: { mergeOptions: vi.fn() } },
    };
});
vi.mock('leaflet/dist/leaflet.css', () => ({}));
vi.mock('leaflet/dist/images/marker-icon.png', () => ({ default: 'icon.png' }));
vi.mock('leaflet/dist/images/marker-icon-2x.png', () => ({ default: 'icon-2x.png' }));
vi.mock('leaflet/dist/images/marker-shadow.png', () => ({ default: 'shadow.png' }));

function mockDetails(metadata: Record<string, unknown> | null) {
    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({
            id: 1,
            name: 'photo.jpg',
            mime_type: 'image/jpeg',
            size: 123456,
            created_at: '2026-01-01T10:00:00Z',
            updated_at: '2026-01-02T10:00:00Z',
            metadata,
        }),
    }) as unknown as typeof fetch;
}

describe('Files/FileDetailsDialog', () => {
    beforeEach(() => {
        document.cookie = 'XSRF-TOKEN=test';
    });
    afterEach(() => {
        document.body.innerHTML = '';
    });

    async function openWith(metadata: Record<string, unknown> | null) {
        mockDetails(metadata);
        const w = mount(FileDetailsDialog, { props: { item: null }, attachTo: document.body });
        await w.setProps({ item: { id: 1, name: 'photo.jpg' } });
        // load() chains several awaits (fetch → nextTick → dynamic leaflet
        // imports); flush enough cycles for the whole chain to settle.
        for (let i = 0; i < 8; i++) await flushPromises();
        return w;
    }

    it('renders camera/EXIF rows and a map when GPS is present', async () => {
        const w = await openWith({
            dimensions: { width: 100, height: 80 },
            camera: { make: 'Sony', model: 'A7' },
            gps: { lat: 60.39, lng: 5.32 },
        });

        expect(document.body.textContent).toContain('Sony A7');
        expect(document.body.querySelector('.cmd-details-map')).not.toBeNull();
        w.unmount();
    });

    it('does not render a map when there is no GPS', async () => {
        const w = await openWith({ dimensions: { width: 100, height: 80 } });

        expect(document.body.querySelector('.cmd-details-map')).toBeNull();
        w.unmount();
    });

    it('shows a no-metadata message when metadata is null', async () => {
        const w = await openWith(null);

        expect(document.body.textContent).toContain('files.details.no_metadata');
        w.unmount();
    });
});
