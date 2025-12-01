<?php

namespace Roshify\VersionVault;

use Illuminate\Support\ServiceProvider;

class VersionVaultServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Bind components, allow overrides (ChangeDetector, SnapshotBuilder, VersionManager, VersionResolver)
        // $this->app->bind(...);
    }

    public function boot()
    {
        // publish config, migrations
        $this->publishes([
            __DIR__ . '/../config/version-vault.php' => config_path('version-vault.php'),
        ], 'version-vault-config');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
