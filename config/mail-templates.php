<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Email template registry
|--------------------------------------------------------------------------
|
| Single source of truth for every transactional email the app can send.
| Each entry declares the variables a template exposes (shown in the
| dashboard editor) and the default copy per locale. The dashboard stores
| live, admin-editable copies in the `email_templates` table; this registry
| only provides the *defaults* and the *variable contract*.
|
| Adding a new email is declare-once:
|   1. Add an entry here (slug => variables + per-locale defaults).
|   2. Run `php artisan mail:sync-templates` (creates the DB rows; never
|      clobbers admin edits — only fills content on first creation).
|   3. Send it from a Notification via the `UsesEmailTemplate` trait:
|        $this->renderTemplate('your-slug', $notifiable, [...vars]);
|
| `mail:sync-templates --fresh` resets content to these defaults.
| `mail:sync-templates --prune` deletes DB rows whose slug was removed here.
|
| `variables` is the list of {{ placeholders }} a template may use. The
| editor renders these as click-to-insert chips and the preview/test fills
| them with sample values (see EmailTemplateController::sampleData()).
|
*/

return [

    'welcome' => [
        'variables' => ['user_name', 'app_name', 'app_url'],
        'locales' => [
            'en' => [
                'name' => 'Welcome Email',
                'subject' => 'Welcome to {{ app_name }}!',
                'heading' => 'Welcome, {{ user_name }}!',
                'body' => "Your account is ready to go.\n\nWe're glad to have you on board. If you have any questions, don't hesitate to reach out.",
                'action_text' => 'Go to Dashboard',
                'action_url' => '{{ app_url }}/app',
            ],
            'no' => [
                'name' => 'Velkomst-e-post',
                'subject' => 'Velkommen til {{ app_name }}!',
                'heading' => 'Velkommen, {{ user_name }}!',
                'body' => "Kontoen din er klar til bruk.\n\nVi er glade for å ha deg med. Hvis du har spørsmål, ikke nøl med å ta kontakt.",
                'action_text' => 'Gå til dashbordet',
                'action_url' => '{{ app_url }}/app',
            ],
        ],
    ],

    'account-banned' => [
        'variables' => ['user_name', 'reason'],
        'locales' => [
            'en' => [
                'name' => 'Account Suspended',
                'subject' => 'Your account has been suspended',
                'heading' => 'Hi {{ user_name }},',
                'body' => "Your account has been suspended by an administrator.\n\nReason: {{ reason }}\n\nContact support if you believe this is a mistake.",
                'action_text' => null,
                'action_url' => null,
            ],
            'no' => [
                'name' => 'Konto suspendert',
                'subject' => 'Kontoen din har blitt suspendert',
                'heading' => 'Hei {{ user_name }},',
                'body' => "Kontoen din har blitt suspendert av en administrator.\n\nÅrsak: {{ reason }}\n\nTa kontakt med brukerstøtte hvis du mener dette er en feil.",
                'action_text' => null,
                'action_url' => null,
            ],
        ],
    ],

    'admin-test' => [
        'variables' => ['user_name', 'message'],
        'locales' => [
            'en' => [
                'name' => 'Test Notification',
                'subject' => 'Test notification',
                'heading' => 'Hi {{ user_name }},',
                'body' => "{{ message }}\n\nThis is a test notification sent from the admin dashboard.",
                'action_text' => null,
                'action_url' => null,
            ],
            'no' => [
                'name' => 'Testvarsel',
                'subject' => 'Testvarsel',
                'heading' => 'Hei {{ user_name }},',
                'body' => "{{ message }}\n\nDette er et testvarsel sendt fra administrasjonspanelet.",
                'action_text' => null,
                'action_url' => null,
            ],
        ],
    ],

    'workspace-member-added' => [
        'variables' => ['user_name', 'workspace_name', 'app_url'],
        'locales' => [
            'en' => [
                'name' => 'Added to Workspace',
                'subject' => 'You have been added to {{ workspace_name }}',
                'heading' => 'Hi {{ user_name }},',
                'body' => "You have been added as a member of {{ workspace_name }}.\n\nYou can now access this workspace from your dashboard.",
                'action_text' => 'Go to Dashboard',
                'action_url' => '{{ app_url }}/app',
            ],
            'no' => [
                'name' => 'Lagt til kunde',
                'subject' => 'Du har blitt lagt til i {{ workspace_name }}',
                'heading' => 'Hei {{ user_name }},',
                'body' => "Du har blitt lagt til som medlem av {{ workspace_name }}.\n\nDu kan nå få tilgang til dette arbeidsområdet fra dashbordet ditt.",
                'action_text' => 'Gå til dashbordet',
                'action_url' => '{{ app_url }}/app',
            ],
        ],
    ],

    'workspace-member-removed' => [
        'variables' => ['user_name', 'workspace_name'],
        'locales' => [
            'en' => [
                'name' => 'Removed from Workspace',
                'subject' => 'You have been removed from {{ workspace_name }}',
                'heading' => 'Hi {{ user_name }},',
                'body' => "You have been removed from {{ workspace_name }}.\n\nIf you believe this is a mistake, please contact your administrator.",
                'action_text' => null,
                'action_url' => null,
            ],
            'no' => [
                'name' => 'Fjernet fra kunde',
                'subject' => 'Du har blitt fjernet fra {{ workspace_name }}',
                'heading' => 'Hei {{ user_name }},',
                'body' => "Du har blitt fjernet fra {{ workspace_name }}.\n\nHvis du mener dette er en feil, vennligst kontakt administratoren din.",
                'action_text' => null,
                'action_url' => null,
            ],
        ],
    ],

    'email-verification' => [
        'variables' => ['user_name', 'verification_url'],
        'locales' => [
            'en' => [
                'name' => 'Verify Email',
                'subject' => 'Verify your email address',
                'heading' => 'Hi {{ user_name }},',
                'body' => "Please click the button below to verify your email address.\n\nIf you did not create an account, no further action is required.",
                'action_text' => 'Verify Email',
                'action_url' => '{{ verification_url }}',
            ],
            'no' => [
                'name' => 'Bekreft e-post',
                'subject' => 'Bekreft e-postadressen din',
                'heading' => 'Hei {{ user_name }},',
                'body' => "Vennligst klikk på knappen nedenfor for å bekrefte e-postadressen din.\n\nHvis du ikke opprettet en konto, trenger du ikke gjøre noe.",
                'action_text' => 'Bekreft e-post',
                'action_url' => '{{ verification_url }}',
            ],
        ],
    ],

    'password-reset' => [
        'variables' => ['user_name', 'reset_url', 'expire_minutes'],
        'locales' => [
            'en' => [
                'name' => 'Password Reset',
                'subject' => 'Reset your password',
                'heading' => 'Hi {{ user_name }},',
                'body' => "You are receiving this email because we received a password reset request for your account.\n\nThis link will expire in {{ expire_minutes }} minutes.\n\nIf you did not request a password reset, no further action is required.",
                'action_text' => 'Reset Password',
                'action_url' => '{{ reset_url }}',
            ],
            'no' => [
                'name' => 'Tilbakestill passord',
                'subject' => 'Tilbakestill passordet ditt',
                'heading' => 'Hei {{ user_name }},',
                'body' => "Du mottar denne e-posten fordi vi mottok en forespørsel om tilbakestilling av passord for kontoen din.\n\nDenne lenken utløper om {{ expire_minutes }} minutter.\n\nHvis du ikke ba om tilbakestilling av passord, trenger du ikke gjøre noe.",
                'action_text' => 'Tilbakestill passord',
                'action_url' => '{{ reset_url }}',
            ],
        ],
    ],

    'notification-digest' => [
        'variables' => ['user_name', 'count', 'frequency', 'lines', 'app_name', 'app_url'],
        'locales' => [
            'en' => [
                'name' => 'Notification digest',
                'subject' => 'Your {{ frequency }} digest from {{ app_name }}',
                'heading' => 'Hi {{ user_name }},',
                'body' => "Here is a summary of your {{ count }} unread notification(s):\n\n{{ lines }}",
                'action_text' => 'Open app',
                'action_url' => '{{ app_url }}',
            ],
            'no' => [
                'name' => 'Varslingssammendrag',
                'subject' => 'Ditt {{ frequency }} sammendrag fra {{ app_name }}',
                'heading' => 'Hei {{ user_name }},',
                'body' => "Her er et sammendrag av dine {{ count }} uleste varsler:\n\n{{ lines }}",
                'action_text' => 'Åpne appen',
                'action_url' => '{{ app_url }}',
            ],
        ],
    ],

    'new-chat-message' => [
        'variables' => ['user_name', 'sender_name', 'message_preview', 'app_name', 'app_url'],
        'locales' => [
            'en' => [
                'name' => 'New Chat Message',
                'subject' => 'New message from {{ sender_name }}',
                'heading' => 'Hi {{ user_name }},',
                'body' => "{{ sender_name }} sent you a new message on {{ app_name }}:\n\n{{ message_preview }}",
                'action_text' => 'Open chat',
                'action_url' => '{{ app_url }}/chat',
            ],
            'no' => [
                'name' => 'Ny chatmelding',
                'subject' => 'Ny melding fra {{ sender_name }}',
                'heading' => 'Hei {{ user_name }},',
                'body' => "{{ sender_name }} har sendt deg en ny melding på {{ app_name }}:\n\n{{ message_preview }}",
                'action_text' => 'Åpne chat',
                'action_url' => '{{ app_url }}/chat',
            ],
        ],
    ],

];
