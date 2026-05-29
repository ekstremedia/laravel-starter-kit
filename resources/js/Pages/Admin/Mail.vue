<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, nextTick } from 'vue';
import AdminLayout from '@/Layouts/CommandLayout.vue';
import Password from 'primevue/password';
import CommandDialog from '@/Components/Command/Dialog.vue';
import Field from '@/Components/Command/Field.vue';
import CmdSelect from '@/Components/Command/Select.vue';
import CmdButton from '@/Components/Command/Button.vue';
import Toggle from '@/Components/Command/Toggle.vue';
import Icon from '@/Components/Command/Icon.vue';
import PageTitle from '@/Components/Command/PageTitle.vue';
import type { PageProps } from '@/types';
import { useI18n } from 'vue-i18n';

defineOptions({ layout: AdminLayout });

const { t } = useI18n();

interface LocaleData {
    id: number;
    locale: string;
    subject: string;
    heading: string | null;
    body: string;
    action_text: string | null;
    action_url: string | null;
    has_compiled: boolean;
}

interface TemplateGroup {
    name: string;
    slug: string;
    variables: string[];
    locales: LocaleData[];
}

interface SmtpSettings {
    mailer: string; host: string | null; port: number | null; encryption: string | null;
    username: string | null; has_password: boolean; from_address: string | null; from_name: string | null;
    enabled: boolean;
}

interface MailLayoutData {
    brand_color: string;
    button_color: string;
    body_bg: string;
    card_bg: string;
    text_color: string;
    heading_color: string;
    footer_color: string;
    font_family: string;
    header_mode: string;
    header_logo_url: string | null;
    footer_text: string;
}

interface Props {
    // SMTP + layout are sent only to super admins (null otherwise); templates
    // are always present for anyone with the `manage email templates` gate.
    settings: SmtpSettings | null;
    layout: MailLayoutData | null;
    templates: TemplateGroup[];
}
const props = defineProps<Props>();

const page = usePage<PageProps>();
const userEmail = computed(() => page.props.auth?.user?.email ?? '');
const isSuperAdmin = computed(() => page.props.auth?.user?.is_super_admin ?? false);

type MailTab = 'smtp' | 'layout' | 'templates';
const tabs = computed<{ key: MailTab; label: string }[]>(() => {
    const list: { key: MailTab; label: string }[] = [];
    // SMTP transport + global branding stay super-admin only.
    if (isSuperAdmin.value) {
        list.push({ key: 'smtp', label: t('admin.mail.smtp_tab') });
        list.push({ key: 'layout', label: t('admin.mail.layout_tab') });
    }
    list.push({ key: 'templates', label: t('admin.mail.templates_tab') });
    return list;
});
const activeTab = ref<MailTab>(isSuperAdmin.value ? 'smtp' : 'templates');

const smtpForm = useForm({
    mailer: props.settings?.mailer ?? 'smtp',
    host: props.settings?.host ?? '',
    port: props.settings?.port ?? null,
    encryption: props.settings?.encryption ?? null,
    username: props.settings?.username ?? '',
    password: '',
    from_address: props.settings?.from_address ?? '',
    from_name: props.settings?.from_name ?? '',
    enabled: props.settings?.enabled ?? false,
});

const layoutForm = useForm({
    brand_color: props.layout?.brand_color ?? '#4f46e5',
    button_color: props.layout?.button_color ?? '#4f46e5',
    body_bg: props.layout?.body_bg ?? '#f3f4f6',
    card_bg: props.layout?.card_bg ?? '#ffffff',
    text_color: props.layout?.text_color ?? '#374151',
    heading_color: props.layout?.heading_color ?? '#111827',
    footer_color: props.layout?.footer_color ?? '#9ca3af',
    font_family: props.layout?.font_family ?? 'Arial, Helvetica, sans-serif',
    header_mode: props.layout?.header_mode ?? 'text',
    header_logo_url: props.layout?.header_logo_url ?? '',
    footer_text: props.layout?.footer_text ?? '© {{ year }} {{ app_name }}. All rights reserved.',
});

