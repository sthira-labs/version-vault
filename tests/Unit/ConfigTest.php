<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('loads version-vault config with defaults', function () {
    expect(config('version-vault'))->toBeArray()
        ->and(config('version-vault.snapshot_interval'))->toBeInt()
        ->and(config('version-vault.store_empty'))->toBeBool()
        ->and(config('version-vault.debug'))->toBeBool()
        ->and(config('version-vault.debug_channel'))->toBeNull()
        ->and(config('version-vault.migrations'))->toBeBool()
        ->and(config('version-vault.table_name'))->toBeString()
        ->and(config('version-vault.model'))->toBeString()
        ->and(config('version-vault.bindings'))->toBeArray();
});

it('allows overriding debug settings via config', function () {
    config()->set('version-vault.debug', true);
    config()->set('version-vault.debug_channel', 'stderr');

    expect(config('version-vault.debug'))->toBeTrue()
        ->and(config('version-vault.debug_channel'))->toBe('stderr');
});

it('respects store_empty for recordVersionIfChanged', function () {
    Schema::create('cfg_projects', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    ensureVersionsTable();

    $project = CfgProject::create(['name' => 'Alpha']);
    $project->recordVersion('created');

    config()->set('version-vault.store_empty', false);
    $empty = $project->recordVersionIfChanged('noop');
    expect($empty)->toBeNull();

    config()->set('version-vault.store_empty', true);
    $stored = $project->recordVersionIfChanged('noop');
    expect($stored)->toBeInstanceOf(\SthiraLabs\VersionVault\Models\Version::class);
});

class CfgProject extends Model
{
    use \SthiraLabs\VersionVault\Traits\HasVersioning;

    protected $table = 'cfg_projects';
    protected $guarded = [];
    public $timestamps = false;

    public function versioningConfig(): array
    {
        return ['name'];
    }
}
