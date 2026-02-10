<?php

declare(strict_types=1);

use Madbox99\UserTeamSync\Models\SyncApp;

it('converts to app array format', function (): void {
    $app = SyncApp::query()->create([
        'name' => 'crm',
        'url' => 'https://crm.example.com',
        'api_key' => 'secret-key',
        'is_active' => true,
    ]);

    $array = $app->toAppArray();

    expect($array)->toBe([
        'url' => 'https://crm.example.com',
        'api_key' => 'secret-key',
        'active' => true,
    ]);
});

it('casts is_active to boolean', function (): void {
    $app = SyncApp::query()->create([
        'name' => 'shop',
        'url' => 'https://shop.example.com',
        'is_active' => true,
    ]);

    expect($app->is_active)->toBeTrue()->toBeBool();
});

it('encrypts api_key in database', function (): void {
    $app = SyncApp::query()->create([
        'name' => 'crm',
        'url' => 'https://crm.example.com',
        'api_key' => 'my-secret',
    ]);

    // Raw database value should not be plain text
    $raw = \Illuminate\Support\Facades\DB::table('sync_apps')
        ->where('id', $app->id)
        ->value('api_key');

    expect($raw)->not->toBe('my-secret');

    // But accessing via model should decrypt
    expect($app->fresh()->api_key)->toBe('my-secret');
});

it('allows null api_key', function (): void {
    $app = SyncApp::query()->create([
        'name' => 'basic',
        'url' => 'https://basic.example.com',
        'api_key' => null,
    ]);

    expect($app->api_key)->toBeNull();

    $array = $app->toAppArray();
    expect($array['api_key'])->toBeNull();
});

it('uses configurable table name', function (): void {
    $app = new SyncApp;

    expect($app->getTable())->toBe('sync_apps');

    config()->set('user-team-sync.publisher.apps_table', 'custom_apps');
    expect($app->getTable())->toBe('custom_apps');
});
