<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Client\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The claims describe an identity that cannot be reconciled with what is
 * already in this app's database without destroying information — for
 * instance an e-mail address that belongs to a local user carrying a
 * different uuid. Signing in is refused rather than silently taking the
 * record over.
 */
final class IdentityConflictException extends RuntimeException
{
    public static function emailBelongsToAnotherIdentity(string $email, ?Throwable $previous = null): self
    {
        return new self(
            "The e-mail address [{$email}] already belongs to a local user with a different identity.",
            previous: $previous,
        );
    }
}
