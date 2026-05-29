<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/CommandLayout.vue';
import Icon from '@/Components/Command/Icon.vue';
import type { Workspace } from '@/types';

const { t } = useI18n();

defineProps<{ workspaces: Workspace[] }>();
</script>

<template>
    <Head :title="t('picker.title')" />
    <AppLayout>
        <div :style="{ maxWidth: '780px', margin: '0 auto', padding: '32px 16px' }">
            <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">
                {{ t('picker.title') }}
            </h1>
            <p
                class="cmd-mono"
                :style="{ marginTop: '4px', fontSize: '11.5px', color: 'var(--fg-mute)', marginBottom: '20px' }"
            >{{ t('picker.subtitle') }}</p>

            <div
                v-if="workspaces.length === 0"
                :style="{
                    padding: '48px 20px',
                    textAlign: 'center',
                    background: 'var(--panel)',
                    border: '1px dashed var(--border)',
                    borderRadius: 'var(--radius-card)',
                }"
            >
                <div :style="{ display: 'flex', justifyContent: 'center', marginBottom: '12px', color: 'var(--fg-mute)' }">
                    <Icon name="workspace" :size="24" />
                </div>
                <p :style="{ fontSize: '12.5px', color: 'var(--fg-dim)' }">{{ t('picker.empty') }}</p>
            </div>

            <ul
                v-else
                :style="{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))',
                    gap: '1px',
                    background: 'var(--border)',
                    border: '1px solid var(--border)',
                    borderRadius: 'var(--radius-card)',
                    overflow: 'hidden',
                    listStyle: 'none',
                    padding: 0,
                    margin: 0,
                }"
            >
                <li v-for="c in workspaces" :key="c.id">
                    <Link
                        :href="`/w/${c.slug}/dashboard`"
                        class="cmd-picker-link"
                        :style="{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '12px',
                            padding: '14px 16px',
                            background: 'var(--panel)',
                            textDecoration: 'none',
                            transition: 'background 0.12s',
                        }"
                    >
                        <div
                            :style="{
                                width: '36px',
                                height: '36px',
                                borderRadius: '5px',
                                background: 'var(--accent)',
                                color: '#fff',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                fontSize: '12px',
                                fontWeight: 700,
                                fontFamily: 'var(--font-mono)',
                                flexShrink: 0,
                            }"
                        >{{ c.name.slice(0, 2).toUpperCase() }}</div>
                        <div :style="{ minWidth: 0 }">
                            <div :style="{ fontSize: '13px', fontWeight: 500, color: 'var(--fg)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }">{{ c.name }}</div>
                            <div class="cmd-mono" :style="{ fontSize: '11px', color: 'var(--fg-dim)' }">/w/{{ c.slug }}</div>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>

<style scoped>
.cmd-picker-link:hover { background: var(--panel2) !important; }
</style>
