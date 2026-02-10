<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    // Create a temporary .env file for testing
    $this->envPath = $this->app->basePath('.env');
    File::put($this->envPath, "APP_NAME=TestApp\n");
});

afterEach(function (): void {
    if (File::exists($this->envPath)) {
        File::delete($this->envPath);
    }

    // Clean up published config
    $configPath = config_path('user-team-sync.php');
    if (File::exists($configPath)) {
        File::delete($configPath);
    }
});

it('is registered as artisan command', function (): void {
    $this->artisan('user-team-sync:install --help')
        ->assertSuccessful();
});

it('runs full install flow', function (): void {
    $this->artisan('user-team-sync:install')
        ->expectsQuestion('Which mode should this app run in?', 'receiver')
        ->expectsConfirmation('Generate a new API key?', 'yes')
        ->expectsConfirmation('Run migrations now?', 'no')
        ->assertSuccessful();

    $env = File::get($this->envPath);
    expect($env)->toContain('USER_TEAM_SYNC_MODE=receiver')
        ->and($env)->toContain('USER_TEAM_SYNC_API_KEY=');
});

it('allows publisher mode selection', function (): void {
    $this->artisan('user-team-sync:install')
        ->expectsQuestion('Which mode should this app run in?', 'publisher')
        ->expectsConfirmation('Generate a new API key?', 'yes')
        ->expectsConfirmation('Run migrations now?', 'no')
        ->assertSuccessful();

    $env = File::get($this->envPath);
    expect($env)->toContain('USER_TEAM_SYNC_MODE=publisher');
});

it('allows custom api key', function (): void {
    $this->artisan('user-team-sync:install')
        ->expectsQuestion('Which mode should this app run in?', 'receiver')
        ->expectsConfirmation('Generate a new API key?', 'no')
        ->expectsQuestion('Enter your API key', 'my-custom-key')
        ->expectsConfirmation('Run migrations now?', 'no')
        ->assertSuccessful();

    $env = File::get($this->envPath);
    expect($env)->toContain('USER_TEAM_SYNC_API_KEY=my-custom-key');
});

it('updates existing env variables instead of duplicating', function (): void {
    File::put($this->envPath, "APP_NAME=TestApp\nUSER_TEAM_SYNC_MODE=receiver\nUSER_TEAM_SYNC_API_KEY=old-key\n");

    $this->artisan('user-team-sync:install')
        ->expectsQuestion('Which mode should this app run in?', 'publisher')
        ->expectsConfirmation('Generate a new API key?', 'no')
        ->expectsQuestion('Enter your API key', 'new-key')
        ->expectsConfirmation('Run migrations now?', 'no')
        ->assertSuccessful();

    $env = File::get($this->envPath);
    expect($env)->toContain('USER_TEAM_SYNC_MODE=publisher')
        ->and($env)->toContain('USER_TEAM_SYNC_API_KEY=new-key')
        ->and($env)->not->toContain('old-key')
        ->and($env)->not->toContain('USER_TEAM_SYNC_MODE=receiver');
});

it('publishes config file', function (): void {
    $this->artisan('user-team-sync:install')
        ->expectsQuestion('Which mode should this app run in?', 'receiver')
        ->expectsConfirmation('Generate a new API key?', 'yes')
        ->expectsConfirmation('Run migrations now?', 'no')
        ->assertSuccessful();

    expect(File::exists(config_path('user-team-sync.php')))->toBeTrue();
});

it('can run migrations', function (): void {
    $this->artisan('user-team-sync:install')
        ->expectsQuestion('Which mode should this app run in?', 'receiver')
        ->expectsConfirmation('Generate a new API key?', 'yes')
        ->expectsConfirmation('Run migrations now?', 'yes')
        ->assertSuccessful();
});
