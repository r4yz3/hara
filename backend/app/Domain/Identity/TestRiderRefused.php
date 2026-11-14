<?php

namespace App\Domain\Identity;

use RuntimeException;

/** The console command `rider:invite-test` may not act on this address (ADR-021). */
class TestRiderRefused extends RuntimeException
{
    public static function staffAccount(): self
    {
        return new self('That address belongs to an account that is not a test rider (it holds a role or is a customer or merchant user). Use a different address.');
    }

    public static function notATestRider(): self
    {
        return new self('That address is not a test rider created by this command. It will not be touched.');
    }

    public static function suspended(): self
    {
        return new self('That test rider or its user account is suspended. Nothing was changed.');
    }

    public static function rateLimited(int $seconds): self
    {
        return new self("Too many links requested for this address. Try again in {$seconds} seconds.");
    }
}
