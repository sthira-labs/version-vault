<?php

use Illuminate\Foundation\Auth\User as FoundationUser;
use SthiraLabs\VersionVault\Models\Version;

class VmModelUser extends FoundationUser
{
    protected $table = 'vm_users';
    public $timestamps = false;
    protected $guarded = [];
}

it('uses configured table name and user model for createdBy', function () {
    config(['version-vault.table_name' => 'custom_versions']);
    config(['version-vault.user_model' => VmModelUser::class]);

    $version = new Version();

    expect($version->getTable())->toBe('custom_versions')
        ->and(get_class($version->createdBy()->getRelated()))->toBe(VmModelUser::class);
});

it('falls back to auth provider user model when version-vault user_model is missing', function () {
    config(['version-vault.user_model' => null]);
    config(['auth.providers.users.model' => VmModelUser::class]);

    $version = new Version();

    expect(get_class($version->createdBy()->getRelated()))->toBe(VmModelUser::class);
});