const fontOptions = [
    { label: 'Arial', value: 'Arial, Helvetica, sans-serif' },
    { label: 'Helvetica', value: 'Helvetica, Arial, sans-serif' },
    { label: 'Verdana', value: 'Verdana, Geneva, sans-serif' },
    { label: 'Tahoma', value: 'Tahoma, Geneva, sans-serif' },
    { label: 'Trebuchet MS', value: '"Trebuchet MS", Helvetica, sans-serif' },
    { label: 'Georgia', value: 'Georgia, "Times New Roman", serif' },
    { label: 'Times New Roman', value: '"Times New Roman", Times, serif' },
    { label: 'Courier New', value: '"Courier New", Courier, monospace' },
];

const headerModeOptions = computed(() => [
    { label: t('admin.mail.header_text'), value: 'text' },
    { label: t('admin.mail.header_image'), value: 'image' },
]);

// Colour fields rendered as a swatch + hex text. Each entry maps to a
// layoutForm key so the markup stays a simple v-for.
type ColorKey = 'brand_color' | 'button_color' | 'body_bg' | 'card_bg' | 'text_color' | 'heading_color' | 'footer_color';
const colorFields = computed<{ key: ColorKey; label: string }[]>(() => [
    { key: 'brand_color', label: t('admin.mail.brand_color') },
    { key: 'button_color', label: t('admin.mail.button_color') },
    { key: 'heading_color', label: t('admin.mail.heading_color') },
    { key: 'text_color', label: t('admin.mail.text_color') },
    { key: 'card_bg', label: t('admin.mail.card_bg') },
    { key: 'body_bg', label: t('admin.mail.body_bg') },
    { key: 'footer_color', label: t('admin.mail.footer_color') },
]);

const loadingLayoutPreview = ref(false);

function saveLayout() {
    layoutForm
        .transform((data) => ({ ...data, header_logo_url: data.header_logo_url || null }))
        .patch('/admin/mail/layout', { preserveScroll: true });
}

function previewLayout() {
    loadingLayoutPreview.value = true;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    fetch('/admin/mail/layout/preview', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({
            brand_color: layoutForm.brand_color,
            button_color: layoutForm.button_color,
            body_bg: layoutForm.body_bg,
            card_bg: layoutForm.card_bg,
            text_color: layoutForm.text_color,
            heading_color: layoutForm.heading_color,
            footer_color: layoutForm.footer_color,
            font_family: layoutForm.font_family,
            header_mode: layoutForm.header_mode,
            header_logo_url: layoutForm.header_logo_url || null,
            footer_text: layoutForm.footer_text,
        }),
    })
        .then(r => r.json())
        .then(json => {
            previewHtml.value = json.html;
            previewDialogVisible.value = true;
        })
        .finally(() => { loadingLayoutPreview.value = false; });
}

const encryptionOptions = [
    { label: 'None', value: '' },
    { label: 'TLS', value: 'tls' },
    { label: 'SSL', value: 'ssl' },
];

const encryptionModel = computed<string>({
    get: () => (smtpForm.encryption ?? '') as string,
    set: (v) => { smtpForm.encryption = v === '' ? null : v; },
});

const portModel = computed<string | number>({
    get: () => smtpForm.port ?? '',
    set: (v) => { smtpForm.port = v === '' ? null : Number(v); },
});

function saveSmtp() {
    smtpForm.patch('/admin/mail', { preserveScroll: true });
}
function sendTest() {
    router.post('/admin/mail/test', {}, { preserveScroll: true });
}

const editingTemplate = ref<TemplateGroup | null>(null);
const editingLocale = ref<string>('en');
const previewDialogVisible = ref(false);
const previewHtml = ref('');
const loadingPreview = ref(false);
const savingTemplate = ref(false);
const sendingTest = ref(false);

const templateForm = useForm({
    subject: '',
    heading: '',
    body: '',
    action_text: '',
    action_url: '',
});

const localeOptions = [
    { label: '🇬🇧 English', value: 'en' },
    { label: '🇳🇴 Norwegian', value: 'no' },
];

function editTemplate(group: TemplateGroup) {
    editingTemplate.value = group;
    editingLocale.value = group.locales[0]?.locale ?? 'en';
    loadLocaleData(group, editingLocale.value);
}

function loadLocaleData(group: TemplateGroup, locale: string) {
    const data = group.locales.find(l => l.locale === locale);
    if (data) {
        templateForm.subject = data.subject;
        templateForm.heading = data.heading ?? '';
        templateForm.body = data.body;
        templateForm.action_text = data.action_text ?? '';
        templateForm.action_url = data.action_url ?? '';
    }
}

function switchLocale(locale: string) {
    editingLocale.value = locale;
    if (editingTemplate.value) {
        loadLocaleData(editingTemplate.value, locale);
    }
}

