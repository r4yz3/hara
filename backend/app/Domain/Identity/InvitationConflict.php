<?php

namespace App\Domain\Identity;

use DomainException;

/** A live invitation for this address, role and scope already exists. */
final class InvitationConflict extends DomainException {}
