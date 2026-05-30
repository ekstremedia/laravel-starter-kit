<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps<{ message: string }>();
</script>

<template>
    <Head :title="t('maintenance.head_title')" />
    <div class="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-dark-950 text-gray-900 dark:text-gray-100 p-6">
        <div class="max-w-md text-center">
            <i class="pi pi-cog text-5xl text-indigo-500 mb-4 animate-spin" style="animation-duration: 4s"></i>
            <h1 class="text-3xl font-semibold mb-2">{{ t('maintenance.title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ message }}</p>
        </div>
    </div>
</template>
