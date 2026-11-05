<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * A global, tenant-independent super admin stored in a plain boolean column
 * (`user-team-sync.super_admin.column`, default `is_super_admin`).
 *
 * The flag is local to each app: the SSO sync never sends, grants or removes
 * it, and it is deliberately not mass assignable — it changes only through
 * {@see setSuperAdmin()} (the `user-team-sync:super-admin` command or the
 * Filament SuperAdminToggle, which only an existing super admin sees).
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasSuperAdmin
{
    public static function superAdminColumn(): string
    {
        return (string) config('user-team-sync.super_admin.column', 'is_super_admin');
    }

    public function initializeHasSuperAdmin(): void
    {
        $this->mergeCasts([static::superAdminColumn() => 'boolean']);
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->getAttribute(static::superAdminColumn());
    }

    public function setSuperAdmin(bool $isSuperAdmin): static
    {
        $this->forceFill([static::superAdminColumn() => $isSuperAdmin])->save();

        return $this;
    }

    public function grantSuperAdmin(): static
    {
        return $this->setSuperAdmin(true);
    }

    public function revokeSuperAdmin(): static
    {
        return $this->setSuperAdmin(false);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSuperAdmins(Builder $query): Builder
    {
        return $query->where(static::superAdminColumn(), true);
    }

    public static function usesSuperAdmin(object $user): bool
    {
        return in_array(HasSuperAdmin::class, class_uses_recursive($user), true);
    }
}
