<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Models\Version;
use SthiraLabs\VersionVault\Traits\HasVersioning;

beforeEach(function () {
    Schema::create('rm_users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('rm_categories', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('rm_projects', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->foreignId('owner_id')->nullable();
        $t->foreignId('manager_id')->nullable();
        $t->foreignId('category_id')->nullable();
    });

    Schema::create('rm_profiles', function (Blueprint $t) {
        $t->id();
        $t->foreignId('project_id');
        $t->string('bio')->nullable();
    });

    Schema::create('rm_tasks', function (Blueprint $t) {
        $t->id();
        $t->foreignId('project_id');
        $t->string('title');
    });

    Schema::create('rm_tags', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('rm_project_tag', function (Blueprint $t) {
        $t->foreignId('rm_project_id');
        $t->foreignId('rm_tag_id');
        $t->integer('order')->nullable();
    });

    ensureVersionsTable();
});

class RmUser extends Model
{
    protected $table = 'rm_users';
    protected $guarded = [];
    public $timestamps = false;
}

class RmCategory extends Model
{
    protected $table = 'rm_categories';
    protected $guarded = [];
    public $timestamps = false;
}

class RmProject extends Model
{
    use HasVersioning;

    protected $table = 'rm_projects';
    protected $guarded = [];
    public $timestamps = false;

    public function owner()
    {
        return $this->belongsTo(RmUser::class, 'owner_id');
    }

    public function manager()
    {
        return $this->belongsTo(RmUser::class, 'manager_id');
    }

    public function category()
    {
        return $this->belongsTo(RmCategory::class, 'category_id');
    }

    public function profile()
    {
        return $this->hasOne(RmProfile::class, 'project_id');
    }

    public function tasks()
    {
        return $this->hasMany(RmTask::class, 'project_id');
    }

    public function tags()
    {
        return $this->belongsToMany(
            RmTag::class,
            'rm_project_tag',
            'rm_project_id',
            'rm_tag_id'
        )->withPivot('order');
    }

    public function versioningConfig(): array
    {
        return [
            'name',
            // Reference mode (default): only FK should be tracked
            'owner' => true,
            'manager' => true,
            // Snapshot mode (implicit because fields are listed)
            'category:name',
            'profile:bio',
            'tasks:title',
            'tags:name,pivot(order)',
        ];
    }
}

class RmProfile extends Model
{
    protected $table = 'rm_profiles';
    protected $guarded = [];
    public $timestamps = false;
}

class RmTask extends Model
{
    protected $table = 'rm_tasks';
    protected $guarded = [];
    public $timestamps = false;
}

class RmTag extends Model
{
    protected $table = 'rm_tags';
    protected $guarded = [];
    public $timestamps = false;
}

it('tracks belongsTo via foreign keys in reference mode', function () {
    $owner = RmUser::create(['name' => 'Owner A']);
    $manager = RmUser::create(['name' => 'Manager A']);
    $category = RmCategory::create(['name' => 'Cat A']);

    $project = RmProject::create([
        'name' => 'Proj',
        'owner_id' => $owner->id,
        'manager_id' => $manager->id,
        'category_id' => $category->id,
    ]);

    $project->recordVersion('v1');

    $owner->update(['name' => 'Owner A+']);
    $manager->update(['name' => 'Manager A+']);

    $version = $project->recordVersionIfChanged('owner-manager-updated');

    expect($version)->toBeNull();

    $project->update(['owner_id' => $manager->id]);
    $version = $project->recordVersionIfChanged('owner-changed');

    expect($version)->toBeInstanceOf(Version::class)
        ->and($version->changed_paths)->toContain('owner_id');
});

it('tracks snapshot relations and reconstructs from stored data', function () {
    $owner = RmUser::create(['name' => 'Owner A']);
    $manager = RmUser::create(['name' => 'Manager A']);
    $category = RmCategory::create(['name' => 'Cat A']);
    $tag = RmTag::create(['name' => 'Tag A']);

    $project = RmProject::create([
        'name' => 'Proj',
        'owner_id' => $owner->id,
        'manager_id' => $manager->id,
        'category_id' => $category->id,
    ]);

    $project->profile()->create(['bio' => 'Bio A']);
    $task = $project->tasks()->create(['title' => 'Task A']);
    $project->tags()->attach($tag->id, ['order' => 1]);

    $project->recordVersion('v1');

    $category->update(['name' => 'Cat B']);
    $project->profile->update(['bio' => 'Bio B']);
    $project->tasks()->first()->update(['title' => 'Task B']);
    $project->tags()->updateExistingPivot($tag->id, ['order' => 2]);

    $version = $project->recordVersionIfChanged('snapshot-updated');

    expect($version)->toBeInstanceOf(Version::class)
        ->and($version->changed_paths)->toContain(
            'category.name',
            'profile.bio',
            "tasks[{$task->id}].title"
        )
        ->and($version->diff['relations']['tags']['updated'][$tag->id]['pivot']['attributes']['order']['to'])->toBe(2);

    $reconstructed = $project->fresh()->reconstructVersion(1, [
        'reconstruct_relations' => ['category', 'profile', 'tasks', 'tags'],
        'attach_unloaded_relations' => true,
    ]);

    expect($reconstructed->model->category->name)->toBe('Cat A')
        ->and($reconstructed->model->profile->bio)->toBe('Bio A')
        ->and($reconstructed->model->tasks->first()->title)->toBe('Task A')
        ->and($reconstructed->model->tags->first()->pivot->order)->toBe(1);
});
