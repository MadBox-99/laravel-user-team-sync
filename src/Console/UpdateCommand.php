<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'user-team-sync:update')]
final class UpdateCommand extends Command
{
    protected $signature = 'user-team-sync:update
                            {--force : Force run migrations in production}';

    protected $description = 'Run post-update tasks (migrations, cache clear)';

    public function handle(): int
    {
        $this->components->info('Updating User Team Sync...');

        $this->runMigrations();
        $this->clearCaches();

        $this->components->info('User Team Sync updated successfully!');

        return self::SUCCESS;
    }

    private function runMigrations(): void
    {
        $params = [];

        if ($this->option('force')) {
            $params['--force'] = true;
        }

        $this->call('migrate', $params);

        $this->components->task('Migrations executed');
    }

    private function clearCaches(): void
    {
        $this->callSilently('config:clear');
        $this->callSilently('route:clear');

        $this->components->task('Caches cleared');
    }
}
