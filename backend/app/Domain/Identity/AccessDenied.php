<?php

namespace App\Domain\Identity;

use DomainException;

/**
 * Thrown when an actor may not perform an action. `reason` is for logs and
 * tests; it is never shown to the caller, who gets a generic 403 or 404.
 */
final class AccessDenied extends DomainException
{
    public const NOT_PERMITTED = 'not_permitted';

    public const SELF_GRANT = 'self_grant';

    public const ROLE_NOT_ASSIGNABLE = 'role_not_assignable';

    public const SCOPE_NOT_ALLOWED_FOR_ROLE = 'scope_not_allowed_for_role';

    public const SCOPE_TARGET_MISSING = 'scope_target_missing';

    public const UNKNOWN_ROLE = 'unknown_role';

    public const TARGET_INACTIVE = 'target_inactive';

    public const ALREADY_GRANTED = 'already_granted';

    public const GRANT_NOT_FOUND = 'grant_not_found';

    public const SELF_SUSPEND = 'self_suspend';

    public const PROTECTED_TARGET = 'protected_target';

    public const ALREADY_SUSPENDED = 'already_suspended';

    public const NOT_SUSPENDED = 'not_suspended';

    private function __construct(public readonly string $reason)
    {
        parent::__construct("Access denied ({$reason}).");
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
