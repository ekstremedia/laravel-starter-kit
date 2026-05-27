<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\UsesEmailTemplate;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerMemberAddedNotification extends Notification
{
    use Queueable;
    use UsesEmailTemplate;

    public function __construct(public Tenant $customer) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->renderTemplate('customer-member-added', $notifiable, [
            'customer_name' => $this->customer->name,
            'app_url' => config('app.url'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => "Added to {$this->customer->name}",
            'message' => "You have been added as a member of {$this->customer->name}.",
            'icon' => 'pi-building',
        ];
    }
}
