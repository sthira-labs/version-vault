<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Models\Version;
use SthiraLabs\VersionVault\Traits\HasVersioning;

beforeEach(function () {
    Schema::create('rr_assets', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('rr_budgets', function (Blueprint $t) {
        $t->id();
        $t->foreignId('asset_id')->nullable();
        $t->integer('amount')->default(0);
    });

    ensureVersionsTable();
});

class RrAsset extends Model
{
    protected $table = 'rr_assets';
    protected $guarded = [];
    public $timestamps = false;
}

class RrBudget extends Model
{
    use HasVersioning;

    protected $table = 'rr_budgets';
    protected $guarded = [];
    public $timestamps = false;

    public function asset()
    {
        return $this->belongsTo(RrAsset::class, 'asset_id');
    }

    public function versioningConfig(): array
    {
        return [
            'amount',
            'asset' => true,
        ];
    }
}

it('does not version when related attributes change in reference mode', function () {
    $asset = RrAsset::create(['name' => 'Asset A']);
    $budget = RrBudget::create(['amount' => 1000, 'asset_id' => $asset->id]);

    $budget->recordVersion('v1');

    $asset->update(['name' => 'Asset A+']);

    $version = $budget->recordVersionIfChanged('asset-updated');

    expect($version)->toBeNull();
});

it('versions when foreign key changes in reference mode', function () {
    $assetA = RrAsset::create(['name' => 'Asset A']);
    $assetB = RrAsset::create(['name' => 'Asset B']);
    $budget = RrBudget::create(['amount' => 1000, 'asset_id' => $assetA->id]);

    $budget->recordVersion('v1');

    $budget->update(['asset_id' => $assetB->id]);
    $version = $budget->recordVersionIfChanged('asset-changed');

    expect($version)->toBeInstanceOf(Version::class)
        ->and($version->changed_paths)->toContain('asset_id');
});
