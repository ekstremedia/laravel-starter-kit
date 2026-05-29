<?php

declare(strict_types=1);

return [
    'mail' => [
        'subject' => 'Du er invitert til :workspace',
        'line' => 'Du er invitert til å bli med i arbeidsområdet :workspace.',
        'line_with_inviter' => ':inviter inviterte deg til arbeidsområdet :workspace.',
        'role' => 'Rollen din blir: :role.',
        'action' => 'Godta invitasjon',
        'expires' => 'Denne invitasjonslenken utløper om 7 dager.',
    ],

    'flash' => [
        'sent' => 'Invitasjon sendt til :email.',
        'revoked' => 'Invitasjon til :email er trukket tilbake.',
        'already_member' => ':email er allerede medlem av dette arbeidsområdet.',
        'invalid' => 'Invitasjonslenken er ugyldig eller utløpt.',
        'wrong_account' => 'Invitasjonen er for :email. Logg inn med den kontoen for å godta den.',
        'joined' => 'Du ble med i :workspace.',
    ],
];