function currentLocaleData(): LocaleData | undefined {
    return editingTemplate.value?.locales.find(l => l.locale === editingLocale.value);
}

function saveTemplate() {
    const data = currentLocaleData();
    if (!data) return;

    savingTemplate.value = true;
    templateForm.patch(`/admin/mail/templates/${data.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            if (data) {
                data.subject = templateForm.subject;
                data.heading = templateForm.heading;
                data.body = templateForm.body;
                data.action_text = templateForm.action_text;
                data.action_url = templateForm.action_url;
                data.has_compiled = true;
            }
        },
        onFinish: () => { savingTemplate.value = false; },
    });
}

function draftPayload() {
    return {
        subject: templateForm.subject,
        heading: templateForm.heading,
        body: templateForm.body,
        action_text: templateForm.action_text,
        action_url: templateForm.action_url,
    };
}

function previewTemplate() {
    const data = currentLocaleData();
    if (!data) return;

    loadingPreview.value = true;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    fetch(`/admin/mail/templates/${data.id}/preview`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify(draftPayload()),
    })
        .then(r => r.json())
        .then(json => {
            previewHtml.value = json.html;
            previewDialogVisible.value = true;
        })
        .finally(() => { loadingPreview.value = false; });
}

function sendTemplateTest() {
    const data = currentLocaleData();
    if (!data) return;

    sendingTest.value = true;
    router.post(`/admin/mail/templates/${data.id}/test`, {
        email: userEmail.value,
        ...draftPayload(),
    }, {
        preserveScroll: true,
        onFinish: () => { sendingTest.value = false; },
    });
}

function closeEditor() {
    editingTemplate.value = null;
}

function handlebar(name: string): string {
    return `{{ ${name} }}`;
}

// Literal handlebar tokens for display (a raw `{{ … }}` in the template markup
// would be parsed as an interpolation by the Vue compiler).
const yearToken = handlebar('year');
const appNameToken = handlebar('app_name');

// Click-to-insert a {{ variable }} at the caret of whichever content field
// last had focus. Fields carry an id of `tpl-<key>`; focusin bubbles, so a
// single listener on the editor card tracks the active field/element.
const editableKeys = ['subject', 'heading', 'body', 'action_text', 'action_url'] as const;
type EditableKey = typeof editableKeys[number];
const activeFieldKey = ref<EditableKey>('body');
const activeFieldEl = ref<HTMLInputElement | HTMLTextAreaElement | null>(null);

function onEditorFocusIn(e: FocusEvent) {
    const el = e.target;
    if ((el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) && el.id.startsWith('tpl-')) {
        const key = el.id.slice(4) as EditableKey;
        if ((editableKeys as readonly string[]).includes(key)) {
            activeFieldKey.value = key;
            activeFieldEl.value = el;
        }
    }
}

function insertVariable(name: string) {
    const token = handlebar(name);
    const key = activeFieldKey.value;
    const el = activeFieldEl.value;
    const current = String(templateForm[key] ?? '');

    if (el && el.id === `tpl-${key}` && el.selectionStart !== null) {
        const start = el.selectionStart;
        const end = el.selectionEnd ?? start;
        templateForm[key] = current.slice(0, start) + token + current.slice(end);
        nextTick(() => {
            el.focus();
            const pos = start + token.length;
            el.setSelectionRange(pos, pos);
        });
    } else {
        templateForm[key] = current ? `${current} ${token}` : token;
    }
}

const fieldLabel = {
    display: 'block',
    fontSize: '10px',
    color: 'var(--fg-mute)',
    marginBottom: '6px',
    letterSpacing: '0.06em',
    fontWeight: 500,
};
const textareaStyle = {
    width: '100%',
    background: 'var(--panel2)',
    border: '1px solid var(--border)',
    borderRadius: '5px',
    padding: '8px 10px',
    color: 'var(--fg)',
    fontSize: '13px',
    outline: 'none',
    fontFamily: 'var(--font-mono)',
    resize: 'vertical' as const,
    minHeight: '160px',
};
const chipStyle = {
    display: 'inline-flex',
    alignItems: 'center',
    padding: '2px 8px',
    fontSize: '11px',
    fontFamily: 'var(--font-mono)',
    color: 'var(--fg-dim)',
    background: 'var(--panel2)',
    border: '1px solid var(--border)',
    borderRadius: '3px',
};
const slugChipStyle = {
    ...chipStyle,
    color: 'var(--accent)',
    background: 'var(--accent-soft)',
    border: '1px solid var(--accent-border)',
};
</script>

<template>
    <div :style="{ padding: '24px 32px', maxWidth: '1100px', margin: '0 auto' }">
        <Head title="Mail · Admin" />

        <!-- Preview dialog -->
        <CommandDialog
            v-model:visible="previewDialogVisible"
            :title="t('admin.mail.preview_title')"
            width="780px"
            :padded="false"
        >
            <div :style="{ background: 'var(--panel2)', padding: '12px', minHeight: '420px' }">
                <iframe
                    :srcdoc="previewHtml"
                    sandbox=""
                    referrerpolicy="no-referrer"
                    title="Email preview"
                    :style="{ width: '100%', minHeight: '520px', border: 'none', borderRadius: '6px', background: '#fff' }"
                />
            </div>
        </CommandDialog>

        <PageTitle :title="t('admin.mail.title')" />

        <!-- Tabs -->
        <div
            :style="{
                display: 'flex',
                gap: '2px',
                marginBottom: '16px',
                borderBottom: '1px solid var(--border)',
            }"
        >
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                @click="activeTab = tab.key"
                :style="{
                    background: 'transparent',
                    border: 'none',
                    borderBottom: activeTab === tab.key ? '2px solid var(--accent)' : '2px solid transparent',
                    padding: '8px 14px',
                    marginBottom: '-1px',
                    fontSize: '12px',
                    fontWeight: activeTab === tab.key ? 500 : 400,
                    color: activeTab === tab.key ? 'var(--fg)' : 'var(--fg-dim)',
                    cursor: 'pointer',
                    fontFamily: 'inherit',
                }"
            >
                {{ tab.label }}
            </button>
        </div>

        <!-- SMTP tab -->
        <div v-if="activeTab === 'smtp'">
            <form
                @submit.prevent="saveSmtp"
                class="cmd-card"
                :style="{ maxWidth: '860px', padding: '24px', display: 'flex', flexDirection: 'column', gap: '16px' }"
            >
                <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '16px', alignItems: 'end' }">
                    <Field v-model="smtpForm.mailer" :label="t('admin.mail.mailer')" />
                    <div :style="{ display: 'flex', alignItems: 'center', gap: '10px', paddingBottom: '8px' }">
                        <Toggle v-model="smtpForm.enabled" :label="t('admin.mail.enabled')" />
                        <span :style="{ fontSize: '12.5px', color: 'var(--fg)' }">{{ t('admin.mail.enabled') }}</span>
                    </div>
                </div>

                <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '16px' }">
                    <Field v-model="smtpForm.host" :label="t('admin.mail.host')" />
                    <Field v-model="portModel" type="number" :label="t('admin.mail.port')" numeric />
                </div>

                <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '16px' }">
                    <CmdSelect
                        v-model="encryptionModel"
                        :label="t('admin.mail.encryption')"
                        :options="encryptionOptions"
                    />
                    <Field v-model="smtpForm.username" :label="t('admin.mail.username')" />
                    <div>
                        <label :style="fieldLabel" class="cmd-mono cmd-uc">{{ t('admin.mail.password') }}</label>
                        <Password
                            v-model="smtpForm.password"
                            toggleMask
                            :feedback="false"
                            class="w-full"
                            inputClass="w-full"
                            :placeholder="settings?.has_password ? t('admin.mail.password_unchanged') : t('admin.mail.password_set')"
                        />
                    </div>
                </div>

                <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '16px' }">
                    <Field v-model="smtpForm.from_address" :label="t('admin.mail.from_address')" />
                    <Field v-model="smtpForm.from_name" :label="t('admin.mail.from_name')" />
                </div>

                <div :style="{ display: 'flex', gap: '8px', paddingTop: '4px' }">
                    <CmdButton type="submit" variant="primary" size="md" :loading="smtpForm.processing">
                        <template #icon><Icon name="check" :size="13" /></template>
                        {{ t('common.save') }}
                    </CmdButton>
                    <CmdButton type="button" variant="ghost" size="md" @click="sendTest">
                        <template #icon><Icon name="mail" :size="13" /></template>
                        {{ t('admin.mail.send_test') }}
                    </CmdButton>
                </div>
            </form>
        </div>

        <!-- Layout tab (super admin only) -->
        <div v-if="activeTab === 'layout'">
            <form
                @submit.prevent="saveLayout"
                class="cmd-card"
                :style="{ maxWidth: '860px', padding: '24px', display: 'flex', flexDirection: 'column', gap: '18px' }"
            >
                <p :style="{ margin: 0, fontSize: '12px', color: 'var(--fg-dim)', lineHeight: 1.5 }">
                    {{ t('admin.mail.layout_intro') }}
                </p>

                <!-- Colours -->
                <div>
                    <p class="cmd-mono cmd-uc" :style="{ margin: '0 0 10px', fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em' }">
                        {{ t('admin.mail.colors') }}
                    </p>
                    <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: '14px' }">
                        <div v-for="cf in colorFields" :key="cf.key">
                            <label :style="fieldLabel" class="cmd-mono cmd-uc">{{ cf.label }}</label>
                            <div :style="{ display: 'flex', alignItems: 'center', gap: '8px' }">
                                <input
                                    type="color"
                                    v-model="layoutForm[cf.key]"
                                    :aria-label="cf.label"
                                    :style="{ width: '36px', height: '34px', padding: '2px', border: '1px solid var(--border)', borderRadius: '5px', background: 'var(--panel2)', cursor: 'pointer', flexShrink: 0 }"
                                />
                                <input
                                    type="text"
                                    v-model="layoutForm[cf.key]"
                                    spellcheck="false"
                                    :style="{ flex: 1, minWidth: 0, background: 'var(--panel2)', border: '1px solid var(--border)', borderRadius: '5px', padding: '8px 10px', color: 'var(--fg)', fontSize: '13px', outline: 'none', fontFamily: 'var(--font-mono)' }"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Typography -->
                <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '14px', alignItems: 'end' }">
                    <CmdSelect
                        v-model="layoutForm.font_family"
                        :label="t('admin.mail.font_family')"
                        :options="fontOptions"
                    />
                    <CmdSelect
                        v-model="layoutForm.header_mode"
                        :label="t('admin.mail.header_mode')"
                        :options="headerModeOptions"
                    />
                </div>

                <!-- Logo URL (image header mode) -->
                <Field
                    v-if="layoutForm.header_mode === 'image'"
                    v-model="layoutForm.header_logo_url"
                    :label="t('admin.mail.header_logo_url')"
                    placeholder="https://..."
                    :error="layoutForm.errors.header_logo_url"
                />

                <!-- Footer -->
                <Field
                    v-model="layoutForm.footer_text"
                    :label="t('admin.mail.footer_text')"
                    :error="layoutForm.errors.footer_text"
                />
                <p :style="{ margin: '-8px 0 0', fontSize: '11px', color: 'var(--fg-mute)', display: 'flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' }">
                    <span>{{ t('admin.mail.footer_vars_hint') }}</span>
                    <code :style="chipStyle">{{ yearToken }}</code>
                    <code :style="chipStyle">{{ appNameToken }}</code>
                </p>

                <div :style="{ display: 'flex', gap: '8px', paddingTop: '4px' }">
                    <CmdButton type="submit" variant="primary" size="md" :loading="layoutForm.processing">
                        <template #icon><Icon name="check" :size="13" /></template>
                        {{ t('common.save') }}
                    </CmdButton>
                    <CmdButton type="button" variant="ghost" size="md" :loading="loadingLayoutPreview" @click="previewLayout">
                        {{ t('admin.mail.preview') }}
                    </CmdButton>
                </div>
            </form>
        </div>

        <!-- Templates tab -->
        <div v-if="activeTab === 'templates'">
            <!-- Template list -->
            <div
                v-if="!editingTemplate"
                :style="{ display: 'flex', flexDirection: 'column', gap: '8px', maxWidth: '860px' }"
            >
                <button
                    v-for="group in templates"
                    :key="group.slug"
                    type="button"
                    class="cmd-card"
                    :style="{
                        width: '100%',
                        textAlign: 'left',
                        padding: '14px 16px',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        gap: '12px',
                        cursor: 'pointer',
                        fontFamily: 'inherit',
                        color: 'var(--fg)',
                    }"
                    @click="editTemplate(group)"
                >
                    <div>
                        <h3 :style="{ margin: 0, fontSize: '13.5px', fontWeight: 500 }">{{ group.name }}</h3>
                        <p :style="{ margin: '6px 0 0', fontSize: '11.5px', color: 'var(--fg-dim)', display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }">
                            <code :style="slugChipStyle">{{ group.slug }}</code>
                            <span v-for="l in group.locales" :key="l.locale" :style="chipStyle">{{ l.locale.toUpperCase() }}</span>
                        </p>
                    </div>
                    <Icon name="chevR" :size="14" :style="{ color: 'var(--fg-mute)' }" />
                </button>
            </div>

            <!-- Template editor -->
            <div v-else :style="{ maxWidth: '860px' }">
                <div :style="{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '16px' }">
                    <CmdButton variant="ghost" size="sm" @click="closeEditor">
                        <template #icon>
                            <Icon name="arrow" :size="12" :style="{ transform: 'rotate(180deg)' }" />
                        </template>
                        {{ t('common.back') }}
                    </CmdButton>
                    <h2 :style="{ margin: 0, fontSize: '16px', fontWeight: 600, color: 'var(--fg)' }">
                        {{ editingTemplate.name }}
                    </h2>
                    <code :style="slugChipStyle">{{ editingTemplate.slug }}</code>
                </div>

                <div :style="{ display: 'flex', gap: '6px', marginBottom: '16px' }">
                    <CmdButton
                        v-for="opt in localeOptions"
                        :key="opt.value"
                        size="sm"
                        :variant="editingLocale === opt.value ? 'primary' : 'ghost'"
                        @click="switchLocale(opt.value)"
                    >
                        {{ opt.label }}
                    </CmdButton>
                </div>

                <div class="cmd-card" :style="{ padding: '20px', display: 'flex', flexDirection: 'column', gap: '14px' }" @focusin="onEditorFocusIn">
                    <Field id="tpl-subject" v-model="templateForm.subject" :label="t('admin.mail.subject')" />
                    <Field
                        id="tpl-heading"
                        v-model="templateForm.heading"
                        :label="t('admin.mail.heading')"
                        :placeholder="t('admin.mail.heading_placeholder')"
                    />
                    <div>
                        <label :style="fieldLabel" class="cmd-mono cmd-uc">{{ t('admin.mail.body') }}</label>
                        <textarea id="tpl-body" v-model="templateForm.body" :style="textareaStyle" rows="8"></textarea>
                    </div>
                    <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px' }">
                        <Field
                            id="tpl-action_text"
                            v-model="templateForm.action_text"
                            :label="t('admin.mail.button_text')"
                            :placeholder="t('admin.mail.button_text_placeholder')"
                        />
                        <Field
                            id="tpl-action_url"
                            v-model="templateForm.action_url"
                            :label="t('admin.mail.button_url')"
                            placeholder="https://..."
                        />
                    </div>

                    <div :style="{ borderTop: '1px solid var(--border)', paddingTop: '12px' }">
                        <p class="cmd-mono cmd-uc" :style="{ margin: '0 0 8px', fontSize: '10px', color: 'var(--fg-mute)', letterSpacing: '0.06em' }">
                            {{ t('admin.mail.available_variables') }}
                        </p>
                        <div :style="{ display: 'flex', flexWrap: 'wrap', gap: '5px' }">
                            <button
                                v-for="v in editingTemplate.variables"
                                :key="v"
                                type="button"
                                :title="t('admin.mail.insert_variable')"
                                :style="{ ...chipStyle, cursor: 'pointer', transition: 'border-color 0.12s, color 0.12s' }"
                                class="cmd-var-chip"
                                @click="insertVariable(v)"
                            >{{ handlebar(v) }}</button>
                        </div>
                    </div>

                    <div :style="{ display: 'flex', gap: '8px', paddingTop: '4px' }">
                        <CmdButton variant="primary" size="md" :loading="savingTemplate" @click="saveTemplate">
                            <template #icon><Icon name="check" :size="13" /></template>
                            {{ t('common.save') }}
                        </CmdButton>
                        <CmdButton variant="ghost" size="md" :loading="loadingPreview" @click="previewTemplate">
                            {{ t('admin.mail.preview') }}
                        </CmdButton>
                        <CmdButton variant="ghost" size="md" :loading="sendingTest" @click="sendTemplateTest">
                            <template #icon><Icon name="mail" :size="13" /></template>
                            {{ t('admin.mail.send_test_to_me') }}
                        </CmdButton>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.cmd-var-chip:hover {
    border-color: var(--accent-border) !important;
    color: var(--accent) !important;
}
</style>
