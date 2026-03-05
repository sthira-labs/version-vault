# VersionVault (sthira-labs/version-vault)

VersionVault is a Laravel package for model versioning that is diff-first, snapshot-assisted, and relation-aware. It captures minimal diffs, supports nested relations (including pivot data), and can reconstruct or rollback historical state.

## Features
- Diff-first storage with periodic snapshots
- Nested relations (single, collection, pivot)
- Deterministic changed paths for audits and UI diffs
- Rollback support with persisted changes
- Optional created-by tracking and user relation
- Morph map friendly (stores morph alias when configured)

## Requirements
- PHP 8.2+
- Laravel 11+ (tested with Laravel 12)

## Installation
```bash
composer require sthira-labs/version-vault
```

## Publish & Migrate
Publish the config and migrations, then run migrations:
```bash
php artisan vendor:publish --provider="SthiraLabs\\VersionVault\\VersionVaultServiceProvider"
php artisan migrate
```

To publish only the config:
```bash
php artisan vendor:publish --provider="SthiraLabs\\VersionVault\\VersionVaultServiceProvider" --tag=config
```

To publish only the migrations:
```bash
php artisan vendor:publish --provider="SthiraLabs\\VersionVault\\VersionVaultServiceProvider" --tag=migrations
```

## Quick Start
```php
use SthiraLabs\VersionVault\Traits\HasVersioning;

class Project extends Model
{
    use HasVersioning;

    public function tasks() { return $this->hasMany(Task::class); }

    public function versioningConfig(): array
    {
        return [
            'name',
            'tasks:title' => [
                'users:name,pivot(role)'
            ],
        ];
    }
}

$project->recordVersion('created');
$project->recordVersionIfChanged('updated');

$result = $project->reconstructVersion(2);
$historic = $result->model;
$rollback = $project->rollbackToVersion(2);
```

Reconstruction is non-destructive by default: existing loaded relations on the template model are preserved, and only attributes present in the snapshot are applied. Relations not present in the snapshot remain untouched unless you enable `attach_unloaded_relations` or `force_replace_relation`.
`reconstructVersion()` always returns a `ReconstructionResult` DTO with:
- `model` (Model): the reconstructed model instance
- `changedPaths` (array): populated only when diff paths are enabled
- `diff` (array): populated only when diff paths are enabled

## Configuration
Config file: `config/version-vault.php`

- `snapshot_interval` (int): store a full snapshot every N versions (default 10)
- `store_empty` (bool): allow storing a version even when no changes are detected (default false)
- `debug` (bool): enable structured debug logs (default false)
- `debug_channel` (string|null): log channel to use for debug logs (default null)
- `migrations` (bool): auto-load package migrations (default true)
- `table_name` (string): versions table name
- `model` (class-string): Version model class
- `user_model` (class-string|null): model used for `Version::user()` relation (defaults to `auth.providers.users.model`)
- `reconstruct` (array): default reconstruction options
- `reconstruct.hydrate_loaded_relations_only` (bool): only hydrate relations already loaded on the template model (default true)
- `reconstruct.preserve_missing_attributes` (bool): keep template attributes not present in the snapshot (default true)
- `reconstruct.attach_unloaded_relations` (bool): build relations even when not loaded (default false)
- `reconstruct.force_replace_relation` (bool): replace relation objects rather than update in-place (default false)
- `reconstruct.with_diff_paths` (bool): include diff + changed paths in reconstruction result (default false)
- `bindings` (array): override internal services (change detector, snapshot builder, resolver, manager, etc.)

## Version Records (created_by)
If an authenticated user exists, `created_by` is recorded on each version.  
The `Version` model exposes a `user()` relation that resolves via `version-vault.user_model` or falls back to `auth.providers.users.model`.

## Snapshot & Diff Format
See `docs/NEW_NODE_FORMAT.md` for the exact snapshot/diff schema and examples.

## Events
VersionVault dispatches lifecycle events you can listen to:
- `SthiraLabs\VersionVault\Events\VersionRecording`
- `SthiraLabs\VersionVault\Events\VersionRecorded`
- `SthiraLabs\VersionVault\Events\VersionReconstructed`
- `SthiraLabs\VersionVault\Events\VersionRollback`

Details and payloads are documented in `docs/EVENTS.md`.

## Useful Commands
- Install package: `composer require sthira-labs/version-vault`
- Publish config: `php artisan vendor:publish --provider="SthiraLabs\\VersionVault\\VersionVaultServiceProvider" --tag=config`
- Publish migrations: `php artisan vendor:publish --provider="SthiraLabs\\VersionVault\\VersionVaultServiceProvider" --tag=migrations`
- Run migrations: `php artisan migrate`
- Run tests: `composer test`

## Development
Local dev option: use the provided docker-compose / Makefile or Laravel Sail.

## License
MIT
