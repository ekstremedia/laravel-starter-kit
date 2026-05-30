<?php

/*
|--------------------------------------------------------------------------
| Flash-meldinger
|--------------------------------------------------------------------------
|
| Korte toast-tekster som vises etter handlinger på serversiden. Gruppert
| etter kontrolleren som sender dem. Verdier med `:name`, `:email` osv.
| er Laravels oversettelses-plassholdere.
|
*/

return [
    'app_settings' => [
        'saved' => 'Innstillinger lagret.',
    ],

    'session' => [
        'expired' => 'Siden har utløpt, prøv igjen.',
    ],

    'avatar' => [
        'updated' => 'Profilbildet er oppdatert.',
        'removed' => 'Profilbildet er fjernet.',
    ],

    'backups' => [
        'queued' => 'Sikkerhetskopi lagt i kø.',
        'cleanup_queued' => 'Opprydding av sikkerhetskopier lagt i kø.',
        'restore_staged' => 'Sikkerhetskopien er pakket ut til :path. Fullfør gjenoppretting fra CLI — se infopanelet for instruksjoner.',
    ],

    'workspaces' => [
        'created' => 'Kunden «:name» ble opprettet.',
        'updated' => 'Kunden er oppdatert.',
        'deleted' => 'Kunden «:name» ble slettet.',
        'member_added' => 'La til :email i :name.',
        'already_member' => ':email er allerede medlem av :name — endre rollen fra medlemslisten i stedet.',
        'member_removed' => 'Fjernet :email fra :name.',
        'member_role_updated' => 'Oppdaterte rollene til :email til :role.',
        'last_admin' => 'Et arbeidsområde må ha minst én administrator.',
        'not_member' => ':email er ikke medlem av :name.',
    ],

    'email_templates' => [
        'updated' => 'Malen «:name» er oppdatert.',
        'test_sent' => 'Test-e-post sendt til :email.',
    ],

    'email_layout' => [
        'saved' => 'E-postoppsettet er lagret. Malene kompileres på nytt.',
    ],

    'health' => [
        'queue_ping' => 'Ping-jobb lagt i kø (nonce: :nonce).',
        'broadcast_ping' => 'Broadcast-ping sendt (nonce: :nonce).',
    ],

    'mail_settings' => [
        'saved' => 'E-postinnstillinger lagret.',
        'test_queued' => 'Test-e-post lagt i kø til :email.',
    ],

    'notification_prefs' => [
        'saved' => 'Varslingsinnstillinger lagret.',
    ],

    'profile' => [
        'updated' => 'Profilen er oppdatert.',
    ],

    'permissions' => [
        'created' => 'Tillatelsen er opprettet.',
        'deleted' => 'Tillatelsen er slettet.',
    ],

    'roles' => [
        'created' => 'Rollen er opprettet.',
        'updated' => 'Rollen er oppdatert.',
        'deleted' => 'Rollen er slettet.',
    ],

    'users' => [
        'created' => 'Brukeren er opprettet.',
        'updated' => 'Brukeren er oppdatert.',
        'deleted' => 'Brukeren er slettet.',
        'verified' => 'E-postadressen er markert som bekreftet.',
        'unverified' => 'E-postbekreftelsen er fjernet.',
        'banned' => 'Brukeren er utestengt.',
        'unbanned' => 'Brukeren er gjeninnsatt.',
        'verification_resent' => 'Bekreftelses-e-post sendt.',
        'twofa_reset' => 'Tofaktorautentisering er tilbakestilt for denne brukeren.',
        'test_notification_sent' => 'Testvarsel sendt.',
        'bulk_email_sent' => 'E-post sendt til :count bruker(e).',
        'role_updated' => 'Rolle oppdatert til :role.',
        'workspace_attached' => 'La til :email i :name.',
        'workspaces_attached' => 'La til :email i :names.',
        'workspace_detached' => 'Fjernet :email fra :name.',
        'workspace_role_updated' => 'Satte :email som :role på :name.',
        'not_member' => ':email er ikke medlem av :name.',
        'platform_permission_updated' => 'Plattformtilgang oppdatert.',
    ],
];
