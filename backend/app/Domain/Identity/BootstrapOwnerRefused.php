<?php

namespace App\Domain\Identity;

use DomainException;

/** The bootstrap command may not issue a link now. The message is for the operator at the console. */
final class BootstrapOwnerRefused extends DomainException
{
    public static function ownerExists(): self
    {
        return new self('An active platform owner already exists. This command only creates the first one; further staff are invited from the admin app.');
    }

    public static function pendingExists(): self
    {
        return new self('A bootstrap invitation is still pending and its link has not expired. Use the link already issued, or run again with --replace to revoke it and print a new one.');
    }

    public static function roleCatalogueMissing(): self
    {
        return new self('The platform_owner role is not in the database. Run the role catalogue seeder first (db:seed --class=RbacSeeder --force).');
    }
}
