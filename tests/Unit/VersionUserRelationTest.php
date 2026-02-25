<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Models\Version;

/*
|--------------------------------------------------------------------------
| Schema
|--------------------------------------------------------------------------
*/
beforeEach(function () {
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    ensureVersionsTable();

    config(['version-vault.user_model' => VuUser::class]);
});

/*
|--------------------------------------------------------------------------
| Models (file-scoped)
|--------------------------------------------------------------------------
*/
class VuUser extends Model
{
    protected $table = 'users';
    protected $guarded = [];
    public $timestamps = false;
}

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/
it('loads the user relation via created_by', function () {
    $user = VuUser::create(['name' => 'Alice']);

    $version = new Version();
    $version->versionable_type = 'Asset';
    $version->versionable_id = 1;
    $version->version = 1;
    $version->created_by = $user->id;
    $version->save();

    $fresh = $version->fresh();

    expect($fresh->createdBy)->toBeInstanceOf(VuUser::class)
        ->and($fresh->createdBy->id)->toBe($user->id);
});
