<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Tenancy\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to an invited address (which may not have an account yet), so it is
 * sent as an on-demand mail notification — no `database` channel. The action
 * link lands on the public accept route, which threads the invitee through
 * registration/login and into the workspace.
 */
class WorkspaceInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly WorkspaceInvitation $invitation) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspace = $this->invitation->tenant;
        $url = route('workspace.invitations.accept', ['token' => $this->invitation->token]);
        $inviter = $this->invitation->invitedBy?->fullName();

        return (new MailMessage)
            ->subject(__('invitations.mail.subject', ['workspace' => $workspace->name]))
            ->line($inviter
                ? __('invitations.mail.line_with_inviter', ['inviter' => $inviter, 'workspace' => $workspace->name])
                : __('invitations.mail.line', ['workspace' => $workspace->name]))
            ->line(__('invitations.mail.role', ['role' => $this->invitation->role]))
            ->action(__('invitations.mail.action'), $url)
            ->line(__('invitations.mail.expires'));
    }
}
