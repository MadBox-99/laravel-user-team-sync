<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Client\Exceptions;

use RuntimeException;

/**
 * The identity provider could not be reached or answered with a server error.
 * This is explicitly NOT the same as access being revoked: an existing session
 * survives it for the configured grace period.
 */
final class IdentityUnavailableException extends RuntimeException {}
