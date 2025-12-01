<?php

namespace Roshify\VersionVault\Traits;

trait HasVersioning
{
    /**
     * Return model versioning config (shorthand or canonical).
     *
     * Example:
     * public function versioningConfig(): array { return ['name', 'tasks:title,status' => ['assignedUser:name']]; }
     */
    public function versioningConfig(): array
    {
        return [];
    }

    // Boot trait (placeholder for model events)
    public static function bootHasVersioning()
    {
        // attach observers or model events to call recordVersion/storeVersionIfChanged
    }

    // API stubs:
    public function recordVersion(string $action = null, array $meta = []) {}
    public function storeVersionIfChanged(string $action = null, array $meta = []) {}
    public function reconstructVersion(int $version) {}
    public function rollbackToVersion(int $version, array $options = []) {}
}
