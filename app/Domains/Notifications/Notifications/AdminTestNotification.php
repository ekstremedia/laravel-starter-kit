<?php

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\UsesEmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminTestNotification extends Notification
{
    use Queueable;
    use UsesEmailTemplate;

    public function __construct(public string $message = 'Hello from the admin panel!') {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->renderTemplate('admin-test', $notifiable, [
            'message' => $this->message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Test notification',
            'message' => $this->message,
            'icon' => 'pi-bell',
        ];
    }
}
