<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Filament;

use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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

    /**
     * Form state key (next to the field) remembering that the toggle was flipped.
     */
    protected static function touchedStatePath(): string
    {
        return '_super_admin_touched';
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
            ->live()
            // Only a toggle the editor actually flipped is written. A form loaded
            // before someone else changed the flag would otherwise write its stale
            // value back on any unrelated save (e.g. a name change).
            ->afterStateUpdated(fn (Set $set): mixed => $set(static::touchedStatePath(), true))
            ->saveRelationshipsUsing(function (Model $record, ?bool $state, Get $get): void {
                if (! $get(static::touchedStatePath())) {
                    return;
                }

                if (! static::canManage() || $record->is(Auth::user()) || ! HasSuperAdmin::usesSuperAdmin($record)) {
                    return;
                }

                $record->setSuperAdmin((bool) $state);
            });
    }
}
