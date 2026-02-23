<?php

return [
    'snapshot_interval' => 10,
    'store_empty' => false,
    // Enable structured debug logs for versioning operations
    'debug' => false,
    // Optional logging channel for versioning debug logs (null = default)
    'debug_channel' => null,
    'migrations' => true,
    'table_name' => 'version_vault_versions',
    'model' => \SthiraLabs\VersionVault\Models\Version::class,
    'bindings' => [
        // allow swapping components
        'change_detector' => null,
        'snapshot_builder' => null,
        'version_manager' => null,
        'version_resolver' => null,
        'config_normalizer' => null,
        'hydration_persister' => null,
    ],
];
