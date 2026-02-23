<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Events\VersionRecorded;
use SthiraLabs\VersionVault\Events\VersionRecording;
use SthiraLabs\VersionVault\Events\VersionReconstructed;
use SthiraLabs\VersionVault\Events\VersionRollback;
use SthiraLabs\VersionVault\Traits\HasVersioning;

beforeEach(function () {
    Schema::create('evt_projects', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    ensureVersionsTable();
});

class EvtProject extends Model
{
    use HasVersioning;

    protected $table = 'evt_projects';
    protected $guarded = [];
    public $timestamps = false;

    public function versioningConfig(): array
    {
        return ['name'];
    }
}

it('dispatches versioning events with expected payloads', function () {
    Event::fake([
        VersionRecording::class,
        VersionRecorded::class,
        VersionReconstructed::class,
        VersionRollback::class,
    ]);

    $project = EvtProject::create(['name' => 'Alpha']);

    $version = $project->recordVersion('created', ['source' => 'test']);

    Event::assertDispatched(VersionRecording::class, function (VersionRecording $event) use ($project) {
        return $event->model->is($project)
            && $event->action === 'created'
            && $event->meta['source'] === 'test';
    });

    Event::assertDispatched(VersionRecorded::class, function (VersionRecorded $event) use ($project, $version) {
        return $event->model->is($project)
            && $event->version->is($version);
    });

    $project->update(['name' => 'Beta']);
    $project->recordVersionIfChanged('updated');

    $project->reconstructVersion(1);
    Event::assertDispatched(VersionReconstructed::class, function (VersionReconstructed $event) use ($project) {
        return $event->originalModel->is($project)
            && $event->versionNumber === 1;
    });

    $project->rollbackToVersion(1);
    Event::assertDispatched(VersionRollback::class, function (VersionRollback $event) use ($project) {
        return $event->model->is($project)
            && $event->rolledBackTo === 1
            && $event->rollbackVersionEntry->version >= 1;
    });
});
