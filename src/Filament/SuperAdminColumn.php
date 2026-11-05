<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Filament;

use Filament\Tables\Columns\IconColumn;

/**
 * Users table column for the global super admin flag, shown to super admins
 * only. Requires filament/tables.
 */
class SuperAdminColumn extends IconColumn
{
    public static function make(?string $name = null): static
    {
        return parent::make($name ?? (string) config('user-team-sync.super_admin.column', 'is_super_admin'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Super admin'))
            ->boolean()
            ->visible(fn (): bool => SuperAdminToggle::canManage());
    }
}
