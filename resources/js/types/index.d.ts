import type { PageProps as InertiaPageProps } from '@inertiajs/core';

export type UserSettingValue = string | number | boolean | null;

export interface User {
    id: number;
    public_id: string;
    first_name: string;
    last_name: string;
    email: string;
    email_verified_at?: string;
    two_factor_enabled?: boolean;
    created_at?: string;
    full_name: string;
    avatar_url?: string | null;
    avatar_thumb_url?: string | null;
    headline?: string | null;
    bio?: string | null;
    location?: string | null;
    website?: string | null;
    roles?: string[];
    permissions?: string[];
    is_super_admin?: boolean;
    unread_notifications_count?: number;
    unread_messages_count?: number;
    is_impersonating?: boolean;
}

export type NotificationDigestFrequency = 'none' | 'daily' | 'weekly';

export type CommandTheme = 'dark' | 'hc' | 'light';
export type CommandAccent = 'cobalt' | 'emerald' | 'amber' | 'violet';
export type CommandDensity = 'compact' | 'comfortable' | 'relaxed';

export interface UserSettings {
    locale: string;
    dark_mode: boolean;
    notification_email_immediate: boolean;
    notification_digest: NotificationDigestFrequency;
    notification_chat_messages: boolean;
    notification_account_updates: boolean;
    notification_system_alerts: boolean;
    // Command-design tweaks (client-only; not synced to server).
    theme?: CommandTheme;
    accent?: CommandAccent;
    density?: CommandDensity;
    show_kbd_hints?: boolean;
    rail_expanded?: boolean;
    [key: string]: UserSettingValue;
}

export interface Customer {
    id: number;
    slug: string;
    name: string;
    headline?: string | null;
    about?: string | null;
    location?: string | null;
    website?: string | null;
    files_feature_enabled?: boolean;
    company_files_enabled?: boolean;
}

/**
 * The workspace the left rail is scoped to. Resolved on every route (unlike
 * `customer`, which is null on central routes), and carries the user's
 * workspace-scoped capabilities so the rail's permission-gated entries render
 * the same on /home as inside the workspace. Null when the user belongs to no
 * active workspace (or tenancy is disabled).
 */
export interface CurrentCustomer {
    id: number;
    slug: string;
    name: string;
    files_feature_enabled?: boolean;
    company_files_enabled?: boolean;
    is_admin: boolean;
    can_view_company_files: boolean;
}

export interface PageProps extends InertiaPageProps {
    request_id?: string;
    auth: {
        user?: User;
        can?: {
            manage_email_templates?: boolean;
        };
    };
    debug: {
        easy_login_enabled: boolean;
    };
    locale: string;
    user_settings: UserSettings;
    flash: {
        success?: string;
        error?: string;
        status?: string;
    };
    tenancy: {
        enabled: boolean;
    };
    chat: {
        enabled: boolean;
    };
    assetsEnabled?: boolean;
    oauth?: {
        providers: Array<{ name: string; label: string }>;
    };
    app_settings?: {
        registration_open?: boolean;
        login_enabled?: boolean;
        files_feature_enabled?: boolean;
        announcement?: { text: string; severity: string } | null;
    };
    customer: Customer | null;
    current_customer: CurrentCustomer | null;
    available_customers: Customer[];
}
