<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Snapshot Interval
    |--------------------------------------------------------------------------
    |
    | Store a full snapshot every N versions. Set to 0 to disable snapshots
    | entirely and store only diffs.
    |
    */
    'snapshot_interval' => 10,

    /*
    |--------------------------------------------------------------------------
    | Store Empty Versions
    |--------------------------------------------------------------------------
    |
    | When true, a version entry is stored even if no changes are detected.
    |
    */
    'store_empty' => false,

    // Enable structured debug logs for versioning operations
    'debug' => false,
    // Optional logging channel for versioning debug logs (null = default)
    'debug_channel' => null,

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | When true, the package will auto-load its migrations.
    |
    */
    'migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Table Name
    |--------------------------------------------------------------------------
    |
    | The database table used to store version records.
    |
    */
    'table_name' => 'version_vault_versions',

    /*
    |--------------------------------------------------------------------------
    | Version Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model class used for version records.
    |
    */
    'model' => \SthiraLabs\VersionVault\Models\Version::class,

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | Model used for Version::user() relationship. If null, the package will
    | fall back to auth.providers.users.model.
    |
    */
    // Model used for Version::user() relationship (falls back to auth.providers.users.model)
    'user_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Reconstruction Defaults
    |--------------------------------------------------------------------------
    |
    | Default hydration behavior when reconstructing versions. These can be
    | overridden per call via reconstructVersion(..., $options).
    |
    */
    // Default reconstruction behavior (can be overridden per call)
    'reconstruct' => [
        // Only hydrate relations already loaded on the template model
        'hydrate_loaded_relations_only' => true,
        // Preserve attributes that are missing from the snapshot
        'preserve_missing_attributes' => true,
        // If true, build relations even when not loaded on the template model
        'attach_unloaded_relations' => false,
        // If true, replace relation objects rather than update in-place
        'force_replace_relation' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Bindings
    |--------------------------------------------------------------------------
    |
    | Override internal services (change detector, snapshot builder, resolver,
    | manager, etc.) by providing your own class strings.
    |
    */
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
