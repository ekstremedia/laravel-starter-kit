<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Notifications\Concerns\UsesEmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired when a user crosses a storage-usage threshold (80 / 95 / 100%).
 * Each threshold fires at most once — the last-alerted value lives in
 * UserSetting so we don't spam on every upload once near the cap.
 */
class ApproachingStorageLimitNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesEmailTemplate;

    public function __construct(
        public int $thresholdPercent,
        public int $usedBytes,
        public int $quotaBytes,
        public ?int $workspaceId = null,
        public ?string $workspaceName = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $settings = $notifiable->settings()->resolved();

        if (! ($settings['notification_storage_alerts'] ?? true)) {
            return [];
        }

        $channels = ['database'];

        if ($settings['notification_email_immediate'] ?? false) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->renderTemplate('approaching-storage-limit', $notifiable, [
            'threshold_percent' => (string) $this->thresholdPercent,
            'used_display' => $this->humanBytes($this->usedBytes),
            'quota_display' => $this->humanBytes($this->quotaBytes),
            'tenant_name' => (string) ($this->workspaceName ?? ''),
            'app_name' => (string) config('app.name'),
            'app_url' => (string) config('app.url'),
        ]);
    }

    /**
     * @return array{title: string, message: string, icon: string, threshold: int, workspace_id: int|string|null}
     */
    public function toArray(object $notifiable): array
    {
        $locale = $this->localeFor($notifiable);

        // If we know which company this alert is for, include its name in the
        // title/message so the user knows which bucket is filling up.
        if ($this->workspaceName !== null && $this->workspaceName !== '') {
            $title = __('files.storage_alert_title_tenant', [
                'percent' => $this->thresholdPercent,
                'workspace' => $this->workspaceName,
            ], $locale);
            $message = __('files.storage_alert_body_tenant', [
                'used' => $this->humanBytes($this->usedBytes),
                'quota' => $this->humanBytes($this->quotaBytes),
                'workspace' => $this->workspaceName,
            ], $locale);
        } else {
            $title = __('files.storage_alert_title', ['percent' => $this->thresholdPercent], $locale);
            $message = __('files.storage_alert_body', [
                'used' => $this->humanBytes($this->usedBytes),
                'quota' => $this->humanBytes($this->quotaBytes),
            ], $locale);
        }

        return [
            'title' => $title,
            'message' => $message,
            'icon' => 'pi-database',
            'threshold' => $this->thresholdPercent,
            'workspace_id' => $this->workspaceId,
        ];
    }

    private function localeFor(object $notifiable): string
    {
        // Use the User model's HasLocalePreference contract so locale
        // resolution is centralised and matches what Laravel's mail pipeline
        // would pick up on its own.
        if (method_exists($notifiable, 'preferredLocale')) {
            $locale = $notifiable->preferredLocale();
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }
        }

        return 'en';
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return number_format($value, $i === 0 ? 0 : 1).' '.$units[$i];
    }
}
