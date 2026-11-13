<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Plain-text email with a single-use link. Sent synchronously for now; it
 * moves to the queue when workers exist.
 */
class PasswordResetLink extends Mailable
{
    public function __construct(
        public readonly string $url,
        public readonly string $token,
        public readonly int $minutesValid,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.password-reset-text');
    }
}
