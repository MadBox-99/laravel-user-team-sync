<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Client\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Madbox99\UserTeamSync\Client\IdentityClient;
use Madbox99\UserTeamSync\Client\IdentitySession;

final class IdentityRedirectController
{
    public function __invoke(Request $request, IdentityClient $client): RedirectResponse
    {
        $state = Str::random(40);
        $verifier = Str::random(128);

        Session::put(IdentitySession::STATE, $state);
        Session::put(IdentitySession::CODE_VERIFIER, $verifier);

        if ($request->filled('intended')) {
            $intended = (string) $request->string('intended');

            // Same-app relative path only — a single leading '/', never '//'
            // (protocol-relative, absolute to a browser). Anything else is
            // silently dropped rather than rejected: a forged intended value
            // should not turn a login attempt into an error page.
            if ($this->isSafeIntended($intended)) {
                Session::put(IdentitySession::INTENDED, $intended);
            }
        }

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return redirect()->away($client->authorizeUrl($state, $challenge));
    }

    private function isSafeIntended(string $path): bool
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//');
    }
}
