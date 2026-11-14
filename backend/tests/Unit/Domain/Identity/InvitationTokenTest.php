<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\InvitationToken;
use PHPUnit\Framework\TestCase;

class InvitationTokenTest extends TestCase
{
    public function test_a_token_is_256_random_bits_as_hex(): void
    {
        $token = InvitationToken::generate();

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $token);
    }

    public function test_tokens_do_not_repeat(): void
    {
        $tokens = array_map(fn (): string => InvitationToken::generate(), range(1, 200));

        $this->assertCount(200, array_unique($tokens));
    }

    public function test_the_stored_value_is_the_sha256_of_the_token_and_not_the_token(): void
    {
        $token = InvitationToken::generate();

        $hash = InvitationToken::hash($token);

        $this->assertSame(hash('sha256', $token), $hash);
        $this->assertNotSame($token, $hash);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $hash);
        $this->assertStringNotContainsString($token, $hash);
    }

    public function test_hashing_is_deterministic_and_input_sensitive(): void
    {
        $this->assertSame(InvitationToken::hash('abc'), InvitationToken::hash('abc'));
        $this->assertNotSame(InvitationToken::hash('abc'), InvitationToken::hash('abd'));
        // Known SHA-256 vector.
        $this->assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', InvitationToken::hash('abc'));
    }
}
