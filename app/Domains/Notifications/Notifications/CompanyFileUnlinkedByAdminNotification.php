<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\UsesEmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired when a customer admin unshares (removes from Company Files) a
 * personal file an owner had linked in. The file remains in the owner's
 * personal storage — only the link row is removed.
 */
class CompanyFileUnlinkedByAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesEmailTemplate;

    public function __construct(
        public string $fileName,
        public int $tenantId,
        public string $tenantName,
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
        return $this->renderTemplate('company-file-unlinked', $notifiable, [
            'file_name' => $this->fileName,
            'tenant_name' => $this->tenantName,
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
            'title' => __('files.company_unlinked_title', ['tenant' => $this->tenantName], $locale),
            'message' => __('files.company_unlinked_body', [
                'file' => $this->fileName,
                'tenant' => $this->tenantName,
                'actor' => $this->actorName,
            ], $locale),
            'icon' => 'pi-link',
            'workspace_id' => $this->tenantId,
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
