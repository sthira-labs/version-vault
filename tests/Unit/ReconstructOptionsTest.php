<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Contracts\Versionable;
use SthiraLabs\VersionVault\Traits\HasVersioning;

/*
|--------------------------------------------------------------------------
| Schema
|--------------------------------------------------------------------------
*/
beforeEach(function () {
    Schema::create('ro_users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('ro_profiles', function (Blueprint $t) {
        $t->id();
        $t->foreignId('user_id');
        $t->string('phone');
    });

    ensureVersionsTable();
});

/*
|--------------------------------------------------------------------------
| Models (file-scoped)
|--------------------------------------------------------------------------
*/
class RoUser extends Model implements Versionable
{
    use HasVersioning;

    protected $table = 'ro_users';
    protected $guarded = [];
    public $timestamps = false;

    public function profile()
    {
        return $this->hasOne(RoProfile::class, 'user_id');
    }

    public function versioningConfig(): array
    {
        return [
            'name',
            'profile:phone',
        ];
    }
}

class RoProfile extends Model
{
    protected $table = 'ro_profiles';
    protected $guarded = [];
    public $timestamps = false;
}

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/
it('respects reconstruct defaults to avoid hydrating unloaded relations', function () {
    config([
        'version-vault.reconstruct.hydrate_loaded_relations_only' => true,
        'version-vault.reconstruct.attach_unloaded_relations' => false,
    ]);

    $user = RoUser::create(['name' => 'Alex']);
    $user->profile()->create(['phone' => '111']);

    $user->recordVersion('created');

    // Do not load profile on the template model
    $user->unsetRelation('profile');
    $reconstructed = $user->reconstructVersion(1);

    expect($reconstructed->model->relationLoaded('profile'))->toBeFalse();
});
