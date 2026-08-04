<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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

it('is walked past by a legacy remember-me login, which is the control for the test below', function (): void {
    // Proves the recaller mechanics used by the next test really do
    // authenticate somebody. A legacy password login with "remember me" is
    // allowed to come back into a fresh session without SSO: it never was an
    // SSO session, so it is out of this middleware's scope.
    Http::fake();

    $this->user->forceFill(['remember_token' => Str::random(60)])->save();

    $this->withCookie($recallerName = Auth::guard('web')->getRecallerName(), implode('|', [
        $this->user->getAuthIdentifier(),
        $this->user->getRememberToken(),
        $this->user->getAuthPassword(),
    ]))->get('/protected')->assertOk();

    expect(Auth::check())->toBeTrue()
        ->and(Auth::viaRemember())->toBeTrue()
        ->and($recallerName)->toStartWith('remember_');

    Http::assertNothingSent();
});

it('cannot be walked past by a recaller cookie for an sso user', function (): void {
    // The regression: an SSO login used to mint a recaller cookie, so once the
    // session lapsed the user came back authenticated into a fresh session
    // with no identity.* keys — and this middleware, correctly, does not touch
    // such a session. The hole is closed at the source: the callback writes no
    // remember token, so a replayed recaller authenticates nobody at all.
    Http::fake();

    expect($this->user->getAttribute('remember_token'))->toBeNull();

    $this->withCookie(Auth::guard('web')->getRecallerName(), implode('|', [
        $this->user->getAuthIdentifier(),
        Str::random(60),
        $this->user->getAuthPassword(),
    ]))->get('/protected')->assertOk();

    expect(Auth::check())->toBeFalse()
        ->and(Auth::viaRemember())->toBeFalse();

    Http::assertNothingSent();
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

it('records a retry marker without restarting the grace clock', function (): void {
    // A slow provider is worse than a down one: with no retry marker every
    // single request would sit through the full HTTP timeout, each one holding
    // a PHP-FPM worker and the session lock, for up to 24 hours.
    Http::fake(['identity.test/api/userinfo' => Http::response('down', 503)]);

    $graceStartedAt = Carbon::now()->subHours(10)->timestamp;

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
            IdentitySession::GRACE_STARTED_AT => $graceStartedAt,
        ])
        ->get('/protected')
        ->assertOk();

    expect(session(IdentitySession::RETRIED_AT))->toBe(Carbon::now()->timestamp)
        // The grace window is anchored to the first failure. A retry that
        // pushed this forward would let an outage postpone its own expiry
        // indefinitely.
        ->and(session(IdentitySession::GRACE_STARTED_AT))->toBe($graceStartedAt);
});

it('does not call the provider again until the retry interval has passed', function (): void {
    // The session state a previous request in grace would have left behind.
    Http::fake();

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
            IdentitySession::GRACE_STARTED_AT => Carbon::now()->subHours(1)->timestamp,
            IdentitySession::RETRIED_AT => Carbon::now()->subMinutes(2)->timestamp,
        ])
        ->get('/protected')
        ->assertOk();

    Http::assertNothingSent();

    expect(Auth::check())->toBeTrue();
});

it('retries again once the retry interval has passed', function (): void {
    Http::fake(['identity.test/api/userinfo' => Http::response(claimsResponse())]);

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
            IdentitySession::GRACE_STARTED_AT => Carbon::now()->subHours(1)->timestamp,
            IdentitySession::RETRIED_AT => Carbon::now()->subMinutes(6)->timestamp,
        ])
        ->get('/protected')
        ->assertOk();

    Http::assertSentCount(1);

    // A recovered provider clears both grace markers, so the session goes back
    // to the ordinary revalidation cadence.
    expect(session(IdentitySession::GRACE_STARTED_AT))->toBeNull()
        ->and(session(IdentitySession::RETRIED_AT))->toBeNull();
});

it('still expires the grace window after a long outage of spaced retries', function (): void {
    // The retry marker is recent-ish but past the interval; the grace marker is
    // 25 hours old. The outage must expire on its own clock, not be kept alive
    // by its retries.
    Http::fake(['identity.test/api/userinfo' => Http::response('down', 503)]);

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
            IdentitySession::GRACE_STARTED_AT => Carbon::now()->subHours(25)->timestamp,
            IdentitySession::RETRIED_AT => Carbon::now()->subMinutes(6)->timestamp,
        ])
        ->get('/protected')
        ->assertRedirect();

    expect(Auth::check())->toBeFalse();
});

it('keeps personal data out of the log when it tolerates an unexpected failure', function (): void {
    // A QueryException interpolates its bindings into getMessage(), so logging
    // the raw message would spray e-mail addresses and names into the error
    // log of every module app.
    Http::fake(['identity.test/api/userinfo' => Http::response(claimsResponse())]);

    Log::spy();

    Team::saving(function (): void {
        throw new RuntimeException(
            'insert into "users" ("email", "name") values (anna@example.test, Anna Teszt)',
        );
    });

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
        ])
        ->get('/protected')
        ->assertOk();

    Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
        $serialised = (string) json_encode($context);

        return str_contains($context['exception'] ?? '', 'RuntimeException')
            && ! str_contains($serialised, 'anna@example.test')
            && ! str_contains($serialised, 'Anna Teszt')
            && ! str_contains($serialised, 'access-1');
    })->once();
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

    // Passport rotates the refresh token and revokes the old one the moment it
    // is used. If the rotated pair is not written back to the session, the very
    // next stale window presents a dead refresh token and logs the user out —
    // and a test that only asserts assertOk() would never notice.
    expect(decrypt(session(IdentitySession::ACCESS_TOKEN)))->toBe('access-2')
        ->and(decrypt(session(IdentitySession::REFRESH_TOKEN)))->toBe('refresh-2');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://identity.test/api/userinfo'
        && $request->hasHeader('Authorization', 'Bearer access-2'));
});

it('keeps the session when the provider goes down between the 401 and the refresh', function (): void {
    // The access token aged out and the provider fell over before the refresh
    // could be exchanged. The 401 arrived first, but it is the *refresh* that
    // decides, and it never got an answer — so this is an outage, not a
    // revocation, and the session belongs in the grace window.
    Http::fake([
        'identity.test/api/userinfo' => Http::response(['message' => 'Unauthenticated.'], 401),
        'identity.test/oauth/token' => Http::response('gateway down', 502),
    ]);

    $this->actingAs($this->user)
        ->withSession([
            IdentitySession::CHECKED_AT => Carbon::now()->subMinutes(20)->timestamp,
            IdentitySession::ACCESS_TOKEN => encrypt('access-1'),
            IdentitySession::REFRESH_TOKEN => encrypt('refresh-1'),
        ])
        ->get('/protected')
        ->assertOk();

    expect(Auth::check())->toBeTrue()
        ->and(session(IdentitySession::GRACE_STARTED_AT))->not->toBeNull();
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
