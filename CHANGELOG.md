# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog
and this project adheres to Semantic Versioning.

---

## [Unreleased]
### Added
- Ongoing improvements and upcoming features.

---

## [1.1.0] - 2026-05-12

### Added
- Optional `?int $createdBy` override on `recordVersion`, `recordVersionIfChanged`, and `forceSnapshot` (on both `VersionManager` and the `HasVersioning` trait). Falls back to `Auth::id()` when null. Useful from queue jobs and system actors where the request-time user is not available.

### Changed
- `reconstructVersion` now delegates to the same canonical-reconstruction path as `compareVersions` and `rollbackToVersion`, eliminating an inconsistency where the three reconstruction paths handled missing snapshots differently.
- `reconstructCanonicalUpTo` now supports timelines that contain no snapshot at all (e.g., when `snapshot_interval=0`) by replaying the full diff timeline from version 1. Previously this case threw `RuntimeException`. The throw is preserved for the case where no versions exist at or below the requested target, with a clearer "No versions found" message.

### Fixed
- README PHP/Laravel requirements now match `composer.json` (PHP 8.1+, Laravel 9+).
- Renamed the documented `Version::user()` relation to `Version::createdBy()` to match the actual method.

### Internal
- Removed a brittle test helper (`changeDetectorProxy()`) whose proxy class was defined in a different test file; the single caller now constructs the proxy directly.

---

## [1.0.0] - 2026-03-17

### Added
- Initial stable release of Version Vault.
- Core versioning infrastructure and storage schema.
- `HasVersioning` trait for integrating version tracking into models.
- `Version` model for managing version records.
- Migration for versions table.
- Configurable package setup.
- Laravel service provider for seamless integration.

### Notes
- This is the first stable public release 🎉
- Designed to be flexible and easily extendable for different versioning use cases.

---

[1.1.0]: https://github.com/sthira-labs/version-vault/releases/tag/v1.1.0
[1.0.0]: https://github.com/sthira-labs/version-vault/releases/tag/v1.0.0