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
        $column = static::superAdminColumn();

        $this->mergeCasts([$column => 'boolean']);

        // A new, unsaved user is not a super admin. Without this default an app
        // running Model::preventAccessingMissingAttributes() (strict mode) throws
        // on isSuperAdmin() before the row has been read back.
        if (! array_key_exists($column, $this->attributes)) {
            $this->attributes[$column] = false;
        }
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

    /**
     * @deprecated Use \Madbox99\UserTeamSync\Support\SuperAdmin::usesTrait(); calling a
     *             static method on the trait itself is deprecated since PHP 8.1.
     */
    public static function usesSuperAdmin(object $user): bool
    {
        return \Madbox99\UserTeamSync\Support\SuperAdmin::usesTrait($user);
    }
}
