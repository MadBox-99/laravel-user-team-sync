<?php

declare(strict_types=1);

use Madbox99\UserTeamSync\Client\Exceptions\IdentityConflictException;
use Madbox99\UserTeamSync\Client\IdentityProvisioner;
use Madbox99\UserTeamSync\Tests\Fixtures\User;
use Madbox99\UserTeamSync\Tests\Fixtures\UserWithoutUuidFillable;

function claims(array $overrides = []): array
{
    return array_merge([
        'sub' => '11111111-1111-4111-8111-111111111111',
        'email' => 'anna@example.test',
        'name' => 'Anna Teszt',
        'role' => 'manager',
        'orgs' => [],
        'apps' => ['crm'],
        'issued_at' => 1785832300,
        'claims_version' => 1,
    ], $overrides);
}

it('creates a user from the claims', function (): void {
    $user = app(IdentityProvisioner::class)->provision(claims());

    expect($user->uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($user->email)->toBe('anna@example.test')
        ->and($user->name)->toBe('Anna Teszt');
});

it('is idempotent', function (): void {
    $provisioner = app(IdentityProvisioner::class);

    $provisioner->provision(claims());
    $provisioner->provision(claims());

    expect(User::query()->count())->toBe(1);
});

it('updates the name and e-mail of an existing user matched by uuid', function (): void {
    User::query()->create([
        'uuid' => '11111111-1111-4111-8111-111111111111',
        'name' => 'Regi Nev',
        'email' => 'regi@example.test',
        'password' => 'irrelevant',
    ]);

    $user = app(IdentityProvisioner::class)->provision(claims());

    expect(User::query()->count())->toBe(1)
        ->and($user->name)->toBe('Anna Teszt')
        ->and($user->email)->toBe('anna@example.test');
});

it('adopts a local user that has no uuid but the same e-mail', function (): void {
    // Production reality: crm has 7 users with a NULL uuid. Matching on uuid
    // alone would try to INSERT a second row with the same e-mail and hit the
    // unique index, so the very first SSO login of such a user would 500.
    $existing = User::query()->create([
        'uuid' => null,
        'name' => 'Regi Nev',
        'email' => 'anna@example.test',
        'password' => 'irrelevant',
    ]);

    $user = app(IdentityProvisioner::class)->provision(claims());

    expect(User::query()->count())->toBe(1)
        ->and($user->getKey())->toBe($existing->getKey())
        ->and($user->uuid)->toBe('11111111-1111-4111-8111-111111111111');
});

it('refuses to take over an e-mail that belongs to a different uuid', function (): void {
    User::query()->create([
        'uuid' => '99999999-9999-4999-8999-999999999999',
        'name' => 'Valaki Mas',
        'email' => 'anna@example.test',
        'password' => 'irrelevant',
    ]);

    expect(fn () => app(IdentityProvisioner::class)->provision(claims()))
        ->toThrow(IdentityConflictException::class);
});

it('writes the uuid even when the receiver model does not list it as fillable', function (): void {
    // App\Models\User in crm declares #[Fillable] without 'uuid'. Mass
    // assignment would silently drop it and every login would create a new row.
    config()->set('user-team-sync.models.user', UserWithoutUuidFillable::class);

    $user = app(IdentityProvisioner::class)->provision(claims());

    expect($user->fresh()->uuid)->toBe('11111111-1111-4111-8111-111111111111');
});

it('activates the user because the token already proves entitlement', function (): void {
    // The receiver's default_active is false and the panel is behind
    // EnsureUserHasActiveSubscription, so without this every SSO login 403s.
    $user = app(IdentityProvisioner::class)->provision(claims());

    expect((bool) $user->is_active)->toBeTrue();
});

it('leaves no usable password on the account', function (): void {
    // Password replication is what SSO removes: the hash must not be copied to
    // 16 apps. An empty string is not a valid bcrypt hash, so Hash::check()
    // can never succeed against it.
    $user = app(IdentityProvisioner::class)->provision(claims());

    expect(Hash::check('password', (string) $user->password))->toBeFalse()
        ->and(Hash::check('', (string) $user->password))->toBeFalse();
});

it('marks the e-mail as verified because the identity provider already did', function (): void {
    $user = app(IdentityProvisioner::class)->provision(claims());

    expect($user->email_verified_at)->not->toBeNull();
});

it('resolves a lower-case claim role onto a differently-cased local role', function (): void {
    // Verified on production: the publisher sends 'manager', crm's Spatie role
    // is named 'Manager', and it only works today because MySQL's collation is
    // case-insensitive. These tests run on SQLite, where it is not.
    config()->set('user-team-sync.client.role_map', []);

    $resolved = app(IdentityProvisioner::class)
        ->resolveRoleName('manager', ['Admin', 'Manager', 'Subscriber']);

    expect($resolved)->toBe('Manager');
});

it('prefers an explicit role map over the case-insensitive fallback', function (): void {
    config()->set('user-team-sync.client.role_map', ['manager' => 'Sales Representative']);

    $resolved = app(IdentityProvisioner::class)
        ->resolveRoleName('manager', ['Admin', 'Manager', 'Sales Representative']);

    expect($resolved)->toBe('Sales Representative');
});

it('falls back to the default role when the claim role has no local counterpart', function (): void {
    config()->set('user-team-sync.receiver.default_role', 'Subscriber');

    $resolved = app(IdentityProvisioner::class)
        ->resolveRoleName('kozgazdasz', ['Admin', 'Manager', 'Subscriber']);

    expect($resolved)->toBe('Subscriber');
});

it('stores the role on the users table when the role driver is not spatie', function (): void {
    config()->set('user-team-sync.receiver.role_driver', 'column');

    $user = app(IdentityProvisioner::class)->provision(claims());

    expect($user->fresh()->role)->toBe('manager');
});
