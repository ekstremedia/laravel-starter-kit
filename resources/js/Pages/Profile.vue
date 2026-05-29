<script setup lang="ts">
import { Head, usePage, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/Layouts/CommandLayout.vue';
import Field from '@/Components/Command/Field.vue';
import Icon from '@/Components/Command/Icon.vue';
import Dot from '@/Components/Command/Dot.vue';
import { useWorkspace } from '@/composables/useWorkspace';
import type { PageProps } from '@/types';

const { t } = useI18n();
const toast = useToast();
const page = usePage<PageProps>();
const user = computed(() => page.props.auth.user!);
const { workspaceUrl } = useWorkspace();
const avatarUrl = computed(() => workspaceUrl('/profile/avatar'));

// --- Avatar ---
const avatarInput = ref<HTMLInputElement | null>(null);
const avatarUploading = ref(false);
const avatarPreview = computed(() => user.value.avatar_url);
const avatarInitials = computed(() => {
    const f = (user.value.first_name?.trim() ?? '')[0] ?? '';
    const l = (user.value.last_name?.trim() ?? '')[0] ?? '';
    return (f + l).toUpperCase();
});

function pickAvatar() {
    avatarInput.value?.click();
}

function onAvatarChange(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const form = new FormData();
    form.append('avatar', file);
    avatarUploading.value = true;
    // Flash messages from the controller drive the toast; only surface
    // client-side validation errors here.
    router.post(avatarUrl.value, form, {
        preserveScroll: true,
        forceFormData: true,
        onError: (errors) => {
            const first = Object.values(errors)[0] as string;
            if (first) toast.add({ severity: 'error', detail: first, life: 4000 });
        },
        onFinish: () => {
            avatarUploading.value = false;
            if (avatarInput.value) avatarInput.value.value = '';
        },
    });
}

function removeAvatar() {
    router.delete(avatarUrl.value, { preserveScroll: true });
}

// --- Profile Info ---
const profileForm = useForm({
    first_name: user.value.first_name,
    last_name: user.value.last_name,
    email: user.value.email,
    headline: user.value.headline ?? '',
    bio: user.value.bio ?? '',
    location: user.value.location ?? '',
    website: user.value.website ?? '',
});

function saveProfile() {
    profileForm.put('/user/profile-information', {
        preserveScroll: true,
        onSuccess: () => toast.add({ severity: 'success', detail: t('profile.saved'), life: 3000 }),
    });
}

const profilePublicHref = computed(() => `/u/${user.value.public_id}`);

// --- Password ---
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function updatePassword() {
    passwordForm.put('/user/password', {
        preserveScroll: true,
        onSuccess: () => {
            passwordForm.reset();
            toast.add({ severity: 'success', detail: t('profile.saved'), life: 3000 });
        },
    });
}

// --- Two-Factor Auth ---
const twoFactorEnabled = computed(() => !!user.value.two_factor_enabled);
const enabling = ref(false);
const confirming = ref(false);
const qrCode = ref('');
const recoveryCodes = ref<string[]>([]);
const confirmCode = ref('');
const confirmError = ref('');
const disabling = ref(false);
const showRecovery = ref(false);

// Password confirmation state
const showPasswordConfirm = ref(false);
const confirmPasswordInput = ref('');
const confirmPasswordError = ref('');
let pendingAction: (() => Promise<void>) | null = null;

async function confirmPassword(password: string): Promise<boolean> {
    try {
        const res = await window.fetch('/user/confirm-password', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content
                    ?? getToken(),
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            body: JSON.stringify({ password }),
        });
        return res.ok;
    } catch {
        return false;
    }
}

async function requirePasswordConfirm(action: () => Promise<void>) {
    pendingAction = action;
    confirmPasswordInput.value = '';
    confirmPasswordError.value = '';
    showPasswordConfirm.value = true;
}

async function submitPasswordConfirm() {
    confirmPasswordError.value = '';
    const ok = await confirmPassword(confirmPasswordInput.value);
    if (!ok) {
        confirmPasswordError.value = t('profile.confirm_password_error');
        return;
    }
    showPasswordConfirm.value = false;
    confirmPasswordInput.value = '';
    if (pendingAction) await pendingAction();
    pendingAction = null;
}

function cancelPasswordConfirm() {
    showPasswordConfirm.value = false;
    confirmPasswordInput.value = '';
    confirmPasswordError.value = '';
    pendingAction = null;
}

