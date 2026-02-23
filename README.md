# sthira-labs/version-vault

VersionVault is a Laravel package for model versioning: diff-first, snapshot-assisted, relation- and pivot-aware.

## Features
- Track model attributes and nested relations.
- Support for collection and pivot relations with pivot attributes.
- Store minimal diffs and periodic snapshots.
- Reconstruct historical versions or rollback and persist.
- Deterministic changed paths for audits and UI diffs.

## Installation (dev)
```bash
composer require sthira-labs/version-vault:dev-main
```

## Usage
```php
use SthiraLabs\VersionVault\Traits\HasVersioning;

class Project extends Model {
    use HasVersioning;

    public function tasks() { return $this->hasMany(Task::class); }

    public function versioningConfig(): array {
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

$historic = $project->reconstructVersion(2);
$rollback = $project->rollbackToVersion(2);
```

## Configuration
Config file: `config/version-vault.php` (published via service provider)

- `snapshot_interval` (int): store a full snapshot every N versions (default 10)
- `store_empty` (bool): allow storing a version even when no changes are detected (default false)
- `debug` (bool): enable structured debug logs (default false)
- `debug_channel` (string|null): log channel to use for debug logs (default null)
- `migrations` (bool): auto-load package migrations (default true)
- `table_name` (string): versions table name
- `model` (class-string): Version model class
- `bindings` (array): override internal services (change detector, snapshot builder, resolver, manager, etc.)

## Snapshot & Diff Format
See `docs/NEW_NODE_FORMAT.md` for the exact snapshot/diff schema and examples.

## Events
VersionVault dispatches lifecycle events you can listen to:
- `SthiraLabs\VersionVault\Events\VersionRecording`
- `SthiraLabs\VersionVault\Events\VersionRecorded`
- `SthiraLabs\VersionVault\Events\VersionReconstructed`
- `SthiraLabs\VersionVault\Events\VersionRollback`

Details and payloads are documented in `docs/EVENTS.md`.

## Development
- Run tests: `composer test`
- Local dev option: use provided docker-compose / Makefile or Laravel Sail

## License
MIT
