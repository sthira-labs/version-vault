<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
    Schema::create('mm_assets', function (Blueprint $t) {
        $t->id();
        $t->foreignId('type_id')->nullable();
        $t->string('name');
    });

    Schema::create('mm_types', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    ensureVersionsTable();

    Relation::requireMorphMap();
    Relation::morphMap([
        'Asset' => MmAsset::class,
    ]);
});

afterEach(function () {
    Relation::requireMorphMap(false);
    Relation::morphMap([]);
});

/*
|--------------------------------------------------------------------------
| Models (file-scoped)
|--------------------------------------------------------------------------
*/
class MmAsset extends Model implements Versionable
{
    use HasVersioning;

    protected $table = 'mm_assets';
    protected $guarded = [];
    public $timestamps = false;

    public function assetType()
    {
        return $this->belongsTo(MmType::class, 'type_id');
    }

    public function versioningConfig(): array
    {
        return [
            'name',
            'assetType:name',
        ];
    }
}

class MmType extends Model
{
    protected $table = 'mm_types';
    protected $guarded = [];
    public $timestamps = false;
}

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/
it('stores morph alias in versionable_type and does not require mapping for related models', function () {
    $type = MmType::create(['name' => 'Bond']);
    $asset = MmAsset::create(['name' => 'Alpha', 'type_id' => $type->id]);

    $version = $asset->recordVersion('created');

    expect($version->versionable_type)->toBe('Asset');
    expect($version->snapshot['__meta']['alias'])->toBe('Asset');
    expect($version->snapshot['relations']['assetType']['data']['__meta']['alias'])
        ->toBe(MmType::class);
});
