<?php

namespace SthiraLabs\VersionVault\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Model;

class VersionRecording
{
    use Dispatchable, SerializesModels;

    public Model $model;
    public ?array $diff;
    public ?array $snapshot;
    public ?string $action;
    public array $meta;

    public function __construct(Model $model, ?array $diff, ?array $snapshot, ?string $action = null, array $meta = [])
    {
        $this->model = $model;
        $this->diff = $diff;
        $this->snapshot = $snapshot;
        $this->action = $action;
        $this->meta = $meta;
    }
}
