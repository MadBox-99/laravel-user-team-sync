<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Publisher;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Madbox99\UserTeamSync\Publisher\Jobs\CreateTeamJob;
use Madbox99\UserTeamSync\Publisher\Jobs\CreateUserJob;
use Madbox99\UserTeamSync\Publisher\Jobs\SyncUserJob;
use Madbox99\UserTeamSync\Publisher\Jobs\ToggleUserActiveJob;

final class PublisherService
{
    /**
     * @return array<string, array{url: string, api_key: ?string, active: bool}>
     */
    public function getApps(): array
    {
        return config('user-team-sync.publisher.apps', []);
    }

    /**
     * @return array<string, array{url: string, api_key: ?string, active: bool}>
     */
    public function getActiveApps(): array
    {
        return array_filter($this->getApps(), fn (array $app): bool => $app['active'] ?? false);
    }

    public function getApiKey(string $appName): ?string
    {
        $app = $this->getApp($appName);

        if (! $app) {
            return null;
        }

        return $app['api_key'] ?? $this->getDefaultApiKey();
    }

    public function getDefaultApiKey(): ?string
    {
        return config('user-team-sync.publisher.api_key');
    }

    /**
     * @return array{url: string, api_key: ?string, active: bool}|null
     */
    public function getApp(string $appName): ?array
    {
        return $this->getApps()[$appName] ?? null;
    }

    public function createUser(string $email, string $name, string $password, string $role, string $ownerEmail): void
    {
        $this->dispatchJob(new CreateUserJob($email, $name, Hash::make($password), $role, $ownerEmail));
    }

    /**
     * @param  array<string, mixed>  $changedData
     */
    public function syncUser(string $email, array $changedData): void
    {
        $this->dispatchJob(new SyncUserJob($email, $changedData));
    }

    public function createTeam(string $teamName, string $userEmail, ?string $slug = null, ?string $userName = null): void
    {
        $this->dispatchJob(new CreateTeamJob($teamName, $userEmail, $slug, $userName));
    }

    public function toggleUserActive(string $userEmail, bool $isActive, string $appKey): void
    {
        $this->dispatchJob(new ToggleUserActiveJob($userEmail, $isActive, $appKey));
    }

    /**
     * @param  array{url: string, api_key: ?string, active: bool}  $app
     */
    public function makeHttpClient(array $app): PendingRequest
    {
        $apiKey = $app['api_key'] ?? $this->getDefaultApiKey();

        $http = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Accept' => 'application/json',
        ])->timeout(config('user-team-sync.publisher.timeout', 10));

        if (config('user-team-sync.publisher.skip_ssl_for_test_domains')
            && str_ends_with($app['url'], '.test')) {
            $http = $http->withoutVerifying();
        }

        return $http;
    }

    private function dispatchJob(object $job): void
    {
        $connection = config('user-team-sync.publisher.connection');
        $queue = config('user-team-sync.publisher.queue', 'default');

        $pending = dispatch($job)->onQueue($queue);

        if ($connection) {
            $pending->onConnection($connection);
        }
    }
}
