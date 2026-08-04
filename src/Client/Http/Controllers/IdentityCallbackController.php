<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Client\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Madbox99\UserTeamSync\Client\IdentityClient;
use Madbox99\UserTeamSync\Client\IdentityProvisioner;
use Madbox99\UserTeamSync\Client\IdentitySession;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class IdentityCallbackController
{
    public function __invoke(
        Request $request,
        IdentityClient $client,
        IdentityProvisioner $provisioner,
    ): RedirectResponse|View {
        $expectedState = Session::get(IdentitySession::STATE);
        $verifier = Session::get(IdentitySession::CODE_VERIFIER);

        // A callback that does not match a handshake this session started is
        // either a forged login or a stale tab. Both get the same answer.
        if (! is_string($expectedState) || ! is_string($verifier)
            || ! hash_equals($expectedState, (string) $request->string('state'))
        ) {
            IdentitySession::forgetHandshake();

            throw new AccessDeniedHttpException('The login response did not match this session.');
        }

        $tokens = $client->exchangeCode((string) $request->string('code'), $verifier);
        $claims = $client->fetchClaims($tokens['access_token']);

        $this->assertAllowlisted((string) $claims['email']);

        $user = $provisioner->provision($claims);

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

        Auth::login($user, remember: true);

        IdentitySession::putTokens($tokens);
        IdentitySession::markChecked();
        IdentitySession::clearGrace();

        $intended = Session::pull(IdentitySession::INTENDED);

        return redirect()->to(is_string($intended) && $intended !== '' ? $intended : '/');
    }

    private function assertAllowlisted(string $email): void
    {
        /** @var array<int, string> $allowlist */
        $allowlist = config('user-team-sync.client.allowlist', []);

        if ($allowlist === []) {
            return;
        }

        foreach ($allowlist as $allowed) {
            if (mb_strtolower($allowed) === mb_strtolower($email)) {
                return;
            }
        }

        IdentitySession::forgetHandshake();

        throw new AccessDeniedHttpException('This account is not part of the SSO pilot yet.');
    }
}
