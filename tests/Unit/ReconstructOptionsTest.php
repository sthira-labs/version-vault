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
        $t->foreignId('team_id')->nullable();
    });

    Schema::create('ro_profiles', function (Blueprint $t) {
        $t->id();
        $t->foreignId('user_id');
        $t->string('phone');
    });

    Schema::create('ro_posts', function (Blueprint $t) {
        $t->id();
        $t->foreignId('user_id');
        $t->string('title');
    });

    Schema::create('ro_teams', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->foreignId('lead_id')->nullable();
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

    public function posts()
    {
        return $this->hasMany(RoPost::class, 'user_id');
    }

    public function team()
    {
        return $this->belongsTo(RoTeam::class, 'team_id');
    }

    public function versioningConfig(): array
    {
        return [
            'name',
            'team' => true,
            'profile:phone',
            'posts:title',
        ];
    }
}

class RoProfile extends Model
{
    protected $table = 'ro_profiles';
    protected $guarded = [];
    public $timestamps = false;
}

class RoPost extends Model
{
    protected $table = 'ro_posts';
    protected $guarded = [];
    public $timestamps = false;
}

class RoTeam extends Model
{
    protected $table = 'ro_teams';
    protected $guarded = [];
    public $timestamps = false;

    public function lead()
    {
        return $this->belongsTo(RoUser::class, 'lead_id');
    }
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

it('hydrates explicitly requested relations even if not loaded', function () {
    config([
        'version-vault.reconstruct.hydrate_loaded_relations_only' => true,
        'version-vault.reconstruct.attach_unloaded_relations' => false,
    ]);

    $user = RoUser::create(['name' => 'Alex']);
    $user->profile()->create(['phone' => '111']);

    $user->recordVersion('created');

    // Do not load profile on the template model
    $user->unsetRelation('profile');
    $reconstructed = $user->reconstructVersion(1, [
        'reconstruct_relations' => ['profile'],
    ]);

    expect($reconstructed->model->relationLoaded('profile'))->toBeTrue()
        ->and($reconstructed->model->profile->phone)->toBe('111');
});

it('skips non-allowed relations when reconstruct_relations is set', function () {
    config([
        'version-vault.reconstruct.hydrate_loaded_relations_only' => true,
        'version-vault.reconstruct.attach_unloaded_relations' => false,
    ]);

    $user = RoUser::create(['name' => 'Alex']);
    $user->profile()->create(['phone' => '111']);
    $user->posts()->create(['title' => 'Post A']);

    $user->recordVersion('created');

    // Do not load relations on the template model
    $user->unsetRelation('profile');
    $user->unsetRelation('posts');
    $reconstructed = $user->reconstructVersion(1, [
        'reconstruct_relations' => ['profile'],
    ]);

    expect($reconstructed->model->relationLoaded('profile'))->toBeTrue()
        ->and($reconstructed->model->relationLoaded('posts'))->toBeFalse();
});

it('loads reference relations after reconstruction using eager loading', function () {
    config([
        'version-vault.reconstruct.hydrate_loaded_relations_only' => true,
        'version-vault.reconstruct.attach_unloaded_relations' => false,
    ]);

    $lead = RoUser::create(['name' => 'Lead']);
    $team = RoTeam::create(['name' => 'Team A', 'lead_id' => $lead->id]);
    $user = RoUser::create(['name' => 'Alex', 'team_id' => $team->id]);

    $user->recordVersion('created');

    $user->unsetRelation('team');
    $reconstructed = $user->reconstructVersion(1);

    $reconstructed->model->load('team.lead');

    expect($reconstructed->model->relationLoaded('team'))->toBeTrue()
        ->and($reconstructed->model->team->name)->toBe('Team A')
        ->and($reconstructed->model->team->relationLoaded('lead'))->toBeTrue()
        ->and($reconstructed->model->team->lead->name)->toBe('Lead');
});

it('reconstructs with same reference and eager loads latest related data', function () {
    config([
        'version-vault.reconstruct.hydrate_loaded_relations_only' => true,
        'version-vault.reconstruct.attach_unloaded_relations' => false,
    ]);

    $team = RoTeam::create(['name' => 'Team A']);
    $user = RoUser::create(['name' => 'Alex', 'team_id' => $team->id]);

    $user->recordVersion('v1');

    // Update reference model (team) after v1
    $team->update(['name' => 'Team B']);

    // Update main model with same reference FK
    $user->update(['name' => 'Alex Updated']);
    $user->recordVersion('v2');

    // Reconstruct v2 and eager load reference relation
    $reconstructed = $user->reconstructVersion(2);
    $reconstructed->model->load('team');

    expect($reconstructed->model->team->name)->toBe('Team B');
});

it('uses prune_missing_many_relations config during reconstruction', function () {
    config([
        'version-vault.reconstruct.hydrate_loaded_relations_only' => true,
        'version-vault.reconstruct.attach_unloaded_relations' => false,
        'version-vault.reconstruct.prune_missing_many_relations' => false,
    ]);

    $user = RoUser::create(['name' => 'Alex']);
    $postA = $user->posts()->create(['title' => 'Post A']);
    $user->recordVersion('v1');

    $postB = $user->posts()->create(['title' => 'Post B']);
    $user->recordVersion('v2');

    // Template model reflects current (v2) and has loaded hasMany relation.
    $template = $user->fresh()->load('posts');
    $reconstructed = $template->reconstructVersion(1);

    expect($reconstructed->model->relationLoaded('posts'))->toBeTrue()
        // Latest-only items should not leak into historical reconstruction.
        ->and($reconstructed->model->posts->pluck('id')->sort()->values()->all())
        ->toBe([$postA->id]);
});
