<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Filament;

use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Madbox99\UserTeamSync\Concerns\HasSuperAdmin;

/**
 * User form field for the global super admin flag (see {@see HasSuperAdmin}).
 *
 * Only a super admin sees it, nobody can change their own flag (no accidental
 * self-lockout), and it is written with forceFill on save, so the column can
 * stay out of the model's $fillable. Requires filament/forms.
 */
class SuperAdminToggle extends Toggle
{
    public static function make(?string $name = null): static
    {
        return parent::make($name ?? (string) config('user-team-sync.super_admin.column', 'is_super_admin'));
    }

    public static function canManage(): bool
    {
        $user = Auth::user();

        return $user !== null
            && HasSuperAdmin::usesSuperAdmin($user)
            && $user->isSuperAdmin();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Super admin'))
            ->helperText(fn (?Model $record): string => $record?->is(Auth::user())
                ? __('You cannot change your own super admin flag.')
                : __('Global administrator: sees and manages every organization. Only super admins can change it.'))
            ->visible(fn (): bool => static::canManage())
            ->disabled(fn (?Model $record): bool => $record?->is(Auth::user()) ?? false)
            ->dehydrated(false)
            ->saveRelationshipsUsing(function (Model $record, ?bool $state): void {
                if (! static::canManage() || $record->is(Auth::user()) || ! HasSuperAdmin::usesSuperAdmin($record)) {
                    return;
                }

                $record->setSuperAdmin((bool) $state);
            });
    }
}
