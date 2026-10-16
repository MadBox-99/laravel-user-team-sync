<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Publisher;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Madbox99\UserTeamSync\Models\SyncApp;
use Madbox99\UserTeamSync\Publisher\Jobs\CreateTeamJob;
use Madbox99\UserTeamSync\Publisher\Jobs\CreateUserJob;
use Madbox99\UserTeamSync\Publisher\Jobs\RemoveTeamMemberJob;
use Madbox99\UserTeamSync\Publisher\Jobs\SyncPasswordJob;
use Madbox99\UserTeamSync\Publisher\Jobs\SyncUserJob;
use Madbox99\UserTeamSync\Publisher\Jobs\ToggleUserActiveJob;
use Madbox99\UserTeamSync\Publisher\Jobs\UpdateTeamJob;

final class PublisherService
{
    private function usesDatabase(): bool
    {
        return config('user-team-sync.publisher.app_source') === 'database';
    }

    /**
     * @return array<string, array{url: string, api_key: ?string, active: bool}>
     */
    public function getApps(): array
    {
        if ($this->usesDatabase()) {
            return $this->toAppArrays(SyncApp::all());
        }

        return config('user-team-sync.publisher.apps', []);
    }

    /**
     * @return array<string, array{url: string, api_key: ?string, active: bool}>
     */
    public function getActiveApps(): array
    {
        if ($this->usesDatabase()) {
            return $this->toAppArrays(SyncApp::where('is_active', true)->get());
        }

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
        if ($this->usesDatabase()) {
            $app = SyncApp::where('name', $appName)->first();

            return $app instanceof SyncApp ? $this->toAppArrayOrSkip($app) : null;
        }

        return $this->getApps()[$appName] ?? null;
    }

    /**
     * @param  Collection<int, SyncApp>  $apps
     * @return array<string, array{url: string, api_key: ?string, active: bool}>
     */
    private function toAppArrays(Collection $apps): array
    {
        $arrays = [];

        foreach ($apps as $app) {
            $array = $this->toAppArrayOrSkip($app);

            if ($array !== null) {
                $arrays[$app->name] = $array;
            }
        }

        return $arrays;
    }

    /**
     * An api_key that cannot be decrypted (typically written to sync_apps
     * unencrypted, bypassing the model's 'encrypted' cast) must take only its
     * own app out of the sync, not throw for every app the job iterates over.
     *
     * @return array{url: string, api_key: ?string, active: bool}|null
     */
    private function toAppArrayOrSkip(SyncApp $app): ?array
    {
        try {
            return $app->toAppArray();
        } catch (DecryptException) {
            Log::error("UserTeamSync: the api_key of sync app [{$app->name}] cannot be decrypted (stored unencrypted, or with another APP_KEY?). The app is skipped until it is saved again through the SyncApp model.");

            return null;
        }
    }

    public function createUser(string $email, string $name, string $password, string $role, string $ownerEmail, ?string $uuid = null): void
    {
        $passwordHash = Hash::isHashed($password) ? $password : Hash::make($password);

        $this->dispatchJob(new CreateUserJob($email, $name, $passwordHash, $role, $ownerEmail, $uuid));
    }

    /**
     * @param  array<string, mixed>  $changedData
     */
    public function syncUser(string $email, array $changedData): void
    {
        $this->dispatchJob(new SyncUserJob($email, $changedData));
    }

    public function syncPassword(string $email, string $password): void
    {
        $passwordHash = Hash::isHashed($password) ? $password : Hash::make($password);

        $this->dispatchJob(new SyncPasswordJob($email, $passwordHash));
    }

    public function createTeam(string $teamName, string $userEmail, ?string $slug = null, ?string $userName = null, ?string $uuid = null): void
    {
        $this->dispatchJob(new CreateTeamJob($teamName, $userEmail, $slug, $userName, $uuid));
    }

    /**
     * @param  array<string, mixed>  $changedData
     */
    public function updateTeam(?string $uuid, string $originalSlug, array $changedData): void
    {
        $this->dispatchJob(new UpdateTeamJob($uuid, $originalSlug, $changedData));
    }

    public function toggleUserActive(string $userEmail, bool $isActive, string $appKey): void
    {
        $this->dispatchJob(new ToggleUserActiveJob($userEmail, $isActive, $appKey));
    }

    /**
     * End a user's membership of a team on one receiver app. The user account
     * on that app is left alone.
     */
    public function removeTeamMember(string $userEmail, string $teamSlug, string $appKey, ?string $userUuid = null, ?string $teamUuid = null): void
    {
        $this->dispatchJob(new RemoveTeamMemberJob($userEmail, $teamSlug, $appKey, $userUuid, $teamUuid));
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

        if (config('user-team-sync.publisher.skip_ssl_for_test_domains') && $this->isTestDomain($app['url'])) {
            $http = $http->withoutVerifying();
        }

        return $http;
    }

    private function isTestDomain(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && str_ends_with($host, '.test');
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
