<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Stands in for a host User that uses Spatie's HasRoles, without pulling
 * spatie/laravel-permission into the package's dev dependencies: the roles
 * live comma-separated in the users.role column.
 */
class SpatieUser extends User
{
    protected $table = 'users';

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user', 'user_id');
    }

    /**
     * @return Collection<int, string>
     */
    public function getRoleNames(): Collection
    {
        return collect(explode(',', (string) $this->getAttribute('role')))->filter()->values();
    }

    public function syncRoles(mixed ...$roles): static
    {
        $this->forceFill(['role' => implode(',', collect($roles)->flatten()->all())])->save();

        return $this;
    }

    public function assignRole(mixed ...$roles): static
    {
        return $this->syncRoles([...$this->getRoleNames()->all(), ...collect($roles)->flatten()->all()]);
    }
}
