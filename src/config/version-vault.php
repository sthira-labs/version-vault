<?php

return [
    'snapshot_interval' => 10,
    'store_empty' => false,
    'migrations' => true,
    'table_name' => 'version_vault_versions',
    'model' => \Roshify\VersionVault\Models\Version::class,
    'bindings' => [
        // allow swapping components
        'change_detector' => null,
        'snapshot_builder' => null,
        'version_manager' => null,
        'version_resolver' => null,
    ],
];
