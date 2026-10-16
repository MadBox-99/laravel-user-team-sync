<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Madbox99\UserTeamSync\Client\IdentityProvisioner;
use Madbox99\UserTeamSync\Tests\Fixtures\Role;
use Madbox99\UserTeamSync\Tests\Fixtures\SpatieUser;
use Madbox99\UserTeamSync\Tests\Fixtures\User;

beforeEach(function (): void {
    Event::fake();

    Schema::create('roles', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    foreach (['Super-Admin', 'Admin', 'manager', 'subscriber'] as $name) {
        Role::query()->create(['name' => $name]);
    }

    config()->set('permission.models.role', Role::class);
    config()->set('user-team-sync.models.user', SpatieUser::class);
    config()->set('user-team-sync.receiver.role_driver', 'spatie');
    config()->set('user-team-sync.receiver.default_role', 'subscriber');
    config()->set('user-team-sync.receiver.local_only_roles', ['Super-Admin']);
    config()->set('user-team-sync.client.role_map', []);
});

function ssoClaims(string $role): array
{
    return [
        'sub' => '22222222-2222-4222-8222-222222222222',
        'email' => 'boss@example.test',
        'name' => 'Boss',
        'role' => $role,
        'orgs' => [],
        'apps' => ['crm'],
        'issued_at' => 1785832300,
        'claims_version' => 1,
    ];
}

function superAdminUser(string $roles = 'Super-Admin,manager'): SpatieUser
{
    return SpatieUser::query()->create([
        'uuid' => '22222222-2222-4222-8222-222222222222',
        'name' => 'Boss',
        'email' => 'boss@example.test',
        'password' => '',
        'role' => $roles,
    ]);
}

it('keeps a local-only role through an SSO login', function (): void {
    $user = superAdminUser();

    app(IdentityProvisioner::class)->provision(ssoClaims('admin'));

    expect($user->fresh()->getRoleNames()->all())->toBe(['Admin', 'Super-Admin']);
});

it('never hands out a local-only role through an SSO claim', function (): void {
    // 'super-admin' would reach 'Super-Admin' through the case-insensitive match.
    $user = app(IdentityProvisioner::class)->provision(ssoClaims('super-admin'));

    expect($user->fresh()->getRoleNames()->all())->toBe(['subscriber']);
});

it('keeps a local-only role through /api/sync-user', function (): void {
    $user = superAdminUser();

    $this->postJson('/api/sync-user', [
        'email' => 'boss@example.test',
        'role' => 'subscriber',
    ], authHeaders())->assertOk();

    expect($user->fresh()->getRoleNames()->all())->toBe(['subscriber', 'Super-Admin']);
});

it('never hands out a local-only role through /api/sync-user', function (): void {
    $user = superAdminUser('manager');

    $this->postJson('/api/sync-user', [
        'email' => 'boss@example.test',
        'role' => 'Super-Admin',
    ], authHeaders())->assertOk();

    expect($user->fresh()->getRoleNames()->all())->toBe(['subscriber']);
});

it('never hands out a local-only role through /api/create-user', function (): void {
    $this->postJson('/api/create-user', [
        'email' => 'new@example.test',
        'name' => 'New',
        'password_hash' => Hash::make('secret'),
        'role' => 'Super-Admin',
    ], authHeaders())->assertCreated();

    expect(SpatieUser::query()->where('email', 'new@example.test')->sole()->getRoleNames()->all())
        ->toBe(['subscriber']);
});

it('replaces every role when no local-only role is configured', function (): void {
    config()->set('user-team-sync.receiver.local_only_roles', []);
    $user = superAdminUser();

    app(IdentityProvisioner::class)->provision(ssoClaims('admin'));

    expect($user->fresh()->getRoleNames()->all())->toBe(['Admin']);
});

it('leaves a local-only role in a plain role column untouched', function (): void {
    config()->set('user-team-sync.models.user', User::class);
    config()->set('user-team-sync.receiver.role_driver', 'column');

    $user = User::query()->create([
        'uuid' => '22222222-2222-4222-8222-222222222222',
        'name' => 'Boss',
        'email' => 'boss@example.test',
        'password' => '',
        'role' => 'Super-Admin',
    ]);

    app(IdentityProvisioner::class)->provision(ssoClaims('admin'));
    $this->postJson('/api/sync-user', ['email' => 'boss@example.test', 'role' => 'manager'], authHeaders())->assertOk();

    expect($user->fresh()->role)->toBe('Super-Admin');
});
