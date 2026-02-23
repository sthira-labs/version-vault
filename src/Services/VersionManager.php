<?php

declare(strict_types=1);

namespace SthiraLabs\VersionVault\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SthiraLabs\VersionVault\Contracts\Versionable;
use SthiraLabs\VersionVault\Events\VersionReconstructed;
use SthiraLabs\VersionVault\Events\VersionRecorded;
use SthiraLabs\VersionVault\Events\VersionRecording;
use SthiraLabs\VersionVault\Events\VersionRollback;
use SthiraLabs\VersionVault\Models\Version;

/**
 * VersionManager
 *
 * Orchestrates version recording, reconstruction, comparison and rollback
 * using ONLY the public APIs of ConfigNormalizer, SnapshotBuilder,
 * ChangeDetector and VersionResolver.
 *
 * Key guarantees:
 * - Diff is always computed against reconstructed canonical state
 * - changed_paths are generated once (write-time) and persisted
 * - Snapshots are stored only at configured intervals or when forced
 * - Reconstruction is non-destructive by default
 */
class VersionManager
{
    public function __construct(
        protected ConfigNormalizer $configNormalizer,
        protected SnapshotBuilder  $snapshotBuilder,
        protected ChangeDetector   $changeDetector,
        protected VersionResolver  $versionResolver,
        protected HydrationPersister $hydrationPersister,
    ) {}

    /**
     * Record a version only if changes are detected.
     */
    public function recordVersionIfChanged(
        Versionable|Model $model,
        ?string $action = null,
        array $meta = [],
        bool $forceSnapshot = false,
    ): ?Version {
        $config = $this->configNormalizer->normalize(
            method_exists($model, 'versioningConfig') ? $model->versioningConfig() : []
        );

        $currentSnapshot = $this->snapshotBuilder->buildSnapshot($model, $config);

        $previousSnapshot = $this->resolveLatestSnapshot($model, $config);

        $diff = $this->changeDetector->diffSnapshots($previousSnapshot, $currentSnapshot);

        if ($this->changeDetector->isEmptyDiff($diff)) {
            $this->debug('recordVersionIfChanged.no_changes', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'action' => $action,
            ]);

            if (!config('version-vault.store_empty', false)) {
                return null;
            }
        }

        $changedPaths = $this->changeDetector->buildChangedPaths($diff);

        $version = $this->persistVersion(
            model: $model,
            diff: $diff,
            changedPaths: $changedPaths,
            snapshot: $this->shouldStoreSnapshot($model, $forceSnapshot)
                ? $currentSnapshot
                : null,
            action: $action,
            meta: $meta
        );

        $this->debug('recordVersionIfChanged.persisted', [
            'model' => $model::class,
            'id' => $model->getKey(),
            'version' => $version->version,
            'action' => $action,
            'changed_paths' => count($changedPaths),
            'snapshot' => $version->snapshot !== null,
        ]);

