<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Madbox99\UserTeamSync\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->bootWithConfig(['user-team-sync.mode' => 'client']);
});

function fakeIdentity(array $claimOverrides = []): void
{
    Http::fake([
        'identity.test/oauth/token' => Http::response([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 31536000,
        ]),
        'identity.test/api/userinfo' => Http::response(array_merge([
            'sub' => '11111111-1111-4111-8111-111111111111',
            'email' => 'anna@example.test',
            'name' => 'Anna Teszt',
            'role' => 'manager',
            'orgs' => [['uuid' => '22222222-2222-4222-8222-222222222222', 'name' => 'Acme Kft.', 'slug' => 'acme-kft']],
            'apps' => ['crm'],
            'issued_at' => 1785832300,
            'claims_version' => 1,
        ], $claimOverrides)),
    ]);
}

it('redirects to the identity provider with pkce', function (): void {
    $response = $this->get('/auth/redirect');

    $response->assertRedirectContains('https://identity.test/oauth/authorize');

    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->not->toBeEmpty()
        ->and($query['state'])->not->toBeEmpty();

    // The verifier must stay on this side — sending it would defeat PKCE.
    expect(session('identity.code_verifier'))->not->toBeEmpty()
        ->and($query)->not->toHaveKey('code_verifier');
});

it('signs the user in through the callback', function (): void {
    fakeIdentity();

    $this->get('/auth/redirect');

    $response = $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => session('identity.state'),
    ]));

    $response->assertRedirect();

    expect(auth()->check())->toBeTrue()
        ->and(auth()->user()->email)->toBe('anna@example.test')
        ->and(User::query()->count())->toBe(1);
});

it('mints no remember-me cookie, so a lapsed session cannot skip revalidation', function (): void {
    // A recaller cookie outlives the session. It would re-authenticate the
    // user into a fresh session holding no identity.* keys at all, which
    // RevalidateIdentity passes through by design — permanently exempting
    // anyone who idles past SESSION_LIFETIME from entitlement revocation,
    // role and team changes and the grace/logout machinery.
    fakeIdentity();

    $this->get('/auth/redirect');

    $response = $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => session('identity.state'),
    ]));

    $response->assertRedirect()
        ->assertCookieMissing(Auth::guard('web')->getRecallerName());

    // No remember token on the row either, so no recaller can be forged for
    // this user after the fact.
    expect(auth()->check())->toBeTrue()
        ->and(auth()->viaRemember())->toBeFalse()
        ->and(User::query()->sole()->getAttribute('remember_token'))->toBeNull();
});

it('rejects a callback whose state does not match', function (): void {
    // Without this check any site could initiate a login into this app.
    fakeIdentity();

    $this->get('/auth/redirect');

    $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => 'forged-state',
    ]))->assertForbidden();

    expect(auth()->check())->toBeFalse();
});

it('rejects a callback with no prior redirect', function (): void {
    fakeIdentity();

    $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => 'anything',
    ]))->assertForbidden();
});

it('refuses the login when the app key is missing from the apps claim', function (): void {
    fakeIdentity(['apps' => ['mes']]);

    $this->get('/auth/redirect');

    $response = $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => session('identity.state'),
    ]));

    $response->assertOk();
    $response->assertSee('Manage subscription');

    expect(auth()->check())->toBeFalse();
});

it('provisions the user even though the login is refused for entitlement', function (): void {
    // Deliberate: the account and its teams are real, only the subscription is
    // missing. Provisioning anyway means the user works the moment they buy.
    fakeIdentity(['apps' => []]);

    $this->get('/auth/redirect');
    $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => session('identity.state'),
    ]));

    expect(User::query()->where('email', 'anna@example.test')->exists())->toBeTrue();
});

it('refuses a user who is not on the allowlist while the allowlist is in force', function (): void {
    config()->set('user-team-sync.client.allowlist', ['belsos@cegem360.hu']);
    fakeIdentity();

    $this->get('/auth/redirect');

    $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => session('identity.state'),
    ]))->assertForbidden();

    expect(auth()->check())->toBeFalse()
        ->and(User::query()->count())->toBe(0);
});

it('lets an allowlisted user through', function (): void {
    config()->set('user-team-sync.client.allowlist', ['anna@example.test']);
    fakeIdentity();

    $this->get('/auth/redirect');

    $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => session('identity.state'),
    ]));

    expect(auth()->check())->toBeTrue();
});

it('does not leave the code verifier in the session after a successful login', function (): void {
    fakeIdentity();

    $this->get('/auth/redirect');
    $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => session('identity.state'),
    ]));

    expect(session('identity.code_verifier'))->toBeNull()
        ->and(session('identity.state'))->toBeNull();
});

it('stores the refresh token encrypted rather than in the clear', function (): void {
    fakeIdentity();

    $this->get('/auth/redirect');
    $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => session('identity.state'),
    ]));

    expect(session('identity.refresh_token'))->not->toBe('refresh-1')
        ->and(decrypt(session('identity.refresh_token')))->toBe('refresh-1');
});

it('does not let an absolute intended url turn the callback into an open redirect', function (): void {
    fakeIdentity();

    $this->get('/auth/redirect?'.http_build_query(['intended' => 'https://evil.example/phish']));

    $response = $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => session('identity.state'),
    ]));

    $response->assertRedirect('/');
});

it('does not let a protocol-relative intended url turn the callback into an open redirect', function (): void {
    fakeIdentity();

    $this->get('/auth/redirect?'.http_build_query(['intended' => '//evil.example/phish']));

    $response = $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => session('identity.state'),
    ]));

    $response->assertRedirect('/');
});

it('redirects to a legitimate relative intended path', function (): void {
    fakeIdentity();

    $this->get('/auth/redirect?'.http_build_query(['intended' => '/app/acme-kft/customers']));

    $response = $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => session('identity.state'),
    ]));

    $response->assertRedirect('/app/acme-kft/customers');
});

it('does not leave an intended target from an abandoned handshake for the next login', function (): void {
    fakeIdentity();

    $this->get('/auth/redirect?'.http_build_query(['intended' => '/first-attempt']));

    // Abandon: a forged state triggers forgetHandshake() without the
    // intended value ever being consumed by a redirect.
    $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => 'forged-state',
    ]))->assertForbidden();

    // A fresh handshake, this time with no intended param at all.
    $this->get('/auth/redirect');

    $response = $this->get('/auth/callback?'.http_build_query([
        'code' => 'code-1',
        'state' => session('identity.state'),
    ]));

    $response->assertRedirect('/');
});
