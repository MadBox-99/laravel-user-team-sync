<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Console;

use Illuminate\Console\Command;
use Madbox99\UserTeamSync\Models\SyncApp;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[AsCommand(name: 'user-team-sync:install')]
final class InstallCommand extends Command
{
    protected $signature = 'user-team-sync:install
                            {--force : Overwrite existing config and migration files}';

    protected $description = 'Install the User Team Sync package';

    public function handle(): int
    {
        $this->components->info('Installing User Team Sync...');

        $mode = $this->chooseMode();
        $apiKey = $this->chooseApiKey();

        $appSource = in_array($mode, ['publisher', 'both'])
            ? $this->chooseAppSource()
            : null;

        $this->updateEnvFile($mode, $apiKey, $appSource);

        $this->publishConfig();
        $this->publishMigrations();
        $this->runMigrations();

        if ($appSource === 'database' && in_array($mode, ['publisher', 'both'])) {
            $this->addAppsInteractively();
        }

        $this->components->info('User Team Sync installed successfully!');

        if (in_array($mode, ['publisher', 'both']) && $appSource === 'config') {
            $this->components->warn('Configure your receiver apps in config/user-team-sync.php under publisher.apps');
        }

        return self::SUCCESS;
    }

    private function chooseMode(): string
    {
        return select(
            label: 'Which mode should this app run in?',
            options: [
                'receiver' => 'Receiver — This app receives sync events',
                'publisher' => 'Publisher — This app sends sync events',
                'both' => 'Both — This app sends and receives',
            ],
            default: 'receiver',
        );
    }

    private function chooseApiKey(): string
    {
        $generated = bin2hex(random_bytes(32));

        $useGenerated = confirm(
            label: 'Generate a new API key?',
            default: true,
            hint: 'Select "No" to enter an existing key',
        );

        if ($useGenerated) {
            $this->components->info("API Key: {$generated}");

            return $generated;
        }

        return text(
            label: 'Enter your API key',
            required: true,
        );
    }

    private function chooseAppSource(): string
    {
        return select(
            label: 'Where should receiver apps be stored?',
            options: [
                'database' => 'Database — Manage apps dynamically (recommended)',
                'config' => 'Config file — Define apps in config/user-team-sync.php',
            ],
            default: 'database',
        );
    }

    private function addAppsInteractively(): void
    {
        if (! confirm('Add a receiver app now?', default: true)) {
            return;
        }

        do {
            $name = text(label: 'App name (e.g. crm, shop)', required: true);
            $url = text(label: 'App URL (e.g. https://crm.example.com)', required: true);
            $appApiKey = text(label: 'App API key (leave empty to use default)', default: '');

            SyncApp::query()->create([
                'name' => $name,
                'url' => $url,
                'api_key' => $appApiKey !== '' ? $appApiKey : null,
                'is_active' => true,
            ]);

            $this->components->info("App '{$name}' added.");
        } while (confirm('Add another app?', default: false));
    }

    private function updateEnvFile(string $mode, string $apiKey, ?string $appSource = null): void
    {
        $envPath = $this->laravel->basePath('.env');

        if (! file_exists($envPath)) {
            return;
        }

        $env = file_get_contents($envPath);

        $variables = [
            'USER_TEAM_SYNC_MODE' => $mode,
            'USER_TEAM_SYNC_API_KEY' => $apiKey,
        ];

        if ($appSource !== null) {
            $variables['USER_TEAM_SYNC_APP_SOURCE'] = $appSource;
        }

        $additions = [];

        foreach ($variables as $key => $value) {
            if (str_contains($env, "{$key}=")) {
                $env = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $env);
            } else {
                $additions[] = "{$key}={$value}";
            }
        }

        if ($additions !== []) {
            $env = rtrim($env, "\n") . "\n\n" . implode("\n", $additions) . "\n";
        }

        file_put_contents($envPath, $env);

        $this->components->info('.env file updated.');
    }

    private function publishConfig(): void
    {
        $this->callSilently('vendor:publish', [
            '--tag' => 'user-team-sync-config',
            '--force' => $this->option('force'),
        ]);

        $this->components->task('Config file published');
    }

    private function publishMigrations(): void
    {
        $this->callSilently('vendor:publish', [
            '--tag' => 'user-team-sync-migrations',
            '--force' => $this->option('force'),
        ]);

        $this->components->task('Migration file published');
    }

    private function runMigrations(): void
    {
        if (! confirm('Run migrations now?', default: true)) {
            return;
        }

        $this->callSilently('migrate');

        $this->components->task('Migrations executed');
    }
}
