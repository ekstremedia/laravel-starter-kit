<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CommandDialog from '@/Components/Command/Dialog.vue';
import { humanBytes } from '@/utils/bytes';
import { useWorkspace } from '@/composables/useWorkspace';

interface DetailsFileItem {
    id: number;
    name: string;
    mime_type?: string | null;
    size?: number;
}

interface FileMetadata {
    dimensions?: { width: number; height: number };
    camera?: { make?: string; model?: string };
    lens?: string;
    iso?: number;
    exposure?: string;
    aperture?: number;
    focal_length?: number;
    gps?: { lat: number; lng: number };
    captured_at?: string;
    duration?: number;
    video_codec?: string;
    audio_codec?: string;
    page_count?: number;
    file_type?: string;
}

interface DetailsResponse {
    id: number;
    name: string;
    mime_type: string | null;
    size: number;
    created_at: string;
    updated_at: string;
    metadata: FileMetadata | null;
}

const props = defineProps<{ item: DetailsFileItem | null }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useI18n();
const { workspaceUrl } = useWorkspace();

const loading = ref(false);
const error = ref(false);
const details = ref<DetailsResponse | null>(null);
const mapEl = ref<HTMLElement | null>(null);
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let mapInstance: any = null;

const isOpen = computed(() => props.item !== null);
const meta = computed<FileMetadata | null>(() => details.value?.metadata ?? null);
const gps = computed(() => meta.value?.gps ?? null);

function close() {
    destroyMap();
    emit('close');
}

function destroyMap() {
    if (mapInstance) {
        mapInstance.remove();
        mapInstance = null;
    }
}

async function load(id: number) {
    loading.value = true;
    error.value = false;
    details.value = null;
    try {
        const res = await fetch(workspaceUrl(`/files/${id}/details`), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? ''),
            },
            credentials: 'same-origin',
        });
        if (!res.ok) {
            error.value = true;
            return;
        }
        details.value = await res.json();
        await nextTick();
        // The map is best-effort — render it without blocking (and swallow any
        // Leaflet/tile failure) so the metadata is shown immediately regardless.
        if (gps.value) {
            renderMap(gps.value.lat, gps.value.lng).catch(() => undefined);
        }
    } catch {
        error.value = true;
    } finally {
        loading.value = false;
    }
}

