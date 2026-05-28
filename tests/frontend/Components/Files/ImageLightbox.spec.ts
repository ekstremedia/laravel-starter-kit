import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import ImageLightbox from '@/Components/Files/ImageLightbox.vue';
import type { LightboxItem } from '@/types/lightbox';

function items(): LightboxItem[] {
    return [
        { id: 1, src: '/one.jpg', thumb: '/one-t.jpg', name: 'one.jpg', canHaveTransparency: false },
        { id: 2, src: '/two.jpg', thumb: '/two-t.jpg', name: 'two.jpg', canHaveTransparency: true },
        { id: 3, src: '/three.jpg', thumb: '/three-t.jpg', name: 'three.jpg', canHaveTransparency: false },
    ] as LightboxItem[];
}

describe('ImageLightbox', () => {
    it('renders nothing when modelValue is null', () => {
        const wrapper = mount(ImageLightbox, {
            props: { modelValue: null, items: items() },
            attachTo: document.body,
        });

        expect(document.querySelector('.fixed.inset-0')).toBeNull();
        wrapper.unmount();
    });

    it('renders an overlay when an index is open', () => {
        const wrapper = mount(ImageLightbox, {
            props: { modelValue: 0, items: items() },
            attachTo: document.body,
        });

        expect(document.querySelector('.fixed.inset-0')).not.toBeNull();
        wrapper.unmount();
    });

    it('loads the current image src', () => {
        const wrapper = mount(ImageLightbox, {
            props: { modelValue: 1, items: items() },
            attachTo: document.body,
        });

        const img = document.querySelector('img');
        expect(img?.getAttribute('src')).toBe('/two.jpg');
        wrapper.unmount();
    });

    it('emits update:modelValue(null) when Escape is pressed', async () => {
        const wrapper = mount(ImageLightbox, {
            props: { modelValue: 0, items: items() },
            attachTo: document.body,
        });

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([null]);
        wrapper.unmount();
    });

    it('navigates to the next image with ArrowRight', async () => {
        const wrapper = mount(ImageLightbox, {
            props: { modelValue: 0, items: items() },
            attachTo: document.body,
        });

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }));
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([1]);
        wrapper.unmount();
    });

    it('navigates to the previous image with ArrowLeft', async () => {
        const wrapper = mount(ImageLightbox, {
            props: { modelValue: 1, items: items() },
            attachTo: document.body,
        });

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft' }));
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([0]);
        wrapper.unmount();
    });
});

describe('ImageLightbox — unified media (video/audio/download)', () => {
    const mixed: LightboxItem[] = [
        { id: 1, kind: 'image', src: '/img.jpg', canZoom: true, downloadUrl: '/dl/1', alt: 'pic' },
        { id: 2, kind: 'video', src: '/poster.jpg', videoSrc: '/clip.mp4', poster: '/poster.jpg', canZoom: false, downloadUrl: '/dl/2', alt: 'clip' },
        { id: 3, kind: 'audio', src: '', audioSrc: '/song.mp3', canZoom: false, downloadUrl: '/dl/3', alt: 'song' },
    ];

    it('renders a <video> for video items', () => {
        const wrapper = mount(ImageLightbox, { props: { modelValue: 1, items: mixed }, attachTo: document.body });
        const video = document.querySelector('video');
        expect(video).not.toBeNull();
        expect(video?.getAttribute('src')).toBe('/clip.mp4');
        expect(video?.getAttribute('poster')).toBe('/poster.jpg');
        wrapper.unmount();
    });

    it('renders an <audio> for audio items', () => {
        const wrapper = mount(ImageLightbox, { props: { modelValue: 2, items: mixed }, attachTo: document.body });
        const audio = document.querySelector('audio');
        expect(audio).not.toBeNull();
        expect(audio?.getAttribute('src')).toBe('/song.mp3');
        wrapper.unmount();
    });

    it('exposes a download control and play/stop controls for media', () => {
        const wrapper = mount(ImageLightbox, { props: { modelValue: 1, items: mixed }, attachTo: document.body });
        const labels = Array.from(document.querySelectorAll('button')).map((b) => b.getAttribute('aria-label'));
        expect(labels).toContain('lightbox.download');
        expect(labels).toContain('lightbox.stop');
        expect(labels.some((l) => l === 'lightbox.play' || l === 'lightbox.pause')).toBe(true);
        wrapper.unmount();
    });
});
