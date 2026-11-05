<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Madbox99\UserTeamSync\Concerns\HasSuperAdmin;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Grants or revokes the global super admin flag. The first super admin of an
 * app can only be created here; afterwards super admins can also toggle it on
 * the Filament user form (SuperAdminToggle).
 */
#[AsCommand(name: 'user-team-sync:super-admin')]
final class SuperAdminCommand extends Command
{
    protected $signature = 'user-team-sync:super-admin
                            {email : The user\'s email address}
                            {--revoke : Remove the super admin flag instead}';

    protected $description = 'Grant or revoke the global super admin flag of a user';

    public function handle(): int
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('user-team-sync.models.user');

        if (! HasSuperAdmin::usesSuperAdmin(new $userModel)) {
            $this->components->error("{$userModel} does not use the ".HasSuperAdmin::class.' trait.');

            return self::FAILURE;
        }

        /** @var Model|null $user */
        $user = $userModel::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error("No user found with the email address {$this->argument('email')}.");

            return self::FAILURE;
        }

        $isSuperAdmin = ! $this->option('revoke');
        $user->setSuperAdmin($isSuperAdmin);

        $this->components->info($isSuperAdmin
            ? "{$user->email} is now a super admin."
            : "{$user->email} is no longer a super admin.");

        return self::SUCCESS;
    }
}
