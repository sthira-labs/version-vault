<?php

namespace SthiraLabs\VersionVault\Models;

use Illuminate\Database\Eloquent\Model;

class Version extends Model
{
    public $timestamps = false;
    protected $table;

    protected $casts = [
        'diff' => 'array',
        'snapshot' => 'array',
        'changed_paths' => 'array',
        'meta' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('version-vault.table_name', 'version_vault_versions');
    }
}
