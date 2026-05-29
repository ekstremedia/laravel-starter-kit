<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\UsesEmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceMemberRemovedNotification extends Notification
{
    use Queueable;
    use UsesEmailTemplate;

    public function __construct(public string $workspaceName) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->renderTemplate('workspace-member-removed', $notifiable, [
            'workspace_name' => $this->workspaceName,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => "Removed from {$this->workspaceName}",
            'message' => "You have been removed from {$this->workspaceName}.",
            'icon' => 'pi-building',
        ];
    }
}
