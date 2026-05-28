<?php

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\UsesEmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountBannedNotification extends Notification
{
    use Queueable;
    use UsesEmailTemplate;

    public function __construct(public ?string $reason = null) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->renderTemplate('account-banned', $notifiable, [
            'reason' => $this->reason ?? '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Account suspended',
            'message' => $this->reason ?? 'Your account has been suspended by an administrator.',
            'icon' => 'pi-ban',
        ];
    }
}
