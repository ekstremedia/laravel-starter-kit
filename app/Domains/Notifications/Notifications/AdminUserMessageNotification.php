<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications;

use App\Domains\Notifications\Models\MailLayout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A free-form message an admin sends to selected users from /admin/users.
 * Rendered through the app's mail branding (MailLayout) so it matches the
 * rest of the system's email. Mail-only — it isn't an in-app alert.
 */
class AdminUserMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $subjectLine,
        public string $body,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subjectLine)
            ->view('mail.admin-message', [
                'layout' => MailLayout::current(),
                'subjectLine' => $this->subjectLine,
                // Author-entered plain text → escaped, with newlines preserved.
                'bodyHtml' => nl2br(e($this->body)),
                'appName' => (string) config('app.name'),
            ]);
    }
}
