<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Models\Version;
use SthiraLabs\VersionVault\Services\ReconstructionResult;
use SthiraLabs\VersionVault\Traits\HasVersioning;

beforeEach(function () {
    Schema::create('vm_projects', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
    });

    ensureVersionsTable();
});

class VmProject extends Model
{
    use HasVersioning;

    protected $table = 'vm_projects';
    protected $guarded = [];
    public $timestamps = false;

    public function versioningConfig(): array
    {
        return ['name'];
    }
}

it('records forced snapshots and returns diff paths on reconstruct', function () {
    config(['version-vault.snapshot_interval' => 10]);
    config(['version-vault.debug' => true, 'version-vault.debug_channel' => 'stderr']);

    $project = VmProject::create(['name' => 'A']);
    $forced = $project->forceSnapshot();

    expect($forced->snapshot)->not()->toBeNull();

    $project->update(['name' => 'B']);
    $project->recordVersionIfChanged('updated');

    $result = $project->reconstructVersion(2, ['with_diff_paths' => true]);

    $resultArray = $result->toArray();

    expect($result)->toBeInstanceOf(ReconstructionResult::class)
        ->and($result->changedPaths)->toContain('name')
        ->and($result->diff)->toBeArray()
        ->and($result->model)->toBeInstanceOf(VmProject::class);

    expect($resultArray['model'])->toBeInstanceOf(VmProject::class)
        ->and($resultArray['changed_paths'])->toContain('name')
        ->and($resultArray['diff'])->toBeArray();
});

it('uses reconstruct config to include diff paths when option is omitted', function () {
    config(['version-vault.snapshot_interval' => 10]);
    config(['version-vault.reconstruct.with_diff_paths' => true]);

    $project = VmProject::create(['name' => 'A']);
    $project->forceSnapshot();
    $project->update(['name' => 'B']);
    $project->recordVersionIfChanged('updated');

    $result = $project->reconstructVersion(2);

    expect($result)->toBeInstanceOf(ReconstructionResult::class)
        ->and($result->changedPaths)->toContain('name')
        ->and($result->diff)->toBeArray()
        ->and($result->model)->toBeInstanceOf(VmProject::class);
});

it('allows reconstruct option to override config', function () {
    config(['version-vault.snapshot_interval' => 10]);
    config(['version-vault.reconstruct.with_diff_paths' => true]);

    $project = VmProject::create(['name' => 'A']);
    $project->forceSnapshot();
    $project->update(['name' => 'B']);
    $project->recordVersionIfChanged('updated');

    $result = $project->reconstructVersion(2, ['with_diff_paths' => false]);

    expect($result)->toBeInstanceOf(ReconstructionResult::class)
        ->and($result->model)->toBeInstanceOf(VmProject::class)
        ->and($result->changedPaths)->toBe([])
        ->and($result->diff)->toBe([]);
});

it('compares versions when snapshots exist', function () {
    config(['version-vault.snapshot_interval' => 1]);

    $project = VmProject::create(['name' => 'One']);
    $project->recordVersion('v1');
    $project->update(['name' => 'Two']);
    $project->recordVersion('v2');

    $comparison = $project->compareVersions(1, 2);

    expect($comparison['diff']['attributes']['name']['to'])->toBe('Two')
        ->and($comparison['changed_paths'])->toContain('name');
});

it('throws when no snapshot exists for comparison', function () {
    config(['version-vault.snapshot_interval' => 0]);

    $project = VmProject::create(['name' => 'X']);
    $project->recordVersion('v1');
    $project->update(['name' => 'Y']);

    $version = new Version();
    $version->versionable_type = Illuminate\Database\Eloquent\Relations\Relation::getMorphAlias(VmProject::class);
    $version->versionable_id = $project->id;
    $version->version = 2;
    $version->diff = ['attributes' => ['name' => ['from' => 'X', 'to' => 'Y']]];
    $version->snapshot = null;
    $version->changed_paths = ['name'];
    $version->action = 'manual';
    $version->meta = [];
    $version->save();

    expect(fn () => $project->compareVersions(1, 2))
        ->toThrow(RuntimeException::class);
});

it('does not store snapshots when interval is disabled', function () {
    config(['version-vault.snapshot_interval' => 0]);

    $project = VmProject::create(['name' => 'NoSnap']);
    $project->recordVersion('v1');

    $version = Version::where('versionable_id', $project->id)->first();
    expect($version->snapshot)->toBeNull();
});

it('stores snapshots for recordVersionIfChanged when interval allows', function () {
    config(['version-vault.snapshot_interval' => 1]);

    $project = VmProject::create(['name' => 'Snap']);
    $version = $project->recordVersionIfChanged('v1');

    expect($version)->not()->toBeNull()
        ->and($version->snapshot)->not()->toBeNull();
});

it('prunes versions by age and logs without a channel', function () {
    config(['version-vault.snapshot_interval' => 1]);
    config(['version-vault.debug' => true, 'version-vault.debug_channel' => '']);

    $project = VmProject::create(['name' => 'Prune']);
    $v1 = $project->recordVersion('v1');
    $v1->created_at = now()->subDays(10);
    $v1->save();

    $v2 = $project->recordVersion('v2');
    $v2->created_at = now();
    $v2->save();

    $deleted = $project->pruneVersions(['older_than_days' => 1]);

    expect($deleted)->toBeGreaterThanOrEqual(1);
});
