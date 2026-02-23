<?php

namespace SthiraLabs\VersionVault\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

interface Versionable
{
    /**
     * Return the versioning configuration for this model.
     *
     * This configuration will be normalized and used to:
     * - build snapshots
     * - detect diffs
     * - reconstruct historical state
     */
    public function versioningConfig(): array;

    /**
     * Relationship to version records.
     *
     * Typically a morphMany relation to the versions table.
     */
    public function versions(): MorphMany;
}