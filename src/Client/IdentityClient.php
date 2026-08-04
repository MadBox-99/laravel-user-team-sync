<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Madbox99\UserTeamSync\Client\Exceptions\IdentityRejectedException;
use Madbox99\UserTeamSync\Client\Exceptions\IdentityUnavailableException;

/**
 * HTTP boundary between this app and the identity provider (Laravel Passport,
 * authorization_code + PKCE). Talks the OAuth token endpoint and the
 * /api/userinfo claims endpoint; carries no session or user state of its own.
 */
final class IdentityClient
{
    public function authorizeUrl(string $state, string $codeChallenge): string
    {
        return $this->baseUrl().'/oauth/authorize?'.http_build_query([
            'client_id' => (string) config('user-team-sync.client.client_id'),
            'redirect_uri' => (string) config('user-team-sync.client.redirect_uri'),
            'response_type' => 'code',
            'scope' => (string) config('user-team-sync.client.scopes', ''),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => (string) config('user-team-sync.client.redirect_uri'),
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function refresh(string $refreshToken): array
    {
        return $this->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => (string) config('user-team-sync.client.scopes', ''),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchClaims(string $accessToken): array
    {
        $response = $this->send(fn (): Response => $this->pending()
            ->withToken($accessToken)
            ->acceptJson()
            ->get($this->baseUrl().'/api/userinfo'));

        $claims = $response->json();

        if (! is_array($claims) || ! $this->isWellFormed($claims)) {
            // A 2xx whose body is not a claims payload — a maintenance page, a
            // proxy error page, a truncated response — means the provider is
            // not answering properly. That is an outage, not a revocation:
            // letting it through would 500 every page in the fleet, and
            // reading it as a rejection would log the fleet out over a deploy.
            throw new IdentityUnavailableException(
                'The identity provider returned a malformed claims payload.',
            );
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    /**
     * Every consumer downstream — the provisioner, the entitlement check —
     * indexes into these keys directly, so the shape is enforced once here at
     * the boundary rather than defended against everywhere afterwards.
     *
     * @param  array<mixed>  $claims
     */
    private function isWellFormed(array $claims): bool
    {
        foreach (['sub', 'email', 'name'] as $key) {
            if (! isset($claims[$key]) || ! is_string($claims[$key]) || $claims[$key] === '') {
                return false;
            }
        }

        if (isset($claims['role']) && ! is_string($claims['role'])) {
            return false;
        }

        $apps = $claims['apps'] ?? [];

        if (! is_array($apps) || array_filter($apps, 'is_string') !== $apps) {
            return false;
        }

        $orgs = $claims['orgs'] ?? [];

        if (! is_array($orgs)) {
            return false;
        }

        foreach ($orgs as $org) {
            if (! is_array($org)) {
                return false;
            }

            foreach (['uuid', 'name', 'slug'] as $key) {
                if (! isset($org[$key]) || ! is_string($org[$key]) || $org[$key] === '') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $payload
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    private function token(array $payload): array
    {
        $response = $this->send(fn (): Response => $this->pending()
            ->asForm()
            ->acceptJson()
            ->post($this->baseUrl().'/oauth/token', array_merge($payload, [
                'client_id' => (string) config('user-team-sync.client.client_id'),
                'client_secret' => (string) config('user-team-sync.client.client_secret'),
            ])));

        /** @var array{access_token: string, refresh_token: string, expires_in: int} $tokens */
        $tokens = $response->json();

        return $tokens;
    }

    /**
     * The whole point of this method is the distinction it draws: a 4xx is the
     * provider saying no, anything else is the provider being unreachable.
     * Collapsing the two would turn a five-minute outage into a fleet-wide
     * forced logout.
     *
     * @param  callable(): Response  $request
     */
    private function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw new IdentityUnavailableException($exception->getMessage(), previous: $exception);
        }

        if ($response->serverError()) {
            throw new IdentityUnavailableException(
                'The identity provider answered with HTTP '.$response->status().'.',
            );
        }

        if ($response->clientError()) {
            throw new IdentityRejectedException(
                'The identity provider rejected the request with HTTP '.$response->status().'.',
            );
        }

        return $response;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('user-team-sync.client.identity_url'), '/');
    }

    /**
     * The connect timeout matters more than the read timeout here: this client
     * runs inside a middleware on every authenticated page, so a provider whose
     * TCP connect hangs would otherwise pin a worker for the full read timeout
     * on request after request.
     */
    private function pending(): PendingRequest
    {
        return Http::timeout((int) config('user-team-sync.client.http_timeout', 10))
            ->connectTimeout((int) config('user-team-sync.client.http_connect_timeout', 3));
    }
}
