<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Notifications\Concerns;

use App\Domains\Notifications\Models\EmailTemplate;
use Illuminate\Notifications\Messages\MailMessage;

trait UsesEmailTemplate
{
    /**
     * Build a MailMessage from a stored email template, resolved in the
     * notifiable's preferred locale (falling back to English).
     *
     * @param  array<string, string>  $extraData
     */
    protected function renderTemplate(string $slug, object $notifiable, array $extraData = []): MailMessage
    {
        $locale = 'en';

        if (method_exists($notifiable, 'settings')) {
            $locale = $notifiable->settings()->resolved()['locale'] ?? 'en';
        }

        $template = EmailTemplate::forSlug($slug, $locale);

        $data = array_merge([
            'user_name' => $notifiable->first_name ?? '',
            'user_full_name' => method_exists($notifiable, 'fullName') ? $notifiable->fullName() : '',
            'user_email' => $notifiable->email ?? '',
        ], $extraData);

        if (! $template || ! $template->compiled_html) {
            // Graceful fallback when templates haven't been seeded or compiled.
            return (new MailMessage)
                ->subject($slug)
                ->line(implode(' ', $extraData));
        }

        return (new MailMessage)
            ->subject($template->interpolateSubject($data))
            ->view('mail.template', [
                'htmlContent' => $template->render($data),
            ]);
    }
}
