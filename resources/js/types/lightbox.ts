export type LightboxKind = 'image' | 'video' | 'audio';

export interface LightboxItem {
    id: string | number;
    /** Kind of media. Defaults to 'image' when omitted (back-compat). */
    kind?: LightboxKind;
    src: string;
    zoomSrc?: string;
    originalSrc?: string;
    zoomResolution?: string;
    originalResolution?: string;
    srcset?: string;
    alt?: string;
    canZoom?: boolean;
    canHaveTransparency?: boolean;
    // Video/audio playback sources.
    videoSrc?: string;
    audioSrc?: string;
    poster?: string;
    // Download target for the toolbar download button.
    downloadUrl?: string;
    mime?: string;
    [key: string]: unknown;
}
