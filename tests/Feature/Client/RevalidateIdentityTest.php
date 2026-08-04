<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Madbox99\UserTeamSync\Client\Http\Middleware\RevalidateIdentity;
use Madbox99\UserTeamSync\Client\IdentitySession;
use Madbox99\UserTeamSync\Tests\Fixtures\Team;
use Madbox99\UserTeamSync\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->bootWithConfig(['user-team-sync.mode' => 'client']);

    // Defined after the reboot, or the fresh application would not have it.
    Route::middleware(['web', RevalidateIdentity::class])
        ->get('/protected', fn (): string => 'ok');

    $this->user = User::query()->create([
        'uuid' => '11111111-1111-4111-8111-111111111111',
        'name' => 'Anna Teszt',
        'email' => 'anna@example.test',
        'password' => '',
        'is_active' => true,
    ]);
});

function claimsResponse(array $overrides = []): array
{
    return array_merge([
        'sub' => '11111111-1111-4111-8111-111111111111',
        'email' => 'anna@example.test',
        'name' => 'Anna Teszt',
        'role' => 'manager',
        'orgs' => [['uuid' => '22222222-2222-4222-8222-222222222222', 'name' => 'Acme Kft.', 'slug' => 'acme-kft']],
        'apps' => ['crm'],
        'issued_at' => 1785832300,
        'claims_version' => 1,
    ], $overrides);
}

it('does nothing for a guest', function (): void {
    Http::fake();

    $this->get('/protected')->assertOk();

    Http::assertNothingSent();
});

it('does not call the identity provider while the check is still fresh', function (): void {
    Http::fake();

    $this->actingAs($this->user)
        ->withSession([IdentitySession::CHECKED_AT => Carbon::now()->timestamp])
        ->get('/protected')
        ->assertOk();

    Http::assertNothingSent();
});

it('leaves a legacy password login alone instead of logging it out', function (): void {
    // The phased rollout runs both login paths in the same app: only the
    // allowlisted pilot users go through SSO, everyone else keeps using the
    // password form. Their session carries no identity token at all, so there
    // is nothing to revalidate — and nothing to log out either.
    Http::fake();

    $this->actingAs($this->user)
        ->get('/protected')
        ->assertOk();

    Http::assertNothingSent();

    expect(Auth::check())->toBeTrue();
});

it('logs out an sso session whose access token is gone', function (): void {
    // The mirror image of the test above: a session that *was* established
    // through SSO but has lost its token is a revocation, not a legacy login.
    Http::fake();

    $this->actingAs($this->user)
        ->withSession([IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp])
        ->get('/protected')
        ->assertRedirect();

    expect(Auth::check())->toBeFalse();
});

it('re-runs the provisioner once the check is stale', function (): void {
    Http::fake(['identity.test/api/userinfo' => Http::response(claimsResponse())]);

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
        ])
        ->get('/protected')
        ->assertOk();

    expect(Team::query()->where('slug', 'acme-kft')->exists())->toBeTrue();
});

it('picks up a team rename without any push from the publisher', function (): void {
    // The behaviour the whole project is for.
    Team::query()->create([
        'uuid' => '22222222-2222-4222-8222-222222222222',
        'name' => 'Acme Kft.',
        'slug' => 'acme-kft',
    ]);

    Http::fake(['identity.test/api/userinfo' => Http::response(claimsResponse([
        'orgs' => [['uuid' => '22222222-2222-4222-8222-222222222222', 'name' => 'Acme Zrt.', 'slug' => 'acme-zrt']],
    ]))]);

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
        ])
        ->get('/protected')
        ->assertOk();

    expect(Team::query()->count())->toBe(1)
        ->and(Team::query()->first()->slug)->toBe('acme-zrt');
});

it('logs the user out when the identity provider rejects the token', function (): void {
    Http::fake(['identity.test/api/userinfo' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
        ])
        ->get('/protected')
        ->assertRedirect();

    expect(Auth::check())->toBeFalse();
});

it('keeps the session alive when the identity provider is down', function (): void {
    // An outage must not be read as revoked access — this is the single
    // decision that separates "SSO is a fix" from "SSO is a new outage source".
    Http::fake(['identity.test/api/userinfo' => Http::response('down', 503)]);

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
        ])
        ->get('/protected')
        ->assertOk();

    expect(Auth::check())->toBeTrue();
});

it('keeps the session alive when the provider answers 200 with a maintenance page', function (): void {
    // Mid-deploy the provider serves HTML with HTTP 200. Every module app in
    // the fleet would 500 on every page — the outage the grace window exists
    // to prevent, arriving through an unguarded door.
    Http::fake(['identity.test/api/userinfo' => Http::response('<html>Be right back</html>')]);

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
        ])
        ->get('/protected')
        ->assertOk();

    expect(Auth::check())->toBeTrue();
});

it('tolerates an unexpected failure instead of 500-ing the page', function (): void {
    Http::fake(['identity.test/api/userinfo' => Http::response(claimsResponse())]);

    // Any bug at all inside the provisioning path. The page must still render.
    Team::saving(function (): void {
        throw new RuntimeException('boom');
    });

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
        ])
        ->get('/protected')
        ->assertOk();

    expect(Auth::check())->toBeTrue();
});

it('logs the user out once the grace period has run out', function (): void {
    Http::fake(['identity.test/api/userinfo' => Http::response('down', 503)]);

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
            IdentitySession::GRACE_STARTED_AT => Carbon::now()->subHours(25)->timestamp,
        ])
        ->get('/protected')
        ->assertRedirect();

    expect(Auth::check())->toBeFalse();
});

it('logs the user out when entitlement to this app disappears', function (): void {
    Http::fake(['identity.test/api/userinfo' => Http::response(claimsResponse(['apps' => ['mes']]))]);

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
        ])
        ->get('/protected')
        ->assertRedirect();

    expect(Auth::check())->toBeFalse();
});

it('refreshes an expired access token instead of logging the user out', function (): void {
    $calls = 0;

    Http::fake([
        'identity.test/api/userinfo' => function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response(['message' => 'Unauthenticated.'], 401)
                : Http::response(claimsResponse());
        },
        'identity.test/oauth/token' => Http::response([
            'access_token' => 'access-2',
            'refresh_token' => 'refresh-2',
            'expires_in' => 31536000,
        ]),
    ]);

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
            IdentitySession::REFRESH_TOKEN => encrypt('refresh-1'),
        ])
        ->get('/protected')
        ->assertOk();

    expect(Auth::check())->toBeTrue();
});

it('clears the grace marker after a successful revalidation', function (): void {
    Http::fake(['identity.test/api/userinfo' => Http::response(claimsResponse())]);

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
            IdentitySession::GRACE_STARTED_AT => Carbon::now()->subHours(2)->timestamp,
        ])
        ->get('/protected')
        ->assertOk();

    expect(session(IdentitySession::GRACE_STARTED_AT))->toBeNull();
});
