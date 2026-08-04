<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Madbox99\UserTeamSync\Client\Exceptions\IdentityRejectedException;
use Madbox99\UserTeamSync\Client\Exceptions\IdentityUnavailableException;
use Madbox99\UserTeamSync\Client\IdentityClient;

it('builds an authorize url with pkce and the configured client', function (): void {
    $url = app(IdentityClient::class)->authorizeUrl('state-abc', 'challenge-xyz');

    expect($url)->toStartWith('https://identity.test/oauth/authorize?');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query)->toMatchArray([
        'client_id' => 'test-client-id',
        'redirect_uri' => 'https://app.test/auth/callback',
        'response_type' => 'code',
        'state' => 'state-abc',
        'code_challenge' => 'challenge-xyz',
        'code_challenge_method' => 'S256',
    ]);

    // The authorize URL is a confidential OAuth client's URL sitting in
    // browser history, server access logs and Referer headers. The client
    // secret and the PKCE verifier must never end up in it.
    expect($query)
        ->not->toHaveKey('client_secret')
        ->not->toHaveKey('code_verifier');
});

it('exchanges an authorization code for tokens', function (): void {
    Http::fake([
        'identity.test/oauth/token' => Http::response([
            'token_type' => 'Bearer',
            'expires_in' => 31536000,
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
        ]),
    ]);

    $tokens = app(IdentityClient::class)->exchangeCode('code-1', 'verifier-1');

    expect($tokens['access_token'])->toBe('access-1')
        ->and($tokens['refresh_token'])->toBe('refresh-1');

    Http::assertSent(fn (Request $request): bool => $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'code-1'
        && $request['code_verifier'] === 'verifier-1'
        && $request['client_secret'] === 'test-client-secret');
});

it('treats a rejected code as a rejection, not an outage', function (): void {
    Http::fake([
        'identity.test/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    expect(fn () => app(IdentityClient::class)->exchangeCode('code-1', 'verifier-1'))
        ->toThrow(IdentityRejectedException::class);
});

it('treats a 5xx as an outage, not a rejection', function (): void {
    // This distinction decides whether an identity-provider outage logs the
    // whole fleet out or lets working sessions carry on.
    Http::fake([
        'identity.test/api/userinfo' => Http::response('gateway down', 502),
    ]);

    expect(fn () => app(IdentityClient::class)->fetchClaims('access-1'))
        ->toThrow(IdentityUnavailableException::class);
});

it('treats a connection failure as an outage', function (): void {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('timeout'));

    expect(fn () => app(IdentityClient::class)->fetchClaims('access-1'))
        ->toThrow(IdentityUnavailableException::class);
});

it('treats a 401 on userinfo as a rejection', function (): void {
    Http::fake([
        'identity.test/api/userinfo' => Http::response(['message' => 'Unauthenticated.'], 401),
    ]);

    expect(fn () => app(IdentityClient::class)->fetchClaims('access-1'))
        ->toThrow(IdentityRejectedException::class);
});

it('fetches the claims with a bearer token', function (): void {
    Http::fake([
        'identity.test/api/userinfo' => Http::response([
            'sub' => '11111111-1111-4111-8111-111111111111',
            'email' => 'anna@example.test',
            'name' => 'Anna Teszt',
            'role' => 'manager',
            'orgs' => [],
            'apps' => ['crm'],
            'issued_at' => 1785832300,
            'claims_version' => 1,
        ]),
    ]);

    $claims = app(IdentityClient::class)->fetchClaims('access-1');

    expect($claims['sub'])->toBe('11111111-1111-4111-8111-111111111111')
        ->and($claims['apps'])->toBe(['crm']);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer access-1'));
});

it('treats a 2xx body that is not json as an outage', function (): void {
    // The provider is mid-deploy and answers 200 with an HTML maintenance
    // page. Letting that through would 500 every page of every module app.
    Http::fake([
        'identity.test/api/userinfo' => Http::response('<html><body>Be right back</body></html>'),
    ]);

    expect(fn () => app(IdentityClient::class)->fetchClaims('access-1'))
        ->toThrow(IdentityUnavailableException::class);
});

it('treats a non-array apps claim as an outage', function (): void {
    Http::fake([
        'identity.test/api/userinfo' => Http::response([
            'sub' => '11111111-1111-4111-8111-111111111111',
            'email' => 'anna@example.test',
            'name' => 'Anna Teszt',
            'orgs' => [],
            'apps' => 'crm',
        ]),
    ]);

    expect(fn () => app(IdentityClient::class)->fetchClaims('access-1'))
        ->toThrow(IdentityUnavailableException::class);
});

it('treats an org entry without a slug as an outage', function (): void {
    Http::fake([
        'identity.test/api/userinfo' => Http::response([
            'sub' => '11111111-1111-4111-8111-111111111111',
            'email' => 'anna@example.test',
            'name' => 'Anna Teszt',
            'orgs' => [['uuid' => '22222222-2222-4222-8222-222222222222', 'name' => 'Acme Kft.']],
            'apps' => ['crm'],
        ]),
    ]);

    expect(fn () => app(IdentityClient::class)->fetchClaims('access-1'))
        ->toThrow(IdentityUnavailableException::class);
});

it('treats an org entry without a name as an outage', function (): void {
    Http::fake([
        'identity.test/api/userinfo' => Http::response([
            'sub' => '11111111-1111-4111-8111-111111111111',
            'email' => 'anna@example.test',
            'name' => 'Anna Teszt',
            'orgs' => [['uuid' => '22222222-2222-4222-8222-222222222222', 'slug' => 'acme-kft']],
            'apps' => ['crm'],
        ]),
    ]);

    expect(fn () => app(IdentityClient::class)->fetchClaims('access-1'))
        ->toThrow(IdentityUnavailableException::class);
});

it('treats claims without a sub as an outage', function (): void {
    Http::fake([
        'identity.test/api/userinfo' => Http::response([
            'email' => 'anna@example.test',
            'name' => 'Anna Teszt',
        ]),
    ]);

    expect(fn () => app(IdentityClient::class)->fetchClaims('access-1'))
        ->toThrow(IdentityUnavailableException::class);
});

it('accepts claims that carry no orgs and no apps at all', function (): void {
    // Absent is not the same as malformed: a user with no memberships and no
    // entitlements is a perfectly well-formed payload.
    Http::fake([
        'identity.test/api/userinfo' => Http::response([
            'sub' => '11111111-1111-4111-8111-111111111111',
            'email' => 'anna@example.test',
            'name' => 'Anna Teszt',
        ]),
    ]);

    expect(app(IdentityClient::class)->fetchClaims('access-1'))
        ->toHaveKey('sub');
});

it('refreshes an expired access token', function (): void {
    Http::fake([
        'identity.test/oauth/token' => Http::response([
            'access_token' => 'access-2',
            'refresh_token' => 'refresh-2',
            'expires_in' => 31536000,
        ]),
    ]);

    $tokens = app(IdentityClient::class)->refresh('refresh-1');

    expect($tokens['access_token'])->toBe('access-2');

    Http::assertSent(fn (Request $request): bool => $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'refresh-1');
});
