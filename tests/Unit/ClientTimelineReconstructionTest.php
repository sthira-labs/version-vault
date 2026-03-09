<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Contracts\Versionable;
use SthiraLabs\VersionVault\Traits\HasVersioning;

beforeEach(function () {
    Schema::create('ct_branches', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('ct_clients', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->unsignedBigInteger('branch_id')->nullable();
    });

    Schema::create('ct_other_identifiers', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('client_id');
        $t->string('identity_code');
        $t->string('identity_type');
    });

    Schema::create('ct_client_profiles', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('client_id');
        $t->string('label')->nullable();
        $t->unsignedBigInteger('external_id')->nullable();
    });

    ensureVersionsTable();
});

class CtBranch extends Model
{
    protected $table = 'ct_branches';
    protected $guarded = [];
    public $timestamps = false;
}

class CtClient extends Model implements Versionable
{
    use HasVersioning;

    protected $table = 'ct_clients';
    protected $guarded = [];
    public $timestamps = false;

    public function branch()
    {
        return $this->belongsTo(CtBranch::class, 'branch_id');
    }

    public function otherIdentifiers()
    {
        return $this->hasMany(CtOtherIdentifier::class, 'client_id');
    }

    public function profile()
    {
        return $this->hasOne(CtClientProfile::class, 'client_id');
    }

    public function versioningConfig(): array
    {
        return [
            'name',
            'branch:name',
            'otherIdentifiers:identity_code,identity_type',
            'profile:label,external_id',
        ];
    }
}

class CtOtherIdentifier extends Model
{
    protected $table = 'ct_other_identifiers';
    protected $guarded = [];
    public $timestamps = false;
}

class CtClientProfile extends Model
{
    protected $table = 'ct_client_profiles';
    protected $guarded = [];
    public $timestamps = false;
}

it('reconstructs hasOne nullable string and fk fields back to null at v1', function () {
    $branch = CtBranch::create(['name' => 'Branch A']);

    $client = CtClient::create([
        'name' => 'Client One',
        'branch_id' => $branch->id,
    ]);

    $profile = $client->profile()->create([
        'label' => null,
        'external_id' => null,
    ]);

    $client->load('profile');
    $client->recordVersion('v1-profile-empty');

    $profile->update([
        'label' => 'PHOTO-123',
        'external_id' => 99,
    ]);

    $v2 = $client->recordVersionIfChanged('v2-profile-filled');
    expect($v2)->not->toBeNull();

    // Reconstruct from latest-loaded template to ensure stale loaded relation
    // is overwritten by target snapshot state.
    $client->load('profile');
    $r1 = $client->reconstructVersion(1)->model;
    // $r2 = $client->reconstructVersion(2)->model;

    Log::debug('r1', [$r1]);
    // Log::debug('r2', [$r2]);

    expect($r1->profile)->not->toBeNull()
        ->and($r1->profile->label)->toBeNull()
        ->and($r1->profile->external_id)->toBeNull();
});

dataset('client_timelines', [
    'mixed update/remove/add with single relation changes' => ['timeline_a'],
    'sequential add then sequential delete' => ['timeline_b'],
]);

it('reconstructs each version stage correctly for many and single relations', function (string $timeline) {
    config([
        'version-vault.reconstruct.prune_missing_many_relations' => false,
    ]);

    $branchA = CtBranch::create(['name' => 'Branch A']);
    $branchB = CtBranch::create(['name' => 'Branch B']);

    $client = CtClient::create([
        'name' => 'Client One',
        'branch_id' => $branchA->id,
    ]);

    $expectations = [];

    if ($timeline === 'timeline_a') {
        $idA = $client->otherIdentifiers()->create([
            'identity_code' => 'RID-001',
            'identity_type' => 'Remittance',
        ]);

        $client->recordVersion('v1-initial');
        $expectations[1] = ['codes' => ['RID-001'], 'branch' => 'Branch A'];

        $idB = $client->otherIdentifiers()->create([
            'identity_code' => 'REC-001',
            'identity_type' => 'Reconciliation',
        ]);
        $client->recordVersion('v2-add-b');
        $expectations[2] = ['codes' => ['REC-001', 'RID-001'], 'branch' => 'Branch A'];

        $idA->delete();
        $client->recordVersion('v3-delete-a');
        // prune=false: version with delete should include deleted identifier
        $expectations[3] = ['codes' => ['REC-001', 'RID-001'], 'branch' => 'Branch A'];

        $client->update(['name' => 'Client One Updated']);
        $client->recordVersion('v4-name-only');
        // previous version tombstone must not leak here
        $expectations[4] = ['codes' => ['REC-001'], 'branch' => 'Branch A'];

        $client->otherIdentifiers()->create([
            'identity_code' => 'NEW-001',
            'identity_type' => 'New Type',
        ]);
        $client->recordVersion('v5-add-c');
        $expectations[5] = ['codes' => ['NEW-001', 'REC-001'], 'branch' => 'Branch A'];

        $client->update(['branch_id' => $branchB->id]);
        $client->recordVersion('v6-branch-change');
        $expectations[6] = ['codes' => ['NEW-001', 'REC-001'], 'branch' => 'Branch B'];
    } else {
        $client->recordVersion('v1-empty');
        $expectations[1] = ['codes' => [], 'branch' => 'Branch A'];

        $id1 = $client->otherIdentifiers()->create([
            'identity_code' => 'SEQ-001',
            'identity_type' => 'Type A',
        ]);
        $client->recordVersion('v2-add-1');
        $expectations[2] = ['codes' => ['SEQ-001'], 'branch' => 'Branch A'];

        $id2 = $client->otherIdentifiers()->create([
            'identity_code' => 'SEQ-002',
            'identity_type' => 'Type B',
        ]);
        $client->recordVersion('v3-add-2');
        $expectations[3] = ['codes' => ['SEQ-001', 'SEQ-002'], 'branch' => 'Branch A'];

        $id1->delete();
        $client->recordVersion('v4-delete-1');
        // version containing delete should include deleted + existing
        $expectations[4] = ['codes' => ['SEQ-001', 'SEQ-002'], 'branch' => 'Branch A'];

        $id2->delete();
        $client->recordVersion('v5-delete-2');
        // only deletion in this version should appear, not previous delete tombstone
        $expectations[5] = ['codes' => ['SEQ-002'], 'branch' => 'Branch A'];

        $client->update(['branch_id' => $branchB->id]);
        $client->recordVersion('v6-branch-change');
        // no identifier tombstone should persist to next version
        $expectations[6] = ['codes' => [], 'branch' => 'Branch B'];
    }

    foreach ($expectations as $version => $expected) {
        $reconstructed = $client->fresh()->reconstructVersion($version, [
            'reconstruct_relations' => ['branch', 'otherIdentifiers'],
            'hydrate_loaded_relations_only' => false,
            'attach_unloaded_relations' => true,
            'prune_missing_many_relations' => false,
        ])->model;

        $codes = $reconstructed->otherIdentifiers->pluck('identity_code')->sort()->values()->all();
        expect($codes)->toBe($expected['codes']);

        $branchName = $reconstructed->branch?->name;
        expect($branchName)->toBe($expected['branch']);
    }
})->with('client_timelines');