        return $version;
    }

    /**
     * Record a version unconditionally.
     */
    public function recordVersion(
        Versionable|Model $model,
        ?string $action = null,
        array $meta = [],
        bool $forceSnapshot = false,
    ): Version {
        $config = $this->configNormalizer->normalize(
            method_exists($model, 'versioningConfig') ? $model->versioningConfig() : []
        );

        $currentSnapshot = $this->snapshotBuilder->buildSnapshot($model, $config);
        $previousSnapshot = $this->resolveLatestSnapshot($model, $config);

        $diff = $this->changeDetector->diffSnapshots($previousSnapshot, $currentSnapshot);
        $changedPaths = $this->changeDetector->buildChangedPaths($diff);

        $version = $this->persistVersion(
            model: $model,
            diff: $diff,
            changedPaths: $changedPaths,
            snapshot: $this->shouldStoreSnapshot($model, $forceSnapshot)
                ? $currentSnapshot
                : null,
            action: $action,
            meta: $meta
        );

        $this->debug('recordVersion.persisted', [
            'model' => $model::class,
            'id' => $model->getKey(),
            'version' => $version->version,
            'action' => $action,
            'changed_paths' => count($changedPaths),
            'snapshot' => $version->snapshot !== null,
        ]);

        return $version;
    }

    /**
     * Force a snapshot-based version.
     */
    public function forceSnapshot(Model $model): Version
    {
        return $this->recordVersion($model, 'forced-snapshot', [], true);
    }

    /**
     * Reconstruct a specific historical version.
     */
    public function reconstructVersion(
        Versionable|Model $model,
        int $version,
        array $options = []
    ): Model|array {
        $this->debug('reconstruct.start', [
            'model' => $model::class,
            'id' => $model->getKey(),
            'version' => $version,
        ]);

        $row = $model->versions()->where('version', '<=', $version)->orderBy('version')->get();

        $baseSnapshot = null;
        $diffs = [];

        foreach ($row as $ver) {
            if ($ver->snapshot !== null) {
                $baseSnapshot = $ver->snapshot;
                $diffs = [];
                continue;
            }
            if ($ver->version <= $version) {
                $diffs[] = $ver->diff;
            }
        }

        $state = $this->versionResolver->applyDiffsToSnapshot($baseSnapshot, $diffs);

        $hydrateOptions = array_merge([
            'hydrate_loaded_relations_only' => false,
            'attach_unloaded_relations' => true,
        ], $options);

        $hydrated = $this->versionResolver->hydrateModelFromSnapshot(
            $model,
            $state,
            $hydrateOptions
        );

        event(new VersionReconstructed($model, $version, $hydrated));

        $this->debug('reconstruct.done', [
            'model' => $model::class,
            'id' => $model->getKey(),
            'version' => $version,
            'relations' => count($state['relations'] ?? []),
        ]);

        if (!($options['with_diff_paths'] ?? false)) {
            return $hydrated;
        }

        $versionRow = $model->versions()->where('version', $version)->first();

        return [
            'model' => $hydrated,
            'changed_paths' => $versionRow?->changed_paths ?? [],
            'diff' => $versionRow?->diff ?? [],
        ];
    }

    /**
     * Compare two versions.
     */
    public function compareVersions(
        Versionable|Model $model,
        int $fromVersion,
        int $toVersion
    ): array {
        $from = $this->reconstructCanonicalState($model, $fromVersion);
        $to = $this->reconstructCanonicalState($model, $toVersion);

        $diff = $this->changeDetector->diffSnapshots($from, $to);

        return [
            'diff' => $diff,
            'changed_paths' => $this->changeDetector->buildChangedPaths($diff),
        ];
    }

    /**
     * Rollback the model to a given version.
     */
    public function rollbackToVersion(
        Versionable|Model $model,
        int $version,
        array $options = []
    ): Version {
        return DB::transaction(function () use ($model, $version, $options) {
            $this->debug('rollback.start', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'version' => $version,
            ]);

            $state = $this->reconstructCanonicalState($model, $version);

            $hydrateOptions = array_merge([
                'hydrate_loaded_relations_only' => false,
                'attach_unloaded_relations' => true,
            ], $options, ['force_replace_relation' => true]);

            $hydrated = $this->versionResolver->hydrateModelFromSnapshot(
                $model,
                $state,
                $hydrateOptions
            );

            $this->hydrationPersister->persist($model, $hydrated, $state);

            $rollback = $this->recordVersion(
                $hydrated,
                'rollback',
                ['rollback_to' => $version]
            );

            event(new VersionRollback($model, $version, $rollback));

            $this->debug('rollback.done', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'version' => $version,
                'rollback_version' => $rollback->version,
            ]);

            return $rollback;
        });
    }

    /**
     * Delete all versions for the model.
     */
    public function clearVersions(Versionable|Model $model): void
    {
        $model->versions()->delete();
    }

    /**
     * Prune versions using retention rules.
     */
    public function pruneVersions(Versionable|Model $model, array $options = []): int
    {
        $query = $model->versions()->newQuery();

        if (isset($options['older_than_days'])) {
            $query->where('created_at', '<', now()->subDays($options['older_than_days']));
        }

        if (isset($options['keep_last'])) {
            $keepIds = $model->versions()
                ->latest('version')
                ->limit((int) $options['keep_last'])
                ->pluck('id');

            $query->whereNotIn('id', $keepIds);
        }

        return $query->delete();
    }

    /**
     * Resolve latest canonical snapshot for diffing.
     */
    protected function resolveLatestSnapshot(Model $model, array $config): ?array
    {
        $latestVersion = $model->versions()->max('version');

        if (! $latestVersion) {
            return null;
        }

        return $this->reconstructCanonicalUpTo($model, $latestVersion);
    }

    /**
     * Reconstruct canonical snapshot state at a specific version.
     */
    protected function reconstructCanonicalState(Model $model, int $version): array
    {
        return $this->reconstructCanonicalUpTo($model, $version);
    }

    /**
     * Get reconstructed snapshot upto given version.
     *
     * @param Model $model
     * @param integer $upToVersion
     * @return array
     */
    protected function reconstructCanonicalUpTo(
        Model $model,
        int $upToVersion
    ): array {
        // 1. Nearest snapshot ≤ target version
        $baseVersion = $model->versions()
            ->whereNotNull('snapshot')
            ->where('version', '<=', $upToVersion)
            ->orderByDesc('version')
            ->first();

        if (! $baseVersion) {
            throw new RuntimeException(
                "No snapshot found for version ≤ {$upToVersion}"
            );
        }

        // 2. Diffs after snapshot up to target
        $diffs = $model->versions()
            ->where('version', '>', $baseVersion->version)
            ->where('version', '<=', $upToVersion)
            ->whereNull('snapshot')
            ->orderBy('version')
            ->pluck('diff')
            ->all();

        // 3. Apply diffs
        return $this->versionResolver->applyDiffsToSnapshot(
            $baseVersion->snapshot,
            $diffs
        );
    }

    /**
     * Persist version entry atomically.
     */
    protected function persistVersion(
        Model $model,
        array $diff,
        array $changedPaths,
        ?array $snapshot,
        ?string $action,
        array $meta
    ): Version {
        event(new VersionRecording($model, $diff, $snapshot, $action, $meta));

        return DB::transaction(function () use ($model, $diff, $changedPaths, $snapshot, $action, $meta) {
            $version = new Version();
            $version->versionable_type = $model::class;
            $version->versionable_id = $model->getKey();
            $version->version = ((int)$model->versions()->max('version')) + 1;
            $version->diff = $diff;
            $version->snapshot = $snapshot;
            $version->changed_paths = $changedPaths;
            $version->action = $action;
            $version->meta = $meta;

            $version->save();

            event(new VersionRecorded($model, $version));

            return $version;
        });
    }

    /**
     * Decide whether to store a snapshot.
     */
    protected function shouldStoreSnapshot(Model $model, bool $forced): bool
    {
        if ($forced) {
            return true;
        }

        $interval = (int) config('version-vault.snapshot_interval', 10);
        if ($interval <= 0) {
            return false;
        }

        $last = $model->versions()->latest('version')->first();

        if (!$last) {
            return true;
        }

        return ($last->version % $interval) === 0;
    }

    protected function debug(string $event, array $context = []): void
    {
        if (!config('version-vault.debug', false)) {
            return;
        }

        $channel = config('version-vault.debug_channel');
        if (is_string($channel) && $channel !== '') {
            Log::channel($channel)->debug("VersionVault: {$event}", $context);
            return;
        }

        Log::debug("VersionVault: {$event}", $context);
    }
}
