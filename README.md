# roshify/version-vault

Version-vault — a Laravel package for model versioning (diff-first, snapshots, relation & pivot aware).

Goals
- Track attributes + relations (nested), including pivot attributes.
- Store minimal diffs and periodic snapshots.
- Reconstruct and rollback historical versions.

See the design & functional spec for details. :contentReference[oaicite:2]{index=2}

## Quick install (dev)
composer require roshify/version-vault:dev-main

## Development
- Run tests: composer test
- Local dev option: use provided docker-compose / Makefile or Laravel Sail

## License
MIT