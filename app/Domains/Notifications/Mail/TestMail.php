<?php

namespace App\Domains\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $note = '') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Starter Kit · Mail Test');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.test',
            with: ['note' => $this->note, 'sentAt' => now()->toDateTimeString()],
        );
    }
}