async function renderMap(lat: number, lng: number) {
    destroyMap();
    const L = await import('leaflet');
    await import('leaflet/dist/leaflet.css');
    // Vite serves Leaflet's bundled marker images from these imported URLs;
    // without this the default marker 404s (the well-known Leaflet+Vite bug).
    const [{ default: iconUrl }, { default: iconRetinaUrl }, { default: shadowUrl }] = await Promise.all([
        import('leaflet/dist/images/marker-icon.png'),
        import('leaflet/dist/images/marker-icon-2x.png'),
        import('leaflet/dist/images/marker-shadow.png'),
    ]);
    // Drop Leaflet's path-builder so it uses our explicit (Vite-resolved) URLs
    // verbatim — otherwise it prepends a detected image path and 404s the
    // retina/shadow assets.
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    delete (L.Icon.Default.prototype as any)._getIconUrl;
    L.Icon.Default.mergeOptions({ iconUrl, iconRetinaUrl, shadowUrl });

    if (!mapEl.value) return;
    mapInstance = L.map(mapEl.value, { attributionControl: true, scrollWheelZoom: false }).setView([lat, lng], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(mapInstance);
    L.marker([lat, lng]).addTo(mapInstance);
    // The dialog animates in; invalidate once laid out so tiles fill the box.
    setTimeout(() => mapInstance?.invalidateSize(), 150);
}

watch(
    () => props.item,
    (item) => {
        if (item) load(item.id);
        else destroyMap();
    },
);

// Curated EXIF rows, rendered only when present.
const exifRows = computed(() => {
    const m = meta.value;
    if (!m) return [];
    const rows: { label: string; value: string }[] = [];
    if (m.dimensions) rows.push({ label: t('files.details.dimensions'), value: `${m.dimensions.width} × ${m.dimensions.height}` });
    const camera = [m.camera?.make, m.camera?.model].filter(Boolean).join(' ');
    if (camera) rows.push({ label: t('files.details.camera'), value: camera });
    if (m.lens) rows.push({ label: t('files.details.lens'), value: m.lens });
    if (m.focal_length) rows.push({ label: t('files.details.focal_length'), value: `${m.focal_length} mm` });
    if (m.aperture) rows.push({ label: t('files.details.aperture'), value: `ƒ/${m.aperture}` });
    if (m.exposure) rows.push({ label: t('files.details.exposure'), value: m.exposure });
    if (m.iso) rows.push({ label: t('files.details.iso'), value: `ISO ${m.iso}` });
    if (m.captured_at) rows.push({ label: t('files.details.captured_at'), value: m.captured_at });
    if (m.duration) rows.push({ label: t('files.details.duration'), value: formatDuration(m.duration) });
    if (m.video_codec) rows.push({ label: t('files.details.video_codec'), value: m.video_codec });
    if (m.audio_codec) rows.push({ label: t('files.details.audio_codec'), value: m.audio_codec });
    if (m.page_count) rows.push({ label: t('files.details.pages'), value: String(m.page_count) });
    return rows;
});

const fileRows = computed(() => {
    const d = details.value;
    if (!d) return [];
    const rows: { label: string; value: string }[] = [
        { label: t('files.details.type'), value: d.mime_type || meta.value?.file_type || '—' },
        { label: t('files.details.size'), value: humanBytes(d.size) },
        { label: t('files.details.created'), value: new Date(d.created_at).toLocaleString() },
        { label: t('files.details.modified'), value: new Date(d.updated_at).toLocaleString() },
    ];
    return rows;
});

const hasExif = computed(() => exifRows.value.length > 0);

function formatDuration(seconds: number): string {
    const s = Math.round(seconds);
    const m = Math.floor(s / 60);
    const rem = s % 60;
    return `${m}:${rem.toString().padStart(2, '0')}`;
}
</script>

<template>
    <CommandDialog
        :visible="isOpen"
        :title="item?.name ?? t('files.details.title')"
        width="520px"
        @update:visible="(v: boolean) => { if (!v) close(); }"
    >
        <div v-if="loading" :style="{ display: 'flex', justifyContent: 'center', padding: '32px' }">
            <i class="pi pi-spin pi-spinner" :style="{ fontSize: '22px', color: 'var(--fg-mute)' }" />
        </div>

        <div v-else-if="error" :style="{ padding: '24px', textAlign: 'center', color: 'var(--danger)', fontSize: '12px' }">
            {{ t('files.details.error') }}
        </div>

        <div v-else :style="{ display: 'flex', flexDirection: 'column', gap: '16px' }">
            <!-- File facts -->
            <section>
                <div class="cmd-mono cmd-uc cmd-details-heading">{{ t('files.details.file') }}</div>
                <dl class="cmd-details-grid">
                    <template v-for="row in fileRows" :key="row.label">
                        <dt>{{ row.label }}</dt>
                        <dd>{{ row.value }}</dd>
                    </template>
                </dl>
            </section>

            <!-- EXIF / media metadata -->
            <section v-if="hasExif">
                <div class="cmd-mono cmd-uc cmd-details-heading">{{ t('files.details.metadata') }}</div>
                <dl class="cmd-details-grid">
                    <template v-for="row in exifRows" :key="row.label">
                        <dt>{{ row.label }}</dt>
                        <dd>{{ row.value }}</dd>
                    </template>
                </dl>
            </section>

            <!-- GPS map -->
            <section v-if="gps">
                <div class="cmd-mono cmd-uc cmd-details-heading">{{ t('files.details.location') }}</div>
                <div ref="mapEl" class="cmd-details-map" />
                <div class="cmd-mono" :style="{ marginTop: '6px', fontSize: '11px', color: 'var(--fg-mute)' }">
                    {{ gps.lat }}, {{ gps.lng }}
                </div>
            </section>

            <div v-if="!hasExif && !gps" :style="{ fontSize: '11.5px', color: 'var(--fg-mute)', paddingTop: '2px' }">
                {{ t('files.details.no_metadata') }}
            </div>
        </div>
    </CommandDialog>
</template>

<style scoped>
.cmd-details-heading {
    font-size: 10px;
    color: var(--fg-mute);
    letter-spacing: 0.06em;
    margin-bottom: 8px;
}
.cmd-details-grid {
    display: grid;
    grid-template-columns: 130px 1fr;
    gap: 6px 12px;
    margin: 0;
    font-size: 12.5px;
}
.cmd-details-grid dt {
    color: var(--fg-mute);
}
.cmd-details-grid dd {
    margin: 0;
    color: var(--fg);
    word-break: break-word;
}
.cmd-details-map {
    height: 220px;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid var(--border);
    background: var(--panel2);
}
</style>
