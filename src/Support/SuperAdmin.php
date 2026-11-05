<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Support;

use Madbox99\UserTeamSync\Concerns\HasSuperAdmin;

/**
 * Helpers around the HasSuperAdmin trait that do not need a user instance of a
 * class using it (calling a static trait method on the trait itself is
 * deprecated since PHP 8.1).
 */
final class SuperAdmin
{
    public static function usesTrait(object $user): bool
    {
        return in_array(HasSuperAdmin::class, class_uses_recursive($user), true);
    }

    public static function is(?object $user): bool
    {
        return $user !== null && self::usesTrait($user) && (bool) $user->isSuperAdmin();
    }
}