async function enableTwoFactor() {
    await requirePasswordConfirm(async () => {
        enabling.value = true;
        try {
            await fetch('/user/two-factor-authentication', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': getToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
            });
            const qrRes = await fetch('/user/two-factor-qr-code', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            });
            const qrData = await qrRes.json();
            qrCode.value = qrData.svg;
            confirming.value = true;
        } catch {
            toast.add({ severity: 'error', detail: t('profile.failed_enable_2fa'), life: 4000 });
        } finally {
            enabling.value = false;
        }
    });
}

async function confirmTwoFactor() {
    confirmError.value = '';
    try {
        const res = await fetch('/user/confirmed-two-factor-authentication', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getToken(),
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            body: JSON.stringify({ code: confirmCode.value }),
        });
        if (!res.ok) {
            confirmError.value = t('profile.invalid_2fa_code');
            return;
        }
        confirming.value = false;
        confirmCode.value = '';
        await fetchRecoveryCodes();
        showRecovery.value = true;
        router.reload({ only: ['auth'] });
    } catch {
        confirmError.value = t('profile.something_went_wrong');
    }
}

async function fetchRecoveryCodes() {
    const res = await fetch('/user/two-factor-recovery-codes', {
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    });
    recoveryCodes.value = await res.json();
}

async function regenerateCodes() {
    await requirePasswordConfirm(async () => {
        await fetch('/user/two-factor-recovery-codes', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': getToken(),
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
        });
        await fetchRecoveryCodes();
        toast.add({ severity: 'success', detail: t('profile.recovery_codes_regenerated'), life: 3000 });
    });
}

async function disableTwoFactor() {
    await requirePasswordConfirm(async () => {
        disabling.value = true;
        try {
            await fetch('/user/two-factor-authentication', {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': getToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
            });
            showRecovery.value = false;
            recoveryCodes.value = [];
            router.reload({ only: ['auth'] });
            toast.add({ severity: 'info', detail: t('profile.two_factor_disabled_toast'), life: 3000 });
        } catch {
            toast.add({ severity: 'error', detail: t('profile.failed_disable_2fa'), life: 4000 });
        } finally {
            disabling.value = false;
        }
    });
}

async function showExistingRecoveryCodes() {
    await fetchRecoveryCodes();
    showRecovery.value = !showRecovery.value;
}

function getToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}
</script>

