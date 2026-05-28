import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import FilesUsageBar from '@/Components/Files/FilesUsageBar.vue';

describe('Files/FilesUsageBar', () => {
    it('shows a percentage for a normal quota', () => {
        const w = mount(FilesUsageBar, { props: { usedBytes: 50, quotaBytes: 100 } });
        expect(w.text()).toContain('50.0%');
    });

    it('caps the percentage at 100', () => {
        const w = mount(FilesUsageBar, { props: { usedBytes: 300, quotaBytes: 100 } });
        expect(w.text()).toContain('100.0%');
    });

    it('renders "unlimited" with no percentage when quota is null', () => {
        const w = mount(FilesUsageBar, { props: { usedBytes: 50, quotaBytes: null } });
        expect(w.text()).toContain('files.unlimited');
        expect(w.text()).not.toContain('%');
    });

    it('renders "disabled" when the quota is the 0 sentinel', () => {
        const w = mount(FilesUsageBar, { props: { usedBytes: 0, quotaBytes: 0 } });
        expect(w.text()).toContain('files.disabled');
        expect(w.text()).not.toContain('%');
    });
});
