<?php

declare(strict_types=1);

return [
    'mail' => [
        'subject' => 'You\'re invited to join :workspace',
        'line' => 'You\'ve been invited to join the :workspace workspace.',
        'line_with_inviter' => ':inviter invited you to join the :workspace workspace.',
        'role' => 'Your role will be: :role.',
        'action' => 'Accept invitation',
        'expires' => 'This invitation link expires in 7 days.',
    ],

    'flash' => [
        'sent' => 'Invitation sent to :email.',
        'revoked' => 'Invitation to :email revoked.',
        'already_member' => ':email is already a member of this workspace.',
        'invalid' => 'That invitation link is invalid or has expired.',
        'wrong_account' => 'That invitation is for :email. Log in with that account to accept it.',
        'joined' => 'You\'ve joined :workspace.',
    ],
];