<template>
    <Head :title="t('profile.title')" />

    <AppLayout>
        <section :style="{ maxWidth: '780px', margin: '0 auto', padding: '32px 16px', display: 'flex', flexDirection: 'column', gap: '20px' }">
            <header>
                <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">
                    {{ t('profile.title') }}
                </h1>
            </header>

            <!-- Profile Photo -->
            <div class="cmd-card" :style="{ padding: '20px', display: 'flex', flexDirection: 'column', gap: '16px' }">
                <div>
                    <h2 :style="{ margin: 0, fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">{{ t('profile.photo_title') }}</h2>
                    <p :style="{ fontSize: '11px', color: 'var(--fg-mute)', margin: '3px 0 0' }">{{ t('profile.photo_desc') }}</p>
                </div>

                <div :style="{ display: 'flex', alignItems: 'center', gap: '20px' }">
                    <div :style="{ position: 'relative', width: '72px', height: '72px' }">
                        <img
                            v-if="avatarPreview"
                            :src="avatarPreview"
                            :alt="user.full_name"
                            :style="{ width: '72px', height: '72px', borderRadius: '50%', objectFit: 'cover', border: '1px solid var(--border)' }"
                        />
                        <div
                            v-else
                            class="cmd-mono"
                            :style="{ width: '72px', height: '72px', borderRadius: '50%', background: 'var(--accent)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '22px', fontWeight: 700 }"
                        >{{ avatarInitials }}</div>
                        <div
                            v-if="avatarUploading"
                            :style="{ position: 'absolute', inset: 0, borderRadius: '50%', background: 'rgba(0,0,0,0.45)', display: 'flex', alignItems: 'center', justifyContent: 'center' }"
                        >
                            <i class="pi pi-spin pi-spinner" :style="{ color: '#fff', fontSize: '18px' }"></i>
                        </div>
                    </div>

                    <div :style="{ display: 'flex', flexDirection: 'column', gap: '6px' }">
                        <input
                            ref="avatarInput"
                            type="file"
                            accept="image/jpeg,image/png,image/webp,image/gif"
                            :style="{ display: 'none' }"
                            @change="onAvatarChange"
                        />
                        <button
                            type="button"
                            :disabled="avatarUploading"
                            @click="pickAvatar"
                            :style="{
                                background: 'var(--accent)',
                                color: '#fff',
                                border: 'none',
                                padding: '6px 12px',
                                borderRadius: '5px',
                                fontSize: '12px',
                                fontWeight: 500,
                                cursor: avatarUploading ? 'not-allowed' : 'pointer',
                                opacity: avatarUploading ? 0.55 : 1,
                                fontFamily: 'inherit',
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '6px',
                            }"
                        >
                            <Icon name="plus" :size="11" />
                            {{ avatarPreview ? t('profile.photo_replace') : t('profile.photo_upload') }}
                        </button>
                        <button
                            v-if="avatarPreview"
                            type="button"
                            :disabled="avatarUploading"
                            @click="removeAvatar"
                            :style="{
                                background: 'transparent',
                                border: '1px solid var(--border)',
                                color: 'var(--danger)',
                                padding: '6px 12px',
                                borderRadius: '5px',
                                fontSize: '12px',
                                cursor: avatarUploading ? 'not-allowed' : 'pointer',
                                fontFamily: 'inherit',
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: '6px',
                            }"
                        >
                            <Icon name="trash" :size="11" />
                            {{ t('profile.photo_remove') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Profile Information -->
            <form @submit.prevent="saveProfile" class="cmd-card" :style="{ padding: '20px', display: 'flex', flexDirection: 'column', gap: '14px' }">
                <div>
                    <h2 :style="{ margin: 0, fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">{{ t('profile.info_title') }}</h2>
                    <p :style="{ fontSize: '11px', color: 'var(--fg-mute)', margin: '3px 0 0' }">{{ t('profile.info_desc') }}</p>
                </div>
                <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px' }">
                    <Field
                        v-model="profileForm.first_name"
                        :label="t('auth.first_name')"
                        :error="profileForm.errors.first_name"
                        autocomplete="given-name"
                    />
                    <Field
                        v-model="profileForm.last_name"
                        :label="t('auth.last_name')"
                        :error="profileForm.errors.last_name"
                        autocomplete="family-name"
                    />
                </div>
                <Field
                    v-model="profileForm.email"
                    type="email"
                    :label="t('auth.email')"
                    :error="profileForm.errors.email"
                    autocomplete="email"
                />

                <div :style="{ height: '1px', background: 'var(--border)', margin: '2px 0' }" />

                <div>
                    <h3 :style="{ margin: 0, fontSize: '12px', fontWeight: 600, color: 'var(--fg)' }">{{ t('profile.public_title') }}</h3>
                    <p :style="{ fontSize: '11px', color: 'var(--fg-mute)', margin: '3px 0 0' }">{{ t('profile.public_desc') }}</p>
                    <a
                        :href="profilePublicHref"
                        target="_blank"
                        rel="noopener"
                        :style="{ display: 'inline-flex', alignItems: 'center', gap: '4px', fontSize: '11px', color: 'var(--accent)', marginTop: '6px' }"
                    >
                        <Icon name="user" :size="11" />
                        <span>{{ t('profile.public_view') }}</span>
                    </a>
                </div>

                <Field
                    v-model="profileForm.headline"
                    :label="t('profile.headline_label')"
                    :placeholder="t('profile.headline_placeholder')"
                    :error="profileForm.errors.headline"
                />

                <div>
                    <label :style="{ display: 'block', fontSize: '11px', color: 'var(--fg-dim)', marginBottom: '4px', fontWeight: 500 }">
                        {{ t('profile.bio_label') }}
                    </label>
                    <textarea
                        v-model="profileForm.bio"
                        rows="4"
                        :maxlength="2000"
                        :placeholder="t('profile.bio_placeholder')"
                        :style="{
                            width: '100%',
                            background: 'var(--panel2)',
                            border: '1px solid var(--border)',
                            borderRadius: '5px',
                            padding: '8px 10px',
                            fontSize: '12.5px',
                            fontFamily: 'inherit',
                            color: 'var(--fg)',
                            resize: 'vertical',
                            minHeight: '80px',
                        }"
                    />
                    <div v-if="profileForm.errors.bio" :style="{ color: 'var(--danger)', fontSize: '11px', marginTop: '3px' }">{{ profileForm.errors.bio }}</div>
                </div>

                <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px' }">
                    <Field
                        v-model="profileForm.location"
                        :label="t('profile.location_label')"
                        :placeholder="t('profile.location_placeholder')"
                        :error="profileForm.errors.location"
                    />
                    <Field
                        v-model="profileForm.website"
                        type="url"
                        :label="t('profile.website_label')"
                        placeholder="https://example.com"
                        :error="profileForm.errors.website"
                    />
                </div>

                <div :style="{ display: 'flex', justifyContent: 'flex-end' }">
                    <button
                        type="submit"
                        :disabled="profileForm.processing"
                        :style="{
                            background: 'var(--accent)',
                            color: '#fff',
                            border: 'none',
                            padding: '7px 14px',
                            borderRadius: '5px',
                            fontSize: '12px',
                            fontWeight: 500,
                            cursor: profileForm.processing ? 'not-allowed' : 'pointer',
                            opacity: profileForm.processing ? 0.6 : 1,
                            fontFamily: 'inherit',
                        }"
                    >{{ t('profile.save') }}</button>
                </div>
            </form>

            <!-- Password -->
            <form @submit.prevent="updatePassword" class="cmd-card" :style="{ padding: '20px', display: 'flex', flexDirection: 'column', gap: '14px' }">
                <div>
                    <h2 :style="{ margin: 0, fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">{{ t('profile.password_title') }}</h2>
                    <p :style="{ fontSize: '11px', color: 'var(--fg-mute)', margin: '3px 0 0' }">{{ t('profile.password_desc') }}</p>
                </div>
                <Field
                    v-model="passwordForm.current_password"
                    type="password"
                    :label="t('profile.current_password')"
                    :error="passwordForm.errors.current_password"
                    autocomplete="current-password"
                />
                <Field
                    v-model="passwordForm.password"
                    type="password"
                    :label="t('profile.new_password')"
                    :error="passwordForm.errors.password"
                    autocomplete="new-password"
                />
                <Field
                    v-model="passwordForm.password_confirmation"
                    type="password"
                    :label="t('profile.confirm_password')"
                    :error="passwordForm.errors.password_confirmation"
                    autocomplete="new-password"
                />
                <div :style="{ display: 'flex', justifyContent: 'flex-end' }">
                    <button
                        type="submit"
                        :disabled="passwordForm.processing"
                        :style="{
                            background: 'var(--accent)',
                            color: '#fff',
                            border: 'none',
                            padding: '7px 14px',
                            borderRadius: '5px',
                            fontSize: '12px',
                            fontWeight: 500,
                            cursor: passwordForm.processing ? 'not-allowed' : 'pointer',
                            opacity: passwordForm.processing ? 0.6 : 1,
                            fontFamily: 'inherit',
                        }"
                    >{{ t('profile.update_password') }}</button>
                </div>
            </form>

            <!-- Two-Factor Authentication -->
            <div class="cmd-card" :style="{ padding: '20px', display: 'flex', flexDirection: 'column', gap: '14px' }">
                <div>
                    <h2 :style="{ margin: 0, fontSize: '13px', fontWeight: 600, color: 'var(--fg)' }">{{ t('profile.two_factor_title') }}</h2>
                    <p :style="{ fontSize: '11px', color: 'var(--fg-mute)', margin: '3px 0 0' }">{{ t('profile.two_factor_desc') }}</p>
                </div>

                <div :style="{ display: 'flex', alignItems: 'center', gap: '8px', fontSize: '11.5px' }">
                    <Dot :color="twoFactorEnabled ? 'var(--success)' : 'var(--fg-mute)'" :size="6" />
                    <span :style="{ color: twoFactorEnabled ? 'var(--success)' : 'var(--fg-dim)', fontWeight: 500 }">
                        {{ twoFactorEnabled ? t('profile.two_factor_enabled') : t('profile.two_factor_not_enabled') }}
                    </span>
                </div>

                <!-- Inline password confirmation -->
                <div
                    v-if="showPasswordConfirm"
                    :style="{ padding: '14px', borderRadius: '6px', background: 'var(--panel2)', border: '1px solid var(--border)', display: 'flex', flexDirection: 'column', gap: '10px' }"
                >
                    <div>
                        <div :style="{ fontSize: '12.5px', fontWeight: 500, color: 'var(--fg)' }">{{ t('auth.confirm_password_title') }}</div>
                        <p :style="{ fontSize: '11px', color: 'var(--fg-mute)', margin: '3px 0 0' }">{{ t('auth.confirm_password_subtitle') }}</p>
                    </div>
                    <Field
                        v-model="confirmPasswordInput"
                        type="password"
                        :label="t('auth.password')"
                        :error="confirmPasswordError"
                        autocomplete="current-password"
                        autofocus
                    />
                    <div :style="{ display: 'flex', gap: '6px' }">
                        <button
                            type="button"
                            @click="submitPasswordConfirm"
                            :style="{ background: 'var(--accent)', color: '#fff', border: 'none', padding: '6px 12px', borderRadius: '5px', fontSize: '12px', fontWeight: 500, cursor: 'pointer', fontFamily: 'inherit' }"
                        >{{ t('auth.confirm_password_submit') }}</button>
                        <button
                            type="button"
                            @click="cancelPasswordConfirm"
                            :style="{ background: 'transparent', border: '1px solid var(--border)', color: 'var(--fg-dim)', padding: '6px 12px', borderRadius: '5px', fontSize: '12px', cursor: 'pointer', fontFamily: 'inherit' }"
                        >{{ t('common.cancel') }}</button>
                    </div>
                </div>

                <!-- Enable flow -->
                <div v-if="!twoFactorEnabled && !confirming && !showPasswordConfirm">
                    <button
                        type="button"
                        @click="enableTwoFactor"
                        :disabled="enabling"
                        :style="{
                            background: 'var(--accent)',
                            color: '#fff',
                            border: 'none',
                            padding: '7px 14px',
                            borderRadius: '5px',
                            fontSize: '12px',
                            fontWeight: 500,
                            cursor: enabling ? 'not-allowed' : 'pointer',
                            opacity: enabling ? 0.6 : 1,
                            fontFamily: 'inherit',
                        }"
                    >{{ t('profile.two_factor_enable') }}</button>
                </div>

                <!-- QR Code + Confirm -->
                <div v-if="confirming" :style="{ display: 'flex', flexDirection: 'column', gap: '12px' }">
                    <p :style="{ fontSize: '12px', color: 'var(--fg-dim)', margin: 0 }">{{ t('profile.two_factor_qr_instructions') }}</p>
                    <div :style="{ display: 'flex', justifyContent: 'center', padding: '14px', background: '#fff', borderRadius: '6px', border: '1px solid var(--border)' }" v-html="qrCode"></div>
                    <Field
                        v-model="confirmCode"
                        :label="t('profile.two_factor_enter_code')"
                        :error="confirmError"
                        autocomplete="one-time-code"
                        inputmode="numeric"
                    />
                    <button
                        type="button"
                        @click="confirmTwoFactor"
                        :style="{ alignSelf: 'flex-start', background: 'var(--accent)', color: '#fff', border: 'none', padding: '7px 14px', borderRadius: '5px', fontSize: '12px', fontWeight: 500, cursor: 'pointer', fontFamily: 'inherit' }"
                    >{{ t('profile.two_factor_confirm') }}</button>
                </div>

                <!-- Enabled: recovery codes + disable -->
                <div v-if="twoFactorEnabled && !confirming" :style="{ display: 'flex', flexDirection: 'column', gap: '12px' }">
                    <div v-if="showRecovery && recoveryCodes.length" :style="{ display: 'flex', flexDirection: 'column', gap: '10px' }">
                        <div>
                            <h3 :style="{ margin: 0, fontSize: '12.5px', fontWeight: 600, color: 'var(--fg)' }">{{ t('profile.two_factor_recovery_title') }}</h3>
                            <p :style="{ fontSize: '11px', color: 'var(--fg-mute)', margin: '3px 0 0' }">{{ t('profile.two_factor_recovery_desc') }}</p>
                        </div>
                        <div
                            class="cmd-mono"
                            :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '6px', padding: '12px', borderRadius: '5px', background: 'var(--panel2)', border: '1px solid var(--border)', fontSize: '11.5px', color: 'var(--fg)' }"
                        >
                            <span v-for="code in recoveryCodes" :key="code">{{ code }}</span>
                        </div>
                        <button
                            type="button"
                            @click="regenerateCodes"
                            :style="{ alignSelf: 'flex-start', background: 'transparent', border: 'none', padding: 0, color: 'var(--accent)', fontSize: '11.5px', cursor: 'pointer', fontFamily: 'inherit' }"
                        >{{ t('profile.two_factor_regenerate') }}</button>
                    </div>

                    <div :style="{ display: 'flex', gap: '6px' }">
                        <button
                            type="button"
                            @click="showExistingRecoveryCodes"
                            :style="{ background: 'transparent', border: '1px solid var(--border)', color: 'var(--fg-dim)', padding: '6px 12px', borderRadius: '5px', fontSize: '12px', cursor: 'pointer', fontFamily: 'inherit' }"
                        >{{ t('profile.two_factor_recovery_title') }}</button>
                        <button
                            type="button"
                            @click="disableTwoFactor"
                            :disabled="disabling"
                            :style="{
                                background: 'var(--danger)',
                                color: '#fff',
                                border: 'none',
                                padding: '6px 12px',
                                borderRadius: '5px',
                                fontSize: '12px',
                                fontWeight: 500,
                                cursor: disabling ? 'not-allowed' : 'pointer',
                                opacity: disabling ? 0.6 : 1,
                                fontFamily: 'inherit',
                            }"
                        >{{ t('profile.two_factor_disable') }}</button>
                    </div>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
