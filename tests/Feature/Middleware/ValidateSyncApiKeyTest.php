<?php

declare(strict_types=1);

use Madbox99\UserTeamSync\Tests\Fixtures\User;

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
    $this->postJson('/api/create-user', [], [
        'Authorization' => 'Bearer test-api-key',
    ])->assertStatus(422);
});

it('rejects when api key is not configured', function (): void {
    config()->set('user-team-sync.receiver.api_key', null);

    $this->postJson('/api/create-user', [], [
        'Authorization' => 'Bearer test-api-key',
    ])->assertStatus(401);
});
