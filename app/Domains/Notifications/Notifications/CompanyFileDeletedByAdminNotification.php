<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\UsesEmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired when a workspace admin (or super admin) deletes a native company file
 * that someone else uploaded. Channels are chosen by the acting admin in the
 * delete dialog — the admin can decide per-action whether the owner gets an
 * in-app notification, an email, both, or nothing.
 */
class CompanyFileDeletedByAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesEmailTemplate;

    public function __construct(
        public string $fileName,
        public int $workspaceId,
        public string $workspaceName,
        public string $actorName,
        public bool $sendEmail = true,
        public bool $sendDatabase = true,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];
        if ($this->sendDatabase) {
            $channels[] = 'database';
        }
        if ($this->sendEmail) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->renderTemplate('company-file-deleted', $notifiable, [
            'file_name' => $this->fileName,
            'tenant_name' => $this->workspaceName,
            'actor_name' => $this->actorName,
            'app_name' => (string) config('app.name'),
            'app_url' => (string) config('app.url'),
        ]);
    }

    /**
     * @return array{title: string, message: string, icon: string, workspace_id: int, file_name: string}
     */
    public function toArray(object $notifiable): array
    {
        $locale = $this->localeFor($notifiable);

        return [
            'title' => __('files.company_deleted_title', ['workspace' => $this->workspaceName], $locale),
            'message' => __('files.company_deleted_body', [
                'file' => $this->fileName,
                'workspace' => $this->workspaceName,
                'actor' => $this->actorName,
            ], $locale),
            'icon' => 'pi-trash',
            'workspace_id' => $this->workspaceId,
            'file_name' => $this->fileName,
        ];
    }

    private function localeFor(object $notifiable): string
    {
        if (method_exists($notifiable, 'preferredLocale')) {
            $locale = $notifiable->preferredLocale();
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        }

        return 'en';
    }
}
