<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Contracts\Versionable;
use SthiraLabs\VersionVault\Traits\HasVersioning;

beforeEach(function () {
    Schema::create('nrlm_identification_types', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('nrlm_identifiers', function (Blueprint $t) {
        $t->id();
        $t->string('value');
        $t->foreignId('identification_type_id')->nullable();
    });

    Schema::create('nrlm_entities', function (Blueprint $t) {
        $t->id();
        $t->string('title');
        $t->foreignId('identifier_id')->nullable();
    });

    ensureVersionsTable();
});

class NrlmIdentificationType extends Model
{
    protected $table = 'nrlm_identification_types';
    protected $guarded = [];
    public $timestamps = false;
}

class NrlmIdentifier extends Model
{
    protected $table = 'nrlm_identifiers';
    protected $guarded = [];
    public $timestamps = false;

    public function identificationType()
    {
        return $this->belongsTo(NrlmIdentificationType::class, 'identification_type_id');
    }
}

class NrlmEntity extends Model implements Versionable
{
    use HasVersioning;

    protected $table = 'nrlm_entities';
    protected $guarded = [];
    public $timestamps = false;

    public function identifier()
    {
        return $this->belongsTo(NrlmIdentifier::class, 'identifier_id');
    }

    public function versioningConfig(): array
    {
        return [
            'title',
            'identifier' => [
                'mode' => 'snapshot',
                'attributes' => ['value', 'identification_type_id'],
                'relations' => [
                    // default reference mode for nested relation
                    'identificationType' => true,
                ],
            ],
        ];
    }
}

it('keeps reconstructed parent relation when using loadMissing for nested reference relation', function () {
    $typeA = NrlmIdentificationType::create(['name' => 'Type A']);
    $typeB = NrlmIdentificationType::create(['name' => 'Type B']);

    $identifier = NrlmIdentifier::create([
        'value' => 'ID-V1',
        'identification_type_id' => $typeA->id,
    ]);

    $entity = NrlmEntity::create([
        'title' => 'Entity',
        'identifier_id' => $identifier->id,
    ]);

    $entity->recordVersion('v1');

    $identifier->update([
        'value' => 'ID-V2',
        'identification_type_id' => $typeB->id,
    ]);

    $entity->recordVersion('v2');

    $reconstructed = $entity->fresh()->reconstructVersion(1, [
        'reconstruct_relations' => ['identifier'],
        'attach_unloaded_relations' => true,
    ]);

    expect($reconstructed->model->relationLoaded('identifier'))->toBeTrue()
        ->and($reconstructed->model->identifier->value)->toBe('ID-V1')
        ->and($reconstructed->model->identifier->relationLoaded('identificationType'))->toBeFalse();

    $reconstructed->model->loadMissing('identifier.identificationType');

    expect($reconstructed->model->identifier->value)->toBe('ID-V1')
        ->and($reconstructed->model->identifier->relationLoaded('identificationType'))->toBeTrue()
        ->and($reconstructed->model->identifier->identificationType->id)->toBe($typeA->id);
});

it('reloads and replaces reconstructed parent relation when using load for nested reference relation', function () {
    $typeA = NrlmIdentificationType::create(['name' => 'Type A']);
    $typeB = NrlmIdentificationType::create(['name' => 'Type B']);

    $identifier = NrlmIdentifier::create([
        'value' => 'ID-V1',
        'identification_type_id' => $typeA->id,
    ]);

    $entity = NrlmEntity::create([
        'title' => 'Entity',
        'identifier_id' => $identifier->id,
    ]);

    $entity->recordVersion('v1');

    $identifier->update([
        'value' => 'ID-V2',
        'identification_type_id' => $typeB->id,
    ]);

    $entity->recordVersion('v2');

    $reconstructed = $entity->fresh()->reconstructVersion(1, [
        'reconstruct_relations' => ['identifier'],
        'attach_unloaded_relations' => true,
    ]);

    expect($reconstructed->model->identifier->value)->toBe('ID-V1');

    $reconstructed->model->load('identifier.identificationType');

    expect($reconstructed->model->identifier->value)->toBe('ID-V2')
        ->and($reconstructed->model->identifier->identificationType->id)->toBe($typeB->id);
});
