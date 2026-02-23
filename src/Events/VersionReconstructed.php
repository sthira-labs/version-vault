<?php

namespace SthiraLabs\VersionVault\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Model;

class VersionReconstructed
{
    use Dispatchable, SerializesModels;

    public Model $originalModel;
    public int $versionNumber;
    public Model $reconstructedModel;

    public function __construct(Model $originalModel, int $versionNumber, Model $reconstructedModel)
    {
        $this->originalModel = $originalModel;
        $this->versionNumber = $versionNumber;
        $this->reconstructedModel = $reconstructedModel;
    }
}
