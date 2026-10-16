<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The single place where a sync writes a user's role: the SSO login and every
 * revalidation (IdentityProvisioner) and the legacy receiver's create-user and
 * sync-user endpoints (UserSyncController).
 *
 * 'receiver.local_only_roles' lists roles this app grants by hand (typically
 * 'Super-Admin'). The identity provider never knows about them, so a sync must
 * neither hand one out nor take one away: an incoming local-only role decays to
 * 'receiver.default_role', and a local-only role the user already holds is kept
 * alongside the synced one. Names are compared exactly, in PHP, so a database
 * collation cannot turn some other spelling into a local-only role.
 */
final class RoleWriter
{
    public function usesSpatie(Model $user): bool
    {
        return config('user-team-sync.receiver.role_driver') === 'spatie' && method_exists($user, 'syncRoles');
    }

    /**
     * Replace the user's synced Spatie role, keeping every local-only role the
     * user already holds.
     */
    public function syncSpatieRole(Model $user, string $role): void
    {
        $kept = $user->exists
            ? array_values(array_filter(
                $user->getRoleNames()->all(),
                fn (mixed $name): bool => is_string($name) && $this->isLocalOnly($name),
            ))
            : [];

        $user->syncRoles(array_values(array_unique([$this->permitted($role), ...$kept])));
    }

    /**
     * True when the user's plain role column holds a local-only role, which a
     * sync must then leave untouched.
     */
    public function keepsColumnRole(Model $user): bool
    {
        $current = $user->getAttribute('role');

        return is_string($current) && $this->isLocalOnly($current);
    }

    /**
     * The role a sync may write: a local-only role decays to the default role.
     */
    public function permitted(string $role): string
    {
        return $this->isLocalOnly($role)
            ? (string) config('user-team-sync.receiver.default_role', 'subscriber')
            : $role;
    }

    public function isLocalOnly(string $role): bool
    {
        return in_array($role, $this->localOnlyRoles(), true);
    }

    /**
     * @return array<int, string>
     */
    public function localOnlyRoles(): array
    {
        /** @var array<int, mixed> $roles */
        $roles = (array) config('user-team-sync.receiver.local_only_roles', []);

        return array_values(array_filter($roles, is_string(...)));
    }
}
