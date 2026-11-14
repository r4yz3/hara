<?php

namespace App\Domain\Identity;

/**
 * The secret in an invitation link. 256 random bits; only the SHA-256 is
 * stored, so a leaked table cannot be used to accept anything. A fast hash is
 * right because the token is not guessable, unlike a password.
 */
final class InvitationToken
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
