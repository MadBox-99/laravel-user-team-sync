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
            Session::put(IdentitySession::INTENDED, (string) $request->string('intended'));
        }

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return redirect()->away($client->authorizeUrl($state, $challenge));
    }
}
