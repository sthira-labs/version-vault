<?php

namespace SthiraLabs\VersionVault\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Model;
use SthiraLabs\VersionVault\Models\Version;

class VersionRecorded
{
    use Dispatchable, SerializesModels;

    public Model $model;
    public Version $version;

    public function __construct(Model $model, Version $version)
    {
        $this->model = $model;
        $this->version = $version;
    }
}
