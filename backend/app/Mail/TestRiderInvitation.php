<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Plain-text set-password mail for a test rider (ADR-021). The token is in the URL fragment. */
class TestRiderInvitation extends Mailable
{
    public function __construct(
        public readonly string $url,
        public readonly string $token,
        public readonly int $minutesValid,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Choose a password for the test rider account');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.test-rider-invitation-text');
    }
}
