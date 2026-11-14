<?php

namespace App\Domain\Identity;

use RuntimeException;

/** Internal signal that rolls an acceptance back; never shown to anyone. */
final class InvitationNotAccepted extends RuntimeException {}
