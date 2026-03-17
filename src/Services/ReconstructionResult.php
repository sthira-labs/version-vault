<?php

namespace SthiraLabs\VersionVault\Services;

use Illuminate\Database\Eloquent\Model;

final class ReconstructionResult
{
    public function __construct(
        public readonly Model $model,
        public readonly array $changedPaths,
        public readonly array $diff
    ) {
    }

    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'changed_paths' => $this->changedPaths,
            'diff' => $this->diff,
        ];
    }
}
