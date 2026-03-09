<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Contracts\Versionable;
use SthiraLabs\VersionVault\Traits\HasVersioning;

beforeEach(function () {
    Schema::create('srr_relations', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('code')->nullable();
    });

    Schema::create('srr_mains', function (Blueprint $t) {
        $t->id();
        $t->string('title');
        $t->string('note')->nullable();
        $t->unsignedBigInteger('relation_id')->nullable();
    });

    ensureVersionsTable();
});

class SrrRelation extends Model
{
    protected $table = 'srr_relations';
    protected $guarded = [];
    public $timestamps = false;
}

class SrrMain extends Model implements Versionable
{
    use HasVersioning;

    protected $table = 'srr_mains';
    protected $guarded = [];
    public $timestamps = false;

    public function relation()
    {
        return $this->belongsTo(SrrRelation::class, 'relation_id');
    }

    public function versioningConfig(): array
    {
        return [
            'title',
            'note',
            // Default mode is reference, so only FK tracking is expected.
            'relation' => true,
        ];
    }
}

class SrrMainExplicitReference extends SrrMain
{
    public function versioningConfig(): array
    {
        return [
            'title',
            'note',
            'relation' => [
                'mode' => 'reference',
                'attributes' => [],
                'relations' => [],
                'pivot' => [],
            ],
        ];
    }
}

class SrrMainAutomaticReference extends SrrMain
{
    public function versioningConfig(): array
    {
        return [
            'title',
            'note',
            'relation' => true,
        ];
    }
}

class SrrMainManualFkTracking extends SrrMain
{
    public function versioningConfig(): array
    {
        return [
            'title',
            'note',
            'relation_id',
        ];
    }
}

it('reconstructs single reference-mode relation using FK history with default options', function () {
    config([
        'version-vault.reconstruct.with_diff_paths' => true,
        'version-vault.reconstruct.prune_missing_many_relations' => false,
    ]);

    $relA = SrrRelation::create(['name' => 'Relation A', 'code' => 'A1']);
    $relB = SrrRelation::create(['name' => 'Relation B', 'code' => 'B1']);

    $main = SrrMain::create([
        'title' => 'Main',
        'relation_id' => $relA->id,
    ]);

    $v1 = $main->recordVersion('v1-initial');

    // Reference relation attribute change should not create a version.
    $relA->update(['name' => 'Relation A Updated', 'code' => 'A2']);
    $noop = $main->recordVersionIfChanged('v1-related-attrs-updated');
    expect($noop)->toBeNull();

    $main->update(['relation_id' => $relB->id]);
    $v2 = $main->recordVersionIfChanged('v2-fk-changed');

    expect($v2)->not->toBeNull()
        ->and($v2->changed_paths)->toContain('relation_id');

    // Reconstruct v1 with defaults only (configured with_diff_paths=true).
    $r1 = $main->fresh()->reconstructVersion(1);
    expect($r1->model->relation_id)->toBe($relA->id)
        ->and($r1->changedPaths)->toBeArray();

    // In reference mode, related attributes are resolved from current DB state.
    $r1->model->load('relation');
    expect($r1->model->relation->name)->toBe('Relation A Updated')
        ->and($r1->model->relation->code)->toBe('A2');

    // Reconstruct v2 to verify FK switch is reflected.
    $r2 = $main->fresh()->reconstructVersion(2);
    expect($r2->model->relation_id)->toBe($relB->id)
        ->and($r2->changedPaths)->toContain('relation_id');
});

it('reconstructs v1 null note and fk with explicit reference mode after v2 fills both', function () {
    config(['version-vault.reconstruct.with_diff_paths' => true]);

    $rel = SrrRelation::create(['name' => 'Rel', 'code' => 'R1']);

    $main = SrrMainExplicitReference::create([
        'title' => 'Main',
        'note' => null,
        'relation_id' => null,
    ]);

    $main->recordVersion('v1-empty');

    $main->update([
        'note' => 'filled',
        'relation_id' => $rel->id,
    ]);
    $v2 = $main->recordVersionIfChanged('v2-filled');

    expect($v2)->not->toBeNull()
        ->and($v2->changed_paths)->toContain('note', 'relation_id');

    $r1 = $main->fresh()->reconstructVersion(1);
    expect($r1->model->note)->toBeNull()
        ->and($r1->model->relation_id)->toBeNull();

    $r2 = $main->fresh()->reconstructVersion(2);
    expect($r2->model->note)->toBe('filled')
        ->and($r2->model->relation_id)->toBe($rel->id);
});

it('reconstructs v1 null note and fk with automatic reference mode after v2 fills both', function () {
    config(['version-vault.reconstruct.with_diff_paths' => true]);

    $rel = SrrRelation::create(['name' => 'Rel', 'code' => 'R1']);

    $main = SrrMainAutomaticReference::create([
        'title' => 'Main',
        'note' => null,
        'relation_id' => null,
    ]);

    $main->recordVersion('v1-empty');

    $main->update([
        'note' => 'filled',
        'relation_id' => $rel->id,
    ]);
    $v2 = $main->recordVersionIfChanged('v2-filled');

    expect($v2)->not->toBeNull()
        ->and($v2->changed_paths)->toContain('note', 'relation_id');

    $r1 = $main->fresh()->reconstructVersion(1);
    expect($r1->model->note)->toBeNull()
        ->and($r1->model->relation_id)->toBeNull();

    $r2 = $main->fresh()->reconstructVersion(2);
    expect($r2->model->note)->toBe('filled')
        ->and($r2->model->relation_id)->toBe($rel->id);
});

it('reconstructs v1 null note and fk with manual fk tracking after v2 fills both', function () {
    config(['version-vault.reconstruct.with_diff_paths' => true]);

    $rel = SrrRelation::create(['name' => 'Rel', 'code' => 'R1']);

    $main = SrrMainManualFkTracking::create([
        'title' => 'Main',
        'note' => null,
        'relation_id' => null,
    ]);

    $main->recordVersion('v1-empty');

    $main->update([
        'note' => 'filled',
        'relation_id' => $rel->id,
    ]);
    $v2 = $main->recordVersionIfChanged('v2-filled');

    expect($v2)->not->toBeNull()
        ->and($v2->changed_paths)->toContain('note', 'relation_id');

    $r1 = $main->fresh()->reconstructVersion(1);
    expect($r1->model->note)->toBeNull()
        ->and($r1->model->relation_id)->toBeNull();

    $r2 = $main->fresh()->reconstructVersion(2);
    expect($r2->model->note)->toBe('filled')
        ->and($r2->model->relation_id)->toBe($rel->id);
});
