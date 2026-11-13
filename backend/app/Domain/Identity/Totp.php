<?php

namespace App\Domain\Identity;

use InvalidArgumentException;

/**
 * RFC 6238 time-based one-time passwords: HMAC-SHA1, 30-second step, 6 digits
 * (what every authenticator app supports). Pure functions; the caller keeps
 * the secret encrypted and remembers the last accepted step.
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new secret: 20 random bytes (the SHA-1 block-safe size), base32 without padding. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function stepAt(int $unixTime): int
    {
        return intdiv($unixTime, self::PERIOD);
    }

    public static function code(string $base32Secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($base32Secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % 1_000_000), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The accepted step for a submitted code, or null. Only the previous,
     * current and next step count, and never one at or before $lastUsedStep,
     * so a code cannot be used twice.
     */
    public static function match(string $base32Secret, string $submitted, int $unixTime, ?int $lastUsedStep): ?int
    {
        if (preg_match('/\A[0-9]{6}\z/', $submitted) !== 1) {
            return null;
        }

        $current = self::stepAt($unixTime);
        $matched = null;

        // Every candidate is compared, so timing does not reveal which one matched.
        foreach ([$current - 1, $current, $current + 1] as $step) {
            $equal = hash_equals(self::code($base32Secret, $step), $submitted);

            if ($equal && ($lastUsedStep === null || $step > $lastUsedStep)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    public static function provisioningUri(string $base32Secret, string $issuer, string $account): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account)
            .'?secret='.$base32Secret
            .'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private static function base32Decode(string $base32): string
    {
        $bits = '';
        foreach (str_split(rtrim(strtoupper($base32), '=')) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                throw new InvalidArgumentException('Not a base32 secret.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
