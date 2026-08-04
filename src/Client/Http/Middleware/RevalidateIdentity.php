<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Client\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Madbox99\UserTeamSync\Client\Exceptions\IdentityConflictException;
use Madbox99\UserTeamSync\Client\Exceptions\IdentityRejectedException;
use Madbox99\UserTeamSync\Client\Exceptions\IdentityUnavailableException;
use Madbox99\UserTeamSync\Client\IdentityClient;
use Madbox99\UserTeamSync\Client\IdentityProvisioner;
use Madbox99\UserTeamSync\Client\IdentitySession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-fetches the claims and re-runs the provisioner when the session's last
 * check has gone stale. This is what makes the fleet self-healing: a rename, a
 * new membership or a cancelled subscription arrives without anyone pushing it.
 */
final class RevalidateIdentity
{
    public function __construct(
        private readonly IdentityClient $client,
        private readonly IdentityProvisioner $provisioner,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check() || $this->isFresh()) {
            return $next($request);
        }

        try {
            $claims = $this->fetchClaims();
        } catch (IdentityRejectedException) {
            // The provider answered, and the answer was no.
            return $this->logout($request);
        } catch (IdentityUnavailableException $exception) {
            return $this->tolerateOutage($request, $next, $exception);
        }

        /** @var array<int, string> $apps */
        $apps = $claims['apps'] ?? [];

        if (! in_array((string) config('user-team-sync.client.app_key'), $apps, true)) {
            return $this->logout($request);
        }

        try {
            $this->provisioner->provision($claims);
        } catch (IdentityConflictException $exception) {
            Log::warning('user-team-sync: identity conflict during revalidation.', [
                'message' => $exception->getMessage(),
            ]);

            return $this->logout($request);
        }

        IdentitySession::markChecked();
        IdentitySession::clearGrace();

        return $next($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchClaims(): array
    {
        $accessToken = IdentitySession::accessToken();

        if ($accessToken === null) {
            throw new IdentityRejectedException('No access token in the session.');
        }

        try {
            return $this->client->fetchClaims($accessToken);
        } catch (IdentityRejectedException $exception) {
            $refreshToken = IdentitySession::refreshToken();

            if ($refreshToken === null) {
                throw $exception;
            }

            // A 401 usually just means the access token aged out; only a
            // refresh that also fails proves the access was taken away.
            $tokens = $this->client->refresh($refreshToken);

            IdentitySession::putTokens($tokens);

            return $this->client->fetchClaims($tokens['access_token']);
        }
    }

    private function isFresh(): bool
    {
        $checkedAt = IdentitySession::checkedAt();

        if ($checkedAt === null) {
            return false;
        }

        $minutes = (int) config('user-team-sync.client.revalidate_after_minutes', 15);

        return $checkedAt->greaterThan(Carbon::now()->subMinutes($minutes));
    }

    private function tolerateOutage(Request $request, Closure $next, IdentityUnavailableException $exception): Response
    {
        IdentitySession::startGrace();

        $graceStartedAt = IdentitySession::graceStartedAt();
        $hours = (int) config('user-team-sync.client.grace_hours', 24);

        if ($graceStartedAt !== null && $graceStartedAt->lessThan(Carbon::now()->subHours($hours))) {
            Log::warning('user-team-sync: grace period expired while the identity provider was unreachable.');

            return $this->logout($request);
        }

        // The session carries on and the next request tries again.
        return $next($request);
    }

    private function logout(Request $request): Response
    {
        Auth::logout();

        IdentitySession::forget();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to('/');
    }
}
