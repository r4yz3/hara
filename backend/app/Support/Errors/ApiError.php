<?php

namespace App\Support\Errors;

use RuntimeException;

/**
 * An error with a deliberate, safe-to-show code and message.
 */
final class ApiError extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self(401, 'AUTH_REQUIRED', 'These credentials do not match our records.');
    }

    public static function forbidden(string $message = 'You are not allowed to do that.'): self
    {
        return new self(403, 'FORBIDDEN', $message);
    }

    public static function notFound(): self
    {
        return new self(404, 'NOT_FOUND', 'Not found.');
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(429, 'RATE_LIMITED', 'Too many attempts. Wait a moment and try again.', ['Retry-After' => (string) max(1, $retryAfterSeconds)]);
    }
}
