<?php

namespace App\Support\Http;

/**
 * Opaque keyset cursor over a ULID primary key (ADR-010). Clients must treat
 * it as a token; the contents may change without notice.
 */
final class Cursor
{
    public static function encode(string $lastId): string
    {
        return rtrim(strtr(base64_encode('id:'.$lastId), '+/', '-_'), '=');
    }

    /** @return string|null the id to continue after, or null if the cursor is not one of ours */
    public static function decode(string $cursor): ?string
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || preg_match('/\Aid:([0-9a-z]{26})\z/', $decoded, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
