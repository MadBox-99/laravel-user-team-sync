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
use Throwable;

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
        // IdentitySession::exists() is the rollout guard: while the pilot runs,
        // the same app still signs users in through the legacy password form,
        // and such a session has no tokens to revalidate. Treating it as an
        // expired SSO session would log every non-pilot user out. It also
        // covers Auth::login(..., remember: true): the recaller re-authenticates
        // into a brand new session, which must not be bounced on sight.
        if (! Auth::check() || ! IdentitySession::exists() || $this->isFresh()) {
            return $next($request);
        }

        // Only two things may end a session here: the provider saying no, and
        // a conflict that cannot be reconciled. Everything else — an outage, a
        // malformed payload, an outright bug — is tolerated, because this
        // middleware runs on every authenticated page of every module app and
        // an escaping exception is a fleet-wide 500.
        try {
            $claims = $this->fetchClaims();

            /** @var array<int, string> $apps */
            $apps = $claims['apps'] ?? [];

            if (! in_array((string) config('user-team-sync.client.app_key'), $apps, true)) {
                return $this->logout($request);
            }

            $this->provisioner->provision($claims);
        } catch (IdentityRejectedException) {
            // The provider answered, and the answer was no.
            return $this->logout($request);
        } catch (IdentityConflictException $exception) {
            Log::warning('user-team-sync: identity conflict during revalidation.', [
                'message' => $exception->getMessage(),
            ]);

            return $this->logout($request);
        } catch (IdentityUnavailableException $exception) {
            return $this->tolerateOutage($request, $next, $exception);
        } catch (Throwable $exception) {
            Log::error('user-team-sync: unexpected failure during identity revalidation.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->tolerateOutage($request, $next, $exception);
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

    private function tolerateOutage(Request $request, Closure $next, Throwable $exception): Response
    {
        IdentitySession::startGrace();

        $graceStartedAt = IdentitySession::graceStartedAt();
        $hours = (int) config('user-team-sync.client.grace_hours', 24);

        if ($graceStartedAt !== null && $graceStartedAt->lessThan(Carbon::now()->subHours($hours))) {
            Log::warning('user-team-sync: grace period expired while the identity provider was unreachable.', [
                'message' => $exception->getMessage(),
            ]);

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
