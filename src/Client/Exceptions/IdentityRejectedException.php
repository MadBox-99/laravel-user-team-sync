<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Client\Exceptions;

use RuntimeException;

/**
 * The identity provider answered, and the answer was no — an expired or
 * revoked token, or a code that cannot be exchanged. The session ends
 * immediately.
 */
final class IdentityRejectedException extends RuntimeException {}
