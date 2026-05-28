<?php

/*
|--------------------------------------------------------------------------
| Flash Messages
|--------------------------------------------------------------------------
|
| Short toast strings flashed after server-side actions. Grouped by the
| controller that emits them so grep stays obvious. Values with `:name`,
| `:email` etc. are Laravel translation placeholders.
|
*/

return [
    'app_settings' => [
        'saved' => 'Settings saved.',
    ],

    'session' => [
        'expired' => 'The page expired, please try again.',
    ],

    'avatar' => [
        'updated' => 'Profile photo updated.',
        'removed' => 'Profile photo removed.',
    ],

    'backups' => [
        'queued' => 'Backup queued.',
        'cleanup_queued' => 'Backup cleanup queued.',
        'restore_staged' => 'Backup extracted to :path. Finish the restore from the CLI — see the info panel for instructions.',
    ],

    'customers' => [
        'created' => 'Customer ":name" created.',
        'updated' => 'Customer updated.',
        'deleted' => 'Customer ":name" deleted.',
        'member_added' => 'Added :email to :name.',
        'already_member' => ':email is already a member of :name — edit their role from the members list instead.',
        'member_removed' => 'Removed :email from :name.',
    ],

    'email_templates' => [
        'updated' => 'Template ":name" updated.',
        'test_sent' => 'Test email sent to :email.',
    ],

    'email_layout' => [
        'saved' => 'Email layout saved. Templates are being recompiled.',
    ],

    'health' => [
        'queue_ping' => 'Queued ping job (nonce: :nonce).',
        'broadcast_ping' => 'Broadcast ping event (nonce: :nonce).',
    ],

    'mail_settings' => [
        'saved' => 'Mail settings saved.',
        'test_queued' => 'Test mail queued to :email.',
    ],

    'notification_prefs' => [
        'saved' => 'Notification preferences saved.',
    ],

    'profile' => [
        'updated' => 'Profile updated.',
    ],

    'permissions' => [
        'created' => 'Permission created.',
        'deleted' => 'Permission deleted.',
    ],

    'roles' => [
        'created' => 'Role created.',
        'updated' => 'Role updated.',
        'deleted' => 'Role deleted.',
    ],

    'users' => [
        'created' => 'User created.',
        'updated' => 'User updated.',
        'deleted' => 'User deleted.',
        'verified' => 'Email marked as verified.',
        'unverified' => 'Email verification cleared.',
        'banned' => 'User banned.',
        'unbanned' => 'User unbanned.',
        'verification_resent' => 'Verification email sent.',
        'twofa_reset' => '2FA has been reset for this user.',
        'test_notification_sent' => 'Test notification sent.',
        'role_updated' => 'Role updated to :role.',
        'customer_attached' => 'Added :email to :name.',
        'customers_attached' => 'Added :email to :names.',
        'customer_detached' => 'Removed :email from :name.',
        'customer_role_updated' => 'Set :email as :role on :name.',
        'not_member' => ':email is not a member of :name.',
        'platform_permission_updated' => 'Platform access updated.',
    ],
];
