<?php

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\UsesEmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification
{
    use Queueable;
    use UsesEmailTemplate;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->renderTemplate('welcome', $notifiable, [
            'app_name' => config('app.name'),
            'app_url' => config('app.url'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Welcome aboard',
            'message' => "We're glad to have you.",
            'icon' => 'pi-star',
        ];
    }
}
