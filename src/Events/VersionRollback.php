<?php

namespace SthiraLabs\VersionVault\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Model;
use SthiraLabs\VersionVault\Models\Version;

class VersionRollback
{
    use Dispatchable, SerializesModels;

    public Model $model;
    public int $rolledBackTo;
    public Version $rollbackVersionEntry;

    public function __construct(Model $model, int $rolledBackTo, Version $rollbackVersionEntry)
    {
        $this->model = $model;
        $this->rolledBackTo = $rolledBackTo;
        $this->rollbackVersionEntry = $rollbackVersionEntry;
    }
}
