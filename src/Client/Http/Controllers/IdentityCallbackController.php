<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Client\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Madbox99\UserTeamSync\Client\Exceptions\IdentityConflictException;
use Madbox99\UserTeamSync\Client\Exceptions\IdentityRejectedException;
use Madbox99\UserTeamSync\Client\Exceptions\IdentityUnavailableException;
use Madbox99\UserTeamSync\Client\IdentityClient;
use Madbox99\UserTeamSync\Client\IdentityProvisioner;
use Madbox99\UserTeamSync\Client\IdentitySession;

final class IdentityCallbackController
{
    public function __invoke(
        Request $request,
        IdentityClient $client,
        IdentityProvisioner $provisioner,
    ): RedirectResponse|View|Response {
        $expectedState = Session::get(IdentitySession::STATE);
        $verifier = Session::get(IdentitySession::CODE_VERIFIER);

        // A callback that does not match a handshake this session started is
        // either a forged login or a stale tab. Both get the same answer as
        // "not on the allowlist" below — see refused() — so an attacker can
        // never tell the two apart from the response alone.
        if (! is_string($expectedState) || ! is_string($verifier)
            || ! hash_equals($expectedState, (string) $request->string('state'))
        ) {
            IdentitySession::forgetHandshake();

            return $this->refused();
        }

        try {
            $tokens = $client->exchangeCode((string) $request->string('code'), $verifier);
            $claims = $client->fetchClaims($tokens['access_token']);
        } catch (IdentityUnavailableException) {
            // The provider is unreachable or erroring, not refusing. Nothing
            // about this handshake was proven forged, but the code is single
            // use regardless, so there is nothing left to salvage from it.
            IdentitySession::forgetHandshake();

            return $this->unavailable();
        } catch (IdentityRejectedException) {
            IdentitySession::forgetHandshake();

            return $this->rejected();
        }

        if (! $this->isAllowlisted((string) $claims['email'])) {
            IdentitySession::forgetHandshake();

            return $this->refused();
        }

        try {
            $user = $provisioner->provision($claims);
        } catch (IdentityConflictException) {
            IdentitySession::forgetHandshake();

            return $this->conflict();
        }

        // Captured before forgetHandshake() clears it below — belt and
        // braces: re-validate even though IdentityRedirectController already
        // filtered on the way in, so a session poisoned by any other means
        // still cannot bounce the user off this app.
        $intended = $this->safeIntended();

        IdentitySession::forgetHandshake();

        /** @var array<int, string> $apps */
        $apps = $claims['apps'] ?? [];

        if (! in_array((string) config('user-team-sync.client.app_key'), $apps, true)) {
            // Authentication succeeded, entitlement did not. The account is
            // already provisioned, so buying the module is all that is left.
            return view('user-team-sync::identity.not-entitled', [
                'subscribeUrl' => (string) config('user-team-sync.client.subscribe_url'),
            ]);
        }

        Session::regenerate();

        // Deliberately no remember-me. A recaller cookie outlives the session
        // and re-authenticates into a fresh one carrying no identity.* keys at
        // all, which RevalidateIdentity would then wave straight through —
        // permanently, for any user who idles past SESSION_LIFETIME. That
        // would exempt them from entitlement revocation, role and team changes
        // and the whole grace/logout machinery. Re-authenticating through the
        // identity provider instead is transparent for a still-signed-in user:
        // a first-party client skips the consent screen.
        Auth::login($user);

        IdentitySession::putTokens($tokens);
        IdentitySession::markChecked();
        IdentitySession::clearGrace();

        return redirect()->to($intended ?? '/');
    }

    private function safeIntended(): ?string
    {
        $intended = Session::get(IdentitySession::INTENDED);

        if (! is_string($intended) || $intended === '') {
            return null;
        }

        return $this->isSafeIntended($intended) ? $intended : null;
    }

    private function isSafeIntended(string $path): bool
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//');
    }

    private function isAllowlisted(string $email): bool
    {
        /** @var array<int, string> $allowlist */
        $allowlist = config('user-team-sync.client.allowlist', []);

        if ($allowlist === []) {
            return true;
        }

        foreach ($allowlist as $allowed) {
            if (mb_strtolower($allowed) === mb_strtolower($email)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A forged/stale state and an account not (yet) on the allowlist share
     * this exact response — status, body and headers — on purpose. Telling
     * them apart would hand an attacker a way to probe which e-mail
     * addresses are allowlisted by watching how a callback is refused.
     */
    private function refused(): Response
    {
        return response()->view('user-team-sync::identity.refused', [
            'loginUrl' => (string) config('user-team-sync.client.login_url'),
        ], 403);
    }

    /**
     * The identity provider is unreachable or erroring (5xx, connection
     * failure, timeout, malformed response). Transient by nature, so 503
     * Service Unavailable — distinct from a refusal — is the honest code for
     * monitoring to alert on separately from IdentityRejectedException below.
     */
    private function unavailable(): Response
    {
        return response()->view('user-team-sync::identity.unavailable', [
            'loginUrl' => (string) config('user-team-sync.client.login_url'),
        ], 503);
    }

    /**
     * The provider answered and the answer was no (a code that cannot be
     * exchanged, a rejected token). This is an authentication failure, not an
     * outage and not an authorization refusal, hence 401 rather than 503 or
     * 403.
     */
    private function rejected(): Response
    {
        return response()->view('user-team-sync::identity.rejected', [
            'loginUrl' => (string) config('user-team-sync.client.login_url'),
        ], 401);
    }

    /**
     * The claims cannot be reconciled with local data without destroying
     * information (e.g. an e-mail already held by a different local
     * identity). 409 Conflict is the literal HTTP semantics for "this
     * request conflicts with the current state of the target resource".
     * Never renders the colliding e-mail address or any other claim data —
     * the user cannot fix this themselves, only support can.
     */
    private function conflict(): Response
    {
        return response()->view('user-team-sync::identity.conflict', [], 409);
    }
}
