<?php

use Illuminate\Support\ServiceProvider;
use SthiraLabs\VersionVault\Services\VersionManager;
use SthiraLabs\VersionVault\VersionVaultServiceProvider;

class CustomVersionManager extends VersionManager
{
    public function __construct() {}
}

it('honors custom version manager binding', function () {
    config(['version-vault.bindings' => [
        'version_manager' => CustomVersionManager::class,
    ]]);

    $provider = new VersionVaultServiceProvider(app());
    $provider->register();

    expect(app()->make(VersionManager::class))->toBeInstanceOf(CustomVersionManager::class);
});

it('boots with migrations enabled without error', function () {
    config(['version-vault.migrations' => true]);

    $provider = new VersionVaultServiceProvider(app());
    $provider->boot();

    expect(true)->toBeTrue();
});
