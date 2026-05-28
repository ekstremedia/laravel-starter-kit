<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AdminLayout from '@/Layouts/CommandLayout.vue';
import Password from 'primevue/password';
import CommandDialog from '@/Components/Command/Dialog.vue';
import Field from '@/Components/Command/Field.vue';
import CmdSelect from '@/Components/Command/Select.vue';
import CmdButton from '@/Components/Command/Button.vue';
import Toggle from '@/Components/Command/Toggle.vue';
import Icon from '@/Components/Command/Icon.vue';
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

interface Props {
    settings: {
        mailer: string; host: string | null; port: number | null; encryption: string | null;
        username: string | null; has_password: boolean; from_address: string | null; from_name: string | null;
        enabled: boolean;
    };
    templates: TemplateGroup[];
}
const props = defineProps<Props>();

const page = usePage<PageProps>();
const userEmail = computed(() => page.props.auth?.user?.email ?? '');

type MailTab = 'smtp' | 'templates';
const activeTab = ref<MailTab>('smtp');
const tabs: { key: MailTab; label: string }[] = [
    { key: 'smtp', label: t('admin.mail.smtp_tab') },
    { key: 'templates', label: t('admin.mail.templates_tab') },
];

const smtpForm = useForm({
    mailer: props.settings.mailer,
    host: props.settings.host ?? '',
    port: props.settings.port,
    encryption: props.settings.encryption,
    username: props.settings.username ?? '',
    password: '',
    from_address: props.settings.from_address ?? '',
    from_name: props.settings.from_name ?? '',
    enabled: props.settings.enabled,
});

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

        <div :style="{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: '18px' }">
            <h1 :style="{ margin: 0, fontSize: '20px', fontWeight: 600, letterSpacing: '-0.01em', color: 'var(--fg)' }">
                {{ t('admin.mail.title') }}
            </h1>
        </div>

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
                            :placeholder="settings.has_password ? t('admin.mail.password_unchanged') : t('admin.mail.password_set')"
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

                <div class="cmd-card" :style="{ padding: '20px', display: 'flex', flexDirection: 'column', gap: '14px' }">
                    <Field v-model="templateForm.subject" :label="t('admin.mail.subject')" />
                    <Field
                        v-model="templateForm.heading"
                        :label="t('admin.mail.heading')"
                        :placeholder="t('admin.mail.heading_placeholder')"
                    />
                    <div>
                        <label :style="fieldLabel" class="cmd-mono cmd-uc">{{ t('admin.mail.body') }}</label>
                        <textarea v-model="templateForm.body" :style="textareaStyle" rows="8"></textarea>
                    </div>
                    <div :style="{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px' }">
                        <Field
                            v-model="templateForm.action_text"
                            :label="t('admin.mail.button_text')"
                            :placeholder="t('admin.mail.button_text_placeholder')"
                        />
                        <Field
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
                            <span v-for="v in editingTemplate.variables" :key="v" :style="chipStyle">{{ handlebar(v) }}</span>
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
