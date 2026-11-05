<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Madbox99\UserTeamSync\Tests\Fixtures\SuperAdminUser;
use Madbox99\UserTeamSync\Tests\Fixtures\User;

beforeEach(function (): void {
    $migration = require __DIR__.'/../../../database/super-admin-migrations/add_is_super_admin_to_users_table.php';
    $migration->up();

    config()->set('user-team-sync.models.user', SuperAdminUser::class);
});

function makeSuperAdminUser(array $attributes = []): SuperAdminUser
{
    return SuperAdminUser::query()->create([
        'name' => 'Test',
        'email' => fake()->unique()->safeEmail(),
        'password' => 'secret',
        ...$attributes,
    ]);
}

it('adds the column only once and keeps it out of mass assignment', function (): void {
    $migration = require __DIR__.'/../../../database/super-admin-migrations/add_is_super_admin_to_users_table.php';
    $migration->up();

    $user = makeSuperAdminUser(['is_super_admin' => true]);

    expect(Schema::hasColumn('users', 'is_super_admin'))->toBeTrue()
        ->and($user->fresh()->isSuperAdmin())->toBeFalse();
});

it('grants, revokes and scopes super admins', function (): void {
    $user = makeSuperAdminUser();
    makeSuperAdminUser();

    $user->grantSuperAdmin();

    expect($user->fresh()->isSuperAdmin())->toBeTrue()
        ->and(SuperAdminUser::query()->superAdmins()->pluck('id')->all())->toBe([$user->id]);

    $user->revokeSuperAdmin();

    expect($user->fresh()->isSuperAdmin())->toBeFalse();
});

it('lets super admins pass every gate check', function (): void {
    Gate::define('manage-everything', fn (): bool => false);

    $superAdmin = makeSuperAdminUser()->grantSuperAdmin();
    $regular = makeSuperAdminUser();

    expect(Gate::forUser($superAdmin)->allows('manage-everything'))->toBeTrue()
        ->and(Gate::forUser($regular)->allows('manage-everything'))->toBeFalse();
});

it('leaves users without the trait alone at the gate', function (): void {
    Gate::define('manage-everything', fn (): bool => false);

    $user = User::query()->create(['name' => 'Plain', 'email' => 'plain@example.com', 'password' => 'secret']);

    expect(Gate::forUser($user)->allows('manage-everything'))->toBeFalse();
});

it('grants and revokes the flag from the console', function (): void {
    $user = makeSuperAdminUser();

    $this->artisan('user-team-sync:super-admin', ['email' => $user->email])->assertSuccessful();
    expect($user->fresh()->isSuperAdmin())->toBeTrue();

    $this->artisan('user-team-sync:super-admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
    expect($user->fresh()->isSuperAdmin())->toBeFalse();

    $this->artisan('user-team-sync:super-admin', ['email' => 'nobody@example.com'])->assertFailed();
});

it('refuses the command when the user model does not use the trait', function (): void {
    config()->set('user-team-sync.models.user', User::class);

    $this->artisan('user-team-sync:super-admin', ['email' => 'x@example.com'])->assertFailed();
});
