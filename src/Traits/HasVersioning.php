<?php

namespace SthiraLabs\VersionVault\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;
use SthiraLabs\VersionVault\Services\VersionManager;
use SthiraLabs\VersionVault\Models\Version;

/**
 * HasVersioning
 *
 * Thin public API facade over VersionManager.
 *
 * Requirements for models using this trait:
 * - Must extend Eloquent Model
 * - Must implement: versioningConfig(): array
 */
trait HasVersioning
{
    /**
     * Morph relationship to version records.
     */
    public function versions(): MorphMany
    {
        return $this->morphMany(Version::class, 'versionable');
    }

    /**
     * Resolve VersionManager from container.
     */
    protected function versionManager(): VersionManager
    {
        return app(VersionManager::class);
    }

    /**
     * Guard: ensure model is persisted before versioning.
     */
    protected function ensureVersionable(): void
    {
        if (! $this->getKey()) {
            throw new LogicException(
                sprintf(
                    'Cannot version unsaved model [%s]. Save the model before versioning.',
                    static::class
                )
            );
        }

        if (! method_exists($this, 'versioningConfig')) {
            throw new LogicException(
                sprintf(
                    'Model [%s] must define versioningConfig() to use HasVersioning.',
                    static::class
                )
            );
        }
    }

    /**
     * Record a version unconditionally.
     */
    public function recordVersion(?string $action = null, array $meta = []): Version
    {
        $this->ensureVersionable();

        return $this->versionManager()
            ->recordVersion($this, $action, $meta);
    }

    /**
     * Record a version only if changes are detected.
     */
    public function recordVersionIfChanged(?string $action = null, array $meta = []): ?Version
    {
        $this->ensureVersionable();

        return $this->versionManager()
            ->recordVersionIfChanged($this, $action, $meta);
    }

    /**
     * Force a snapshot-based version.
     */
    public function forceSnapshot(): Version
    {
        $this->ensureVersionable();

        return $this->versionManager()
            ->forceSnapshot($this);
    }

    /**
     * Reconstruct a hydrated (non-persisted) model for a version.
     *
     * @param int $version
     * @param array $options
     *
     * @return \SthiraLabs\VersionVault\Services\ReconstructionResult
     */
    public function reconstructVersion(int $version, array $options = []): \SthiraLabs\VersionVault\Services\ReconstructionResult
    {
        $this->ensureVersionable();

        return $this->versionManager()
            ->reconstructVersion($this, $version, $options);
    }

    /**
     * Compare two versions and return diff + changed paths.
     */
    public function compareVersions(int $from, int $to): array
    {
        $this->ensureVersionable();

        return $this->versionManager()
            ->compareVersions($this, $from, $to);
    }

    /**
     * Rollback model to a prior version (persists & records rollback).
     */
    public function rollbackToVersion(int $version, array $options = []): Version
    {
        $this->ensureVersionable();

        return $this->versionManager()
            ->rollbackToVersion($this, $version, $options);
    }

    /**
     * Remove all versions for this model.
     */
    public function clearVersions(): void
    {
        $this->ensureVersionable();

        $this->versionManager()
            ->clearVersions($this);
    }

    /**
     * Prune versions using retention rules.
     */
    public function pruneVersions(array $options = []): int
    {
        $this->ensureVersionable();

        return $this->versionManager()
            ->pruneVersions($this, $options);
    }

    /**
     * Get current highest version number.
     */
    public function versionNumber(): int
    {
        return (int) ($this->versions()->max('version') ?? 0);
    }
}
