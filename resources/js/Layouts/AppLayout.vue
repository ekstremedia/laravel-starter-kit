<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { usePage, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Toast from 'primevue/toast';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import DarkModeToggle from '@/Components/DarkModeToggle.vue';
import ChatDropdown from '@/Components/Chat/ChatDropdown.vue';
import WorkspaceSwitcher from '@/Components/Command/WorkspaceSwitcher.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import { useFlashToast } from '@/composables/useFlashToast';
import { useWorkspace } from '@/composables/useWorkspace';
import { useUnreadCounts } from '@/composables/useUnreadCounts';
import { useUserChannel } from '@/composables/useUserChannel';
import type { PageProps } from '@/types';

const { t } = useI18n();
const page = usePage<PageProps>();
const user = computed(() => page.props.auth?.user);
const isAdmin = computed(() => user.value?.is_super_admin === true);
const appName = import.meta.env.VITE_APP_NAME || t('app.name');
const { workspace, tenancyEnabled, workspaceUrl } = useWorkspace();
const dashboardUrl = computed(() => workspaceUrl('/dashboard'));
const profileUrl = computed(() => workspaceUrl('/profile'));
const filesUrl = computed(() => workspaceUrl('/files'));
const filesEnabled = computed(() => {
    const us = (page.props.user_settings ?? {}) as Record<string, unknown>;
    const appSettings = (page.props.app_settings ?? {}) as Record<string, unknown>;
    const tenancyOn = Boolean(page.props.workspaces?.enabled);
    // Show the link only when every gate is green:
    //   global app toggle + per-user setting,
    //   AND (workspaces off | the active workspace has it on).
    return (
        Boolean(appSettings.files_feature_enabled)
        && Boolean(us.files_enabled)
        && (!tenancyOn || Boolean(page.props.workspace?.files_feature_enabled))
    );
});
const notificationSettingsUrl = '/settings/notifications';
const chatEnabled = computed(() => page.props.chat?.enabled ?? false);
const {
    messagesCount: unreadMessagesCount,
    incrementMessages,
    incrementNotifications,
} = useUnreadCounts();
const notificationBellRef = ref<InstanceType<typeof NotificationBell> | null>(null);
// Workspace-scoped nav entries show in the layout when either workspaces is off
// (single-tenant mode, routes at root) or a workspace is actively in scope.
const showWorkspaceNav = computed(() => !tenancyEnabled.value || Boolean(workspace.value));

const dropdownOpen = ref(false);
useFlashToast();

// Listen for server-pushed notifications on the user's private channel.
// Chat messages only touch the message badge; everything else bumps the bell.
useUserChannel((n) => {
    const isChat = typeof n.type === 'string' && n.type.endsWith('NewChatMessageNotification');
    if (isChat) {
        incrementMessages(1);
        return;
    }
    incrementNotifications(1);
    notificationBellRef.value?.refresh();
});

const isImpersonating = computed(() => user.value?.is_impersonating ?? false);
const announcement = computed(() => (page.props as any).app_settings?.announcement as { text: string; severity: string } | null);
const registrationOpen = computed(() => page.props.app_settings?.registration_open !== false);
const announcementClass: Record<string, string> = {
    info: 'bg-sky-500/90',
    warn: 'bg-amber-500/90',
    danger: 'bg-rose-500/90',
    success: 'bg-emerald-500/90',
};

function logout() {
    router.post('/logout');
}

function leaveImpersonation() {
    router.post('/impersonate/leave');
}

function initials(u: { first_name: string; last_name: string }) {
    const first = (u.first_name?.trim() ?? '')[0] ?? '';
    const last = (u.last_name?.trim() ?? '')[0] ?? '';
    return (first + last).toUpperCase();
}
</script>

<template>
    <Toast position="top-right" />
    <div class="min-h-screen bg-gray-50 dark:bg-dark-950 text-gray-900 dark:text-gray-100 transition-colors">
        <!-- Announcement banner -->
        <div v-if="announcement"
             :class="[announcementClass[announcement.severity] ?? 'bg-sky-500/90', 'text-white px-4 py-2 text-sm text-center']">
            <i class="pi pi-megaphone mr-2"></i>{{ announcement.text }}
        </div>

        <!-- Impersonation banner -->
        <div v-if="isImpersonating" class="bg-amber-500/90 text-white px-4 py-2 text-sm flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div class="truncate"><i class="pi pi-user-edit mr-2"></i>{{ t('impersonation.banner') }} <strong>{{ user?.email }}</strong>.</div>
            <button @click="leaveImpersonation" class="self-start sm:self-auto px-3 py-1 rounded bg-white/20 hover:bg-white/30 text-sm cursor-pointer">
                <i class="pi pi-sign-out mr-1"></i>{{ t('impersonation.stop') }}
            </button>
        </div>
        <!-- Navigation -->
        <nav class="border-b border-gray-200 dark:border-dark-800 bg-white dark:bg-dark-900 transition-colors">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16 items-center gap-2">
                    <!-- Left: Logo + nav links -->
                    <div class="flex items-center gap-4 md:gap-8 min-w-0">
                        <Link href="/" class="text-base sm:text-xl font-bold text-indigo-600 dark:text-indigo-400 transition-colors hover:text-indigo-500 truncate">
                            {{ appName }}
                        </Link>

                        <template v-if="user">
                            <div class="hidden sm:flex items-center gap-1">
                                <Link
                                    v-if="showWorkspaceNav"
                                    :href="dashboardUrl"
                                    class="px-3 py-2 text-sm font-medium rounded-lg transition-colors"
                                    :class="$page.url.startsWith(dashboardUrl)
                                        ? 'text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/10'
                                        : 'text-gray-600 dark:text-dark-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-dark-800'"
                                >
                                    {{ t('nav.dashboard') }}
                                </Link>
                                <Link
                                    v-if="filesEnabled"
                                    :href="filesUrl"
                                    class="px-3 py-2 text-sm font-medium rounded-lg transition-colors"
                                    :class="$page.url.startsWith(filesUrl)
                                        ? 'text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/10'
                                        : 'text-gray-600 dark:text-dark-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-dark-800'"
                                >
                                    {{ t('files.title') }}
                                </Link>
                                <Link
                                    v-if="isAdmin"
                                    href="/admin"
                                    class="px-3 py-2 text-sm font-medium rounded-lg transition-colors"
                                    :class="$page.url.startsWith('/admin')
                                        ? 'text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/10'
                                        : 'text-gray-600 dark:text-dark-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-dark-800'"
                                >
                                    {{ t('nav.admin') }}
                                </Link>
                            </div>
                        </template>
                    </div>

                    <!-- Right side -->
                    <div class="flex items-center gap-1 sm:gap-3">
                        <template v-if="user"><WorkspaceSwitcher /></template>
                        <div class="hidden sm:block"><LanguageSwitcher /></div>
                        <DarkModeToggle />

                        <!-- Notification bell -->
                        <template v-if="user">
                            <NotificationBell ref="notificationBellRef" />
                        </template>

                        <!-- Chat messages -->
                        <template v-if="user && chatEnabled">
                            <ChatDropdown :unread-count="unreadMessagesCount" :current-user-id="user.id" />
                        </template>

                        <!-- User dropdown -->
                        <template v-if="user">
                            <div class="relative">
                                <button
                                    @click="dropdownOpen = !dropdownOpen"
                                    class="flex items-center gap-2 cursor-pointer rounded-lg px-2 py-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-dark-800"
                                >
                                    <img
                                        v-if="user.avatar_thumb_url"
                                        :src="user.avatar_thumb_url"
                                        :alt="user.full_name"
                                        class="w-8 h-8 rounded-full object-cover"
                                    />
                                    <div
                                        v-else
                                        class="w-8 h-8 rounded-full bg-indigo-600 text-white flex items-center justify-center text-xs font-semibold"
                                    >
                                        {{ initials(user) }}
                                    </div>
                                    <span class="text-sm text-gray-700 dark:text-gray-300 hidden sm:inline">
                                        {{ user.full_name }}
                                    </span>
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>

                                <!-- Dropdown menu -->
                                <Transition
                                    enter-active-class="transition ease-out duration-100"
                                    enter-from-class="opacity-0 scale-95"
                                    enter-to-class="opacity-100 scale-100"
                                    leave-active-class="transition ease-in duration-75"
                                    leave-from-class="opacity-100 scale-100"
                                    leave-to-class="opacity-0 scale-95"
                                >
                                    <div
                                        v-if="dropdownOpen"
                                        @click="dropdownOpen = false"
                                        class="absolute right-0 mt-2 w-48 rounded-xl bg-white dark:bg-dark-900 border border-gray-200 dark:border-dark-700 shadow-lg dark:shadow-dark-950/50 py-1 z-50"
                                    >
                                        <Link
                                            v-if="showWorkspaceNav"
                                            :href="dashboardUrl"
                                            class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-dark-800 sm:hidden"
                                        >
                                            {{ t('nav.dashboard') }}
                                        </Link>
                                        <Link
                                            v-if="filesEnabled"
                                            :href="filesUrl"
                                            class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-dark-800 sm:hidden"
                                        >
                                            {{ t('files.title') }}
                                        </Link>
                                        <Link
                                            :href="profileUrl"
                                            class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-dark-800"
                                        >
                                            {{ t('nav.profile') }}
                                        </Link>
                                        <Link
                                            :href="notificationSettingsUrl"
                                            class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-dark-800"
                                        >
                                            <i class="pi pi-cog mr-2 text-xs"></i>{{ t('notifications.settings.title') }}
                                        </Link>
                                        <Link
                                            v-if="isAdmin"
                                            href="/admin"
                                            class="block px-4 py-2 text-sm text-indigo-600 dark:text-indigo-400 hover:bg-gray-100 dark:hover:bg-dark-800"
                                        >
                                            <i class="pi pi-shield mr-2 text-xs"></i>{{ t('nav.admin') }}
                                        </Link>
                                        <div class="sm:hidden border-t border-gray-100 dark:border-dark-700 my-1"></div>
                                        <div class="sm:hidden px-4 py-2" @click.stop><LanguageSwitcher /></div>
                                        <div class="border-t border-gray-100 dark:border-dark-700 my-1"></div>
                                        <button
                                            @click="logout"
                                            class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-dark-800 cursor-pointer"
                                        >
                                            {{ t('nav.logout') }}
                                        </button>
                                    </div>
                                </Transition>
                            </div>

                            <!-- Click-outside backdrop -->
                            <div v-if="dropdownOpen" @click="dropdownOpen = false" class="fixed inset-0 z-40"></div>
                        </template>

                        <!-- Guest links -->
                        <template v-else>
                            <Link
                                href="/login"
                                class="text-sm text-gray-600 dark:text-dark-400 hover:text-gray-900 dark:hover:text-white transition-colors px-2"
                            >
                                {{ t('nav.login') }}
                            </Link>
                            <Link
                                v-if="registrationOpen"
                                href="/register"
                                class="text-sm px-3 sm:px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition-colors whitespace-nowrap"
                            >
                                {{ t('nav.register') }}
                            </Link>
                        </template>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Page content -->
        <main>
            <slot />
        </main>
    </div>
</template>
