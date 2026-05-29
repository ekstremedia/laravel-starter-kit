<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\UsesEmailTemplate;
use App\Domains\Workspaces\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceMemberAddedNotification extends Notification
{
    use Queueable;
    use UsesEmailTemplate;

    public function __construct(public Workspace $workspace) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->renderTemplate('workspace-member-added', $notifiable, [
            'workspace_name' => $this->workspace->name,
            'app_url' => config('app.url'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => "Added to {$this->workspace->name}",
            'message' => "You have been added as a member of {$this->workspace->name}.",
            'icon' => 'pi-building',
        ];
    }
}
