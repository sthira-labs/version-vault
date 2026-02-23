<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Traits\HasVersioning;
use SthiraLabs\VersionVault\Models\Version;

/*
|--------------------------------------------------------------------------
| Schema
|--------------------------------------------------------------------------
*/
beforeEach(function () {

    Schema::create('hv_projects', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('hv_tasks', function (Blueprint $t) {
        $t->id();
        $t->foreignId('project_id');
        $t->string('title');
    });

    Schema::create('hv_users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('hv_task_user', function (Blueprint $t) {
        $t->foreignId('hv_task_id');
        $t->foreignId('hv_user_id');
        $t->string('role');
    });

    ensureVersionsTable();
});

/*
|--------------------------------------------------------------------------
| Models
|--------------------------------------------------------------------------
*/
class HvProject extends Model {
    use HasVersioning;
    protected $table = 'hv_projects';
    protected $guarded = [];
    public $timestamps = false;

    public function tasks() {
        return $this->hasMany(HvTask::class, 'project_id');
    }

    public function versioningConfig(): array {
        return [
            'name',
            'tasks:title' => [
                'users:name,pivot(role)'
            ],
        ];
    }
}

class HvTask extends Model {
    protected $table = 'hv_tasks';
    protected $guarded = [];
    public $timestamps = false;

    public function users() {
        return $this->belongsToMany(HvUser::class, 'hv_task_user')
            ->withPivot('role');
    }
}

class HvUser extends Model {
    protected $table = 'hv_users';
    protected $guarded = [];
    public $timestamps = false;
}

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/

it('prevents versioning on unsaved model', function () {
    $project = new HvProject(['name' => 'Draft']);

    expect(fn () => $project->recordVersion())
        ->toThrow(LogicException::class);
});

it('records and rolls back deep relations correctly', function () {
    $project = HvProject::create(['name' => 'Alpha']);
    $task = $project->tasks()->create(['title' => 'Setup']);
    $user = HvUser::create(['name' => 'John']);

    $task->users()->attach($user->id, ['role' => 'dev']);
    $project->recordVersion('created');

    $project->update(['name' => 'Beta']);
    $task->update(['title' => 'Setup v2']);
    $task->users()->updateExistingPivot($user->id, ['role' => 'lead']);

    $project->recordVersionIfChanged('updated');

    $rollback = $project->rollbackToVersion(1);

    expect($rollback)->toBeInstanceOf(Version::class);
    expect($project->fresh()->name)->toBe('Alpha');

    $task = $project->fresh()->tasks->first();
    expect($task->title)->toBe('Setup');
    expect($task->users->first()->pivot->role)->toBe('dev');
});

it('reconstructs and rolls back across multiple versions (nested + pivot)', function () {
    $project = HvProject::create(['name' => 'Alpha']);
    $task1 = $project->tasks()->create(['title' => 'Setup']);
    $user1 = HvUser::create(['name' => 'John']);
    $task1->users()->attach($user1->id, ['role' => 'dev']);

    $v1 = $project->recordVersion('v1');

    $project->update(['name' => 'Beta']);
    $task1->update(['title' => 'Setup v2']);
    $task1->users()->updateExistingPivot($user1->id, ['role' => 'lead']);
    $v2 = $project->recordVersionIfChanged('v2');

    $task2 = $project->tasks()->create(['title' => 'Docs']);
    $user2 = HvUser::create(['name' => 'Mary']);
    $task2->users()->attach($user2->id, ['role' => 'qa']);
    $v3 = $project->recordVersionIfChanged('v3');

    $task1->delete();
    $v4 = $project->recordVersionIfChanged('v4');

    $task2->update(['title' => 'Docs v2']);
    $task2->users()->updateExistingPivot($user2->id, ['role' => 'lead']);
    $v5 = $project->recordVersionIfChanged('v5');

    expect($v1->version)->toBe(1)
        ->and($v2->version)->toBe(2)
        ->and($v3->version)->toBe(3)
        ->and($v4->version)->toBe(4)
        ->and($v5->version)->toBe(5);

    $r1 = $project->fresh()->reconstructVersion(1);
    expect($r1->name)->toBe('Alpha');
    expect($r1->tasks)->toHaveCount(1);
    expect($r1->tasks->first()->title)->toBe('Setup');
    expect($r1->tasks->first()->users->first()->pivot->role)->toBe('dev');

    $r2 = $project->fresh()->reconstructVersion(2);
    expect($r2->name)->toBe('Beta');
    expect($r2->tasks)->toHaveCount(1);
    expect($r2->tasks->first()->title)->toBe('Setup v2');
    expect($r2->tasks->first()->users->first()->pivot->role)->toBe('lead');

    $r3 = $project->fresh()->reconstructVersion(3);
    expect($r3->tasks)->toHaveCount(2);
    expect($r3->tasks->firstWhere('title', 'Docs'))->not()->toBeNull();
    expect($r3->tasks->firstWhere('title', 'Docs')->users->first()->pivot->role)->toBe('qa');

    $r4 = $project->fresh()->reconstructVersion(4);
    expect($r4->tasks)->toHaveCount(1);
    expect($r4->tasks->first()->title)->toBe('Docs');

    $r5 = $project->fresh()->reconstructVersion(5);
    expect($r5->tasks)->toHaveCount(1);
    expect($r5->tasks->first()->title)->toBe('Docs v2');
    expect($r5->tasks->first()->users->first()->pivot->role)->toBe('lead');

    $rollback = $project->rollbackToVersion(2);
    expect($rollback)->toBeInstanceOf(Version::class);
    expect($project->fresh()->name)->toBe('Beta');
    expect($project->fresh()->tasks)->toHaveCount(1);
    expect($project->fresh()->tasks->first()->title)->toBe('Setup v2');
    expect($project->fresh()->tasks->first()->users->first()->pivot->role)->toBe('lead');
    expect($project->versionNumber())->toBe(6);
});
