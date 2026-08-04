<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Madbox99\UserTeamSync\Client\Exceptions\IdentityConflictException;

/**
 * Turns a claim payload from the identity provider into a local, signed-in-able
 * user. This is the only place in client mode where state is created, and it
 * runs both on login and on every revalidation: it is a reconcile that happens
 * to also be the login path.
 */
final class IdentityProvisioner
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function provision(array $claims): Model
    {
        return DB::transaction(function () use ($claims): Model {
            $user = $this->resolveUser($claims);

            $this->applyRole($user, (string) ($claims['role'] ?? ''));

            return $user;
        });
    }

    /**
     * Resolve the claim's role name onto a role that actually exists in this
     * app. The publisher sends lower-case names while a receiver may capitalise
     * its own; relying on the database collation to bridge that works on MySQL
     * and fails on SQLite, so the match is made in PHP.
     *
     * @param  array<int, string>  $localRoleNames
     */
    public function resolveRoleName(string $claimRole, array $localRoleNames): string
    {
        /** @var array<string, string> $map */
        $map = config('user-team-sync.client.role_map', []);

        if (isset($map[$claimRole])) {
            return $map[$claimRole];
        }

        foreach ($localRoleNames as $name) {
            if (mb_strtolower($name) === mb_strtolower($claimRole)) {
                return $name;
            }
        }

        return (string) config('user-team-sync.receiver.default_role', 'subscriber');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function resolveUser(array $claims): Model
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        $uuid = (string) $claims['sub'];
        $email = (string) $claims['email'];

        $user = $userModel::query()->where('uuid', $uuid)->first();

        if (! $user instanceof Model) {
            $byEmail = $userModel::query()->where('email', $email)->first();

            if ($byEmail instanceof Model) {
                // Adopt a local account that predates the identity layer. A row
                // that already carries a *different* uuid is a genuine identity
                // clash and must not be taken over silently.
                if ($byEmail->getAttribute('uuid') !== null) {
                    throw IdentityConflictException::emailBelongsToAnotherIdentity($email);
                }

                $user = $byEmail;
            } else {
                $user = new $userModel;
            }
        }

        // forceFill, because a receiver's user model is free to leave 'uuid'
        // out of $fillable — crm's does. Mass assignment would drop it and
        // every login would then look like a brand new identity.
        $user->forceFill([
            'uuid' => $uuid,
            'name' => (string) $claims['name'],
            'email' => $email,
            'email_verified_at' => $user->getAttribute('email_verified_at') ?? now(),
            'is_active' => true,
        ]);

        if ($user->exists === false) {
            // Never a valid bcrypt hash, so Hash::check() can never match it.
            // SSO accounts have no password anywhere in the fleet.
            $user->forceFill(['password' => '']);
        }

        $user->save();

        return $user;
    }

    private function applyRole(Model $user, string $claimRole): void
    {
        if ($claimRole === '') {
            return;
        }

        if (config('user-team-sync.receiver.role_driver') === 'spatie' && method_exists($user, 'syncRoles')) {
            /** @var class-string<Model> $roleModel */
            $roleModel = config('permission.models.role', \Spatie\Permission\Models\Role::class);

            /** @var array<int, string> $localRoleNames */
            $localRoleNames = $roleModel::query()->pluck('name')->all();

            $user->syncRoles([$this->resolveRoleName($claimRole, $localRoleNames)]);

            return;
        }

        $user->forceFill(['role' => $claimRole])->save();
    }
}
