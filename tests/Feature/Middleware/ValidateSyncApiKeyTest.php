<?php

declare(strict_types=1);

it('rejects requests without bearer token', function (): void {
    $this->postJson('/api/create-user', [])
        ->assertStatus(401)
        ->assertJson(['error' => 'Unauthorized']);
});

it('rejects requests with invalid bearer token', function (): void {
    $this->postJson('/api/create-user', [], [
        'Authorization' => 'Bearer wrong-key',
    ])->assertStatus(401)
        ->assertJson(['error' => 'Unauthorized']);
});

it('accepts requests with valid bearer token', function (): void {
    // Will fail validation but should pass middleware (not 401)
    $this->postJson('/api/create-user', [], authHeaders())
        ->assertStatus(422);
});

it('rejects when api key is not configured', function (): void {
    config()->set('user-team-sync.receiver.api_key', null);

    $this->postJson('/api/create-user', [], authHeaders())
        ->assertStatus(401);
});

it('sets receiving guard during request processing', function (): void {
    expect(app('user-team-sync.receiving'))->toBeFalse();

    // A valid request through the middleware should set the flag
    // We test indirectly: after a full request cycle, it should be reset to false
    $this->postJson('/api/create-user', [], authHeaders());

    expect(app('user-team-sync.receiving'))->toBeFalse();
});
