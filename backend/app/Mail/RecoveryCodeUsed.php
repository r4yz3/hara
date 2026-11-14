<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Plain-text alert that a two-step recovery code signed in (ADR-011). It holds
 * no code, no token, no address of the device and no link. Sent synchronously,
 * like the other mail, until queue workers exist.
 */
class RecoveryCodeUsed extends Mailable
{
    public const LOW_THRESHOLD = 2;

    public function __construct(
        public readonly string $usedAt,
        public readonly int $remaining,
    ) {}

    public function isLow(): bool
    {
        return $this->remaining <= self::LOW_THRESHOLD;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'A recovery code was used to sign in to your account');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.recovery-code-used-text');
    }
}
