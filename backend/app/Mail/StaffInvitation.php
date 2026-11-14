<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Plain-text invitation with a single-use link into the admin app. The token
 * is in the URL fragment, never the query string.
 */
class StaffInvitation extends Mailable
{
    public function __construct(
        public readonly string $url,
        public readonly string $token,
        public readonly string $inviteeName,
        public readonly string $roleLabel,
        public readonly string $scopeLabel,
        public readonly int $hoursValid,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You have been invited to join the admin team');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.staff-invitation-text');
    }
}
