<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TemplateMail extends Mailable
{
    public function __construct(
        private string $mailSubject,
        private string $htmlContent,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.template',
            with: ['htmlContent' => $this->htmlContent],
        );
    }
}
