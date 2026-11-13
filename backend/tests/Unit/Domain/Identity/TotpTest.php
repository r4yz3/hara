<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** RFC 6238 appendix B secret for SHA1: ASCII "12345678901234567890". */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return array<string, array{int, string}> */
    public static function rfcVectors(): array
    {
        // The RFC lists 8-digit codes; the last six digits are the 6-digit code.
        return [
            'T=59' => [59, '287082'],
            'T=1111111109' => [1111111109, '081804'],
            'T=1111111111' => [1111111111, '050471'],
            'T=1234567890' => [1234567890, '005924'],
            'T=2000000000' => [2000000000, '279037'],
            'T=20000000000' => [20000000000, '353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_it_matches_the_rfc_6238_sha1_vectors(int $time, string $expected): void
    {
        $this->assertSame($expected, Totp::code(self::RFC_SECRET, Totp::stepAt($time)));
    }

    public function test_the_current_previous_and_next_step_are_accepted(): void
    {
        $now = 1_700_000_015;
        $step = Totp::stepAt($now);

        foreach ([-1, 0, 1] as $offset) {
            $this->assertSame($step + $offset, Totp::match(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + $offset), $now, null));
        }
    }

    public function test_two_steps_either_way_are_refused(): void
    {
        $now = 1_700_000_015;
        $step = Totp::stepAt($now);

        foreach ([-2, 2] as $offset) {
            $this->assertNull(Totp::match(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + $offset), $now, null));
        }
    }

    public function test_a_step_at_or_before_the_last_used_one_is_refused(): void
    {
        $now = 1_700_000_015;
        $step = Totp::stepAt($now);
        $code = Totp::code(self::RFC_SECRET, $step);

        $this->assertNull(Totp::match(self::RFC_SECRET, $code, $now, $step));
        $this->assertNull(Totp::match(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 1), $now, $step));
        $this->assertSame($step, Totp::match(self::RFC_SECRET, $code, $now, $step - 1));
    }

    public function test_malformed_input_is_refused(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', '12 456', "28708\n"] as $input) {
            $this->assertNull(Totp::match(self::RFC_SECRET, $input, 59, null));
        }
    }

    public function test_a_generated_secret_is_20_random_bytes_in_base32(): void
    {
        $secret = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotSame($secret, Totp::generateSecret());
    }

    public function test_the_provisioning_uri_carries_issuer_and_label(): void
    {
        $uri = Totp::provisioningUri(self::RFC_SECRET, 'Food Delivery', 'staff@example.com');

        $this->assertSame(
            'otpauth://totp/Food%20Delivery:staff%40example.com?secret='.self::RFC_SECRET.'&issuer=Food%20Delivery&algorithm=SHA1&digits=6&period=30',
            $uri,
        );
    }
}
