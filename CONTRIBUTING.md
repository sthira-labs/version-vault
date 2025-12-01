# Contributing to roshify/version-vault

Thanks for wanting to contribute! This document explains the process we prefer contributors to follow.

## Code of conduct
Please follow a standard professional code of conduct — be respectful and constructive.

## How to contribute

### 1. Fork & branch
- Fork the repo (or create a branch on `roshify/version-vault` with permission).
- Branch naming: `feature/<name>`, `fix/<short-desc>`, `chore/<desc>`.

### 2. Commit style
Use conventional commits:
- `feat:`, `fix:`, `chore:`, `docs:`, `test:`, `refactor:`.
Keep commits small and focused.

### 3. Run tests & linters locally
- Use the `test` script (Pest).
- Run static analysis and code style checks before sending PR.

### 4. PR checklist
Before opening a PR:
- Ensure tests pass locally: `composer test`.
- Add or update tests covering new/changed behavior.
- Update README/CHANGELOG if behavior or API changed.
- Ensure code follows PSR-12 standards.

When creating a PR:
- Link to any relevant issue(s).
- Provide a short description of what changed and why.
- Set the PR target to `main` (or appropriate release branch).

### 5. Review process
- At least one approving review required.
- CI must be green before merge.
- Squash & merge or rebase/merge according to team preference.

### 6. Adding tests
- Use Pest for tests and Orchestra Testbench for Laravel integration.
- Prefer SQLite in-memory for fast tests.
- Add tests under `tests/` and follow existing patterns.

### 7. Releases
- Follow semantic versioning.
- Update CHANGELOG and tag releases using `vX.Y.Z`.

### 8. Local development
For fast iteration, link the package into a Laravel app (use `repositories.type=path` in your app's composer.json with `symlink: true`).

### 9. Contact
If unsure, open an issue or reach one of the maintainers for guidance.
