<?php

namespace SthiraLabs\VersionVault;

use Illuminate\Support\ServiceProvider;
use SthiraLabs\VersionVault\Services\ChangeDetector;
use SthiraLabs\VersionVault\Services\ConfigNormalizer;
use SthiraLabs\VersionVault\Services\HydrationPersister;
use SthiraLabs\VersionVault\Services\SnapshotBuilder;
use SthiraLabs\VersionVault\Services\VersionManager;
use SthiraLabs\VersionVault\Services\VersionResolver;

class VersionVaultServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/version-vault.php',
            'version-vault'
        );

        $bindings = config('version-vault.bindings', []);

        $this->app->singleton(
            ConfigNormalizer::class,
            $bindings['config_normalizer'] ?? ConfigNormalizer::class
        );
        $this->app->singleton(
            SnapshotBuilder::class,
            $bindings['snapshot_builder'] ?? SnapshotBuilder::class
        );
        $this->app->singleton(
            ChangeDetector::class,
            $bindings['change_detector'] ?? ChangeDetector::class
        );
        $this->app->singleton(
            VersionResolver::class,
            $bindings['version_resolver'] ?? VersionResolver::class
        );
        $this->app->singleton(
            HydrationPersister::class,
            $bindings['hydration_persister'] ?? HydrationPersister::class
        );

        $managerBinding = $bindings['version_manager'] ?? null;
        if ($managerBinding) {
            $this->app->singleton(VersionManager::class, $managerBinding);
            return;
        }

        $this->app->singleton(VersionManager::class, function ($app) {
            return new VersionManager(
                $app->make(ConfigNormalizer::class),
                $app->make(SnapshotBuilder::class),
                $app->make(ChangeDetector::class),
                $app->make(VersionResolver::class),
                $app->make(HydrationPersister::class)
            );
        });
    }

    public function boot()
    {
        // publish config, migrations
        $this->publishes([
            __DIR__ . '/config/version-vault.php' => config_path('version-vault.php'),
        ], 'version-vault-config');

        if (config('version-vault.migrations', true)) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }
    }
}
