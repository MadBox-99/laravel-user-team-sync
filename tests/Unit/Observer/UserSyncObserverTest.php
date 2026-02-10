<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Madbox99\UserTeamSync\Publisher\Jobs\SyncUserJob;
use Madbox99\UserTeamSync\Publisher\Observers\UserSyncObserver;
use Madbox99\UserTeamSync\Tests\Fixtures\User;

beforeEach(function (): void {
    config()->set('user-team-sync.mode', 'publisher');
    config()->set('user-team-sync.publisher.auto_observe', true);
    config()->set('user-team-sync.publisher.sync_fields', ['email', 'role']);

    // Manually attach observer since ServiceProvider already booted in receiver mode
    User::observe(UserSyncObserver::class);
});

it('dispatches SyncUserJob when watched field changes', function (): void {
    Bus::fake();

    $user = User::create([
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => Hash::make('pass'),
        'role' => 'subscriber',
    ]);

    $user->role = 'admin';
    $user->save();

    Bus::assertDispatched(SyncUserJob::class, function (SyncUserJob $job): bool {
        return $job->email === 'test@example.com'
            && $job->changedData === ['role' => 'admin'];
    });
});

it('dispatches SyncUserJob with new_email key when email changes', function (): void {
    Bus::fake();

    $user = User::create([
        'name' => 'Test',
        'email' => 'old@example.com',
        'password' => Hash::make('pass'),
    ]);

    $user->email = 'new@example.com';
    $user->save();

    Bus::assertDispatched(SyncUserJob::class, function (SyncUserJob $job): bool {
        return $job->email === 'old@example.com'
            && $job->changedData === ['new_email' => 'new@example.com'];
    });
});

it('does not dispatch when unwatched field changes', function (): void {
    Bus::fake();

    $user = User::create([
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => Hash::make('pass'),
    ]);

    $user->name = 'Updated Name';
    $user->save();

    Bus::assertNotDispatched(SyncUserJob::class);
});

it('does not dispatch during receiving mode', function (): void {
    Bus::fake();

    app()->instance('user-team-sync.receiving', true);

    $user = User::create([
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => Hash::make('pass'),
        'role' => 'subscriber',
    ]);

    $user->role = 'admin';
    $user->save();

    Bus::assertNotDispatched(SyncUserJob::class);
});
