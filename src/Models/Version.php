<?php

namespace SthiraLabs\VersionVault\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as FoundationUser;

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

    public function createdBy(): BelongsTo
    {
        $model = config('version-vault.user_model')
            ?? config('auth.providers.users.model')
            ?? FoundationUser::class;

        return $this->belongsTo($model, 'created_by');
    }
}
