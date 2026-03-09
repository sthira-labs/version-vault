<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Contracts\Versionable;
use SthiraLabs\VersionVault\Models\Version;
use SthiraLabs\VersionVault\Traits\HasVersioning;

beforeEach(function () {
    Schema::create('prw_members', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('prw_programs', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('prw_benefits', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('prw_races', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('prw_member_program', function (Blueprint $t) {
        $t->foreignId('prw_member_id');
        $t->foreignId('prw_program_id');
        $t->date('effective_from')->nullable();
        $t->date('effective_till')->nullable();
        $t->boolean('override_fee')->default(false);
        $t->decimal('fee_amount', 10, 2)->nullable();
        $t->unsignedBigInteger('reporting_account_id')->nullable();
        $t->string('status')->nullable();
    });

    Schema::create('prw_benefit_member', function (Blueprint $t) {
        $t->foreignId('prw_member_id');
        $t->foreignId('prw_benefit_id');
        $t->decimal('benefit_amount', 10, 2)->nullable();
        $t->date('effective_from')->nullable();
        $t->date('effective_till')->nullable();
    });

    Schema::create('prw_member_race', function (Blueprint $t) {
        $t->foreignId('prw_member_id');
        $t->foreignId('prw_race_id');
        $t->string('other_race')->nullable();
    });

    Schema::create('prw_member_program_rows', function (Blueprint $t) {
        $t->id();
        $t->foreignId('member_id');
        $t->foreignId('program_id')->nullable();
        $t->date('effective_from')->nullable();
        $t->decimal('fee_amount', 10, 2)->nullable();
        $t->string('status')->nullable();
    });

    ensureVersionsTable();
});

class PrwProgram extends Model
{
    protected $table = 'prw_programs';
    protected $guarded = [];
    public $timestamps = false;
}

class PrwBenefit extends Model
{
    protected $table = 'prw_benefits';
    protected $guarded = [];
    public $timestamps = false;
}

class PrwRace extends Model
{
    protected $table = 'prw_races';
    protected $guarded = [];
    public $timestamps = false;
}

class PrwMemberPivotOnly extends Model implements Versionable
{
    use HasVersioning;

    protected $table = 'prw_members';
    protected $guarded = [];
    public $timestamps = false;

    public function programs()
    {
        return $this->belongsToMany(
            PrwProgram::class,
            'prw_member_program',
            'prw_member_id',
            'prw_program_id'
        )->withPivot([
            'effective_from',
            'effective_till',
            'override_fee',
            'fee_amount',
            'reporting_account_id',
            'status',
        ]);
    }

    public function benefits()
    {
        return $this->belongsToMany(
            PrwBenefit::class,
            'prw_benefit_member',
            'prw_member_id',
            'prw_benefit_id'
        )->withPivot(['benefit_amount', 'effective_from', 'effective_till']);
    }

    public function races()
    {
        return $this->belongsToMany(
            PrwRace::class,
            'prw_member_race',
            'prw_member_id',
            'prw_race_id'
        )->withPivot(['other_race']);
    }

    public function versioningConfig(): array
    {
        return [
            'name',
            'programs:pivot(effective_from,effective_till,override_fee,fee_amount,reporting_account_id,status)',
            'benefits:pivot(benefit_amount,effective_from,effective_till)',
            'races:pivot(other_race)',
        ];
    }
}

class PrwMemberHybrid extends PrwMemberPivotOnly
{
    public function versioningConfig(): array
    {
        return [
            'name',
            'programs:id,name,pivot(effective_from,effective_till,override_fee,fee_amount,reporting_account_id,status)',
            'benefits:id,name,pivot(benefit_amount,effective_from,effective_till)',
            'races:id,name,pivot(other_race)',
        ];
    }
}

class PrwMemberViaPivot extends Model implements Versionable
{
    use HasVersioning;

    protected $table = 'prw_members';
    protected $guarded = [];
    public $timestamps = false;

    public function memberPrograms()
    {
        return $this->hasMany(PrwMemberProgramRow::class, 'member_id');
    }

    public function versioningConfig(): array
    {
        return [
            'name',
            'memberPrograms:effective_from,fee_amount,status' => [
                'program' => true,
            ],
        ];
    }
}

class PrwMemberProgramRow extends Model
{
    protected $table = 'prw_member_program_rows';
    protected $guarded = [];
    public $timestamps = false;

    public function program()
    {
        return $this->belongsTo(PrwProgram::class, 'program_id');
    }
}

it('tracks pivot-only changes for programs, benefits, and races without snapshotting related attributes', function () {
    $program = PrwProgram::create(['name' => 'Gov Grant']);
    $benefit = PrwBenefit::create(['name' => 'Transport']);
    $race = PrwRace::create(['name' => 'Other']);

    $member = PrwMemberPivotOnly::create(['name' => 'Riya']);

    $member->programs()->attach($program->id, [
        'effective_from' => '2025-01-01',
        'effective_till' => '2025-12-31',
        'override_fee' => false,
        'fee_amount' => 1200,
        'reporting_account_id' => 10,
        'status' => 'active',
    ]);
    $member->benefits()->attach($benefit->id, [
        'benefit_amount' => 400,
        'effective_from' => '2025-01-01',
        'effective_till' => '2025-12-31',
    ]);
    $member->races()->attach($race->id, [
        'other_race' => 'South Asian',
    ]);

    $member->recordVersion('v1');

    // Related model attributes are not tracked in pivot-only configuration.
    $program->update(['name' => 'Gov Grant Renamed']);
    $benefit->update(['name' => 'Transport Plus']);
    $race->update(['name' => 'Updated Race Label']);

    $noVersion = $member->recordVersionIfChanged('related-model-renamed');
    expect($noVersion)->toBeNull();

    // Pivot updates should be tracked.
    $member->programs()->updateExistingPivot($program->id, ['fee_amount' => 1500, 'status' => 'paused']);
    $member->benefits()->updateExistingPivot($benefit->id, ['benefit_amount' => 450]);
    $member->races()->updateExistingPivot($race->id, ['other_race' => 'South Asian Updated']);

    $v2 = $member->recordVersionIfChanged('pivot-updated');

    expect($v2)->toBeInstanceOf(Version::class)
        ->and($v2->changed_paths)->toContain(
            "programs[{$program->id}].pivot.fee_amount",
            "programs[{$program->id}].pivot.status",
            "benefits[{$benefit->id}].pivot.benefit_amount",
            "races[{$race->id}].pivot.other_race"
        );

    $r1 = $member->fresh()->reconstructVersion(1, [
        'reconstruct_relations' => ['programs', 'benefits', 'races'],
        'attach_unloaded_relations' => true,
    ]);

    expect($r1->model->programs->first()->pivot->fee_amount)->toBe(1200)
        ->and($r1->model->programs->first()->pivot->status)->toBe('active')
        ->and($r1->model->benefits->first()->pivot->benefit_amount)->toBe(400)
        ->and($r1->model->races->first()->pivot->other_race)->toBe('South Asian')
        // No related attributes were snapshotted.
        ->and($r1->model->programs->first()->name)->toBeNull();
});

it('tracks both related attributes and pivot when configured in hybrid mode', function () {
    $program = PrwProgram::create(['name' => 'Housing Support']);
    $benefit = PrwBenefit::create(['name' => 'Meal Voucher']);
    $race = PrwRace::create(['name' => 'Undisclosed']);

    $member = PrwMemberHybrid::create(['name' => 'Asha']);

    $member->programs()->attach($program->id, [
        'effective_from' => '2025-01-01',
        'effective_till' => null,
        'override_fee' => true,
        'fee_amount' => 800,
        'reporting_account_id' => 22,
        'status' => 'active',
    ]);
    $member->benefits()->attach($benefit->id, [
        'benefit_amount' => 120,
        'effective_from' => '2025-01-01',
        'effective_till' => null,
    ]);
    $member->races()->attach($race->id, ['other_race' => 'Prefer not to say']);

    $member->recordVersion('v1');

    $program->update(['name' => 'Housing Support Plus']);
    $member->programs()->updateExistingPivot($program->id, ['fee_amount' => 999]);

    $v2 = $member->recordVersionIfChanged('hybrid-changes');

    expect($v2)->toBeInstanceOf(Version::class)
        ->and($v2->changed_paths)->toContain(
            "programs[{$program->id}].name",
            "programs[{$program->id}].pivot.fee_amount"
        );

    $r1 = $member->fresh()->reconstructVersion(1, [
        'reconstruct_relations' => ['programs', 'benefits', 'races'],
        'attach_unloaded_relations' => true,
    ]);

    expect($r1->model->programs->first()->name)->toBe('Housing Support')
        ->and($r1->model->programs->first()->pivot->fee_amount)->toBe(800);
});

it('supports hasMany pivot-model approach with nested program as reference', function () {
    $programA = PrwProgram::create(['name' => 'Program A']);
    $programB = PrwProgram::create(['name' => 'Program B']);

    $member = PrwMemberViaPivot::create(['name' => 'Kiran']);

    $row = $member->memberPrograms()->create([
        'program_id' => $programA->id,
        'effective_from' => '2025-02-01',
        'fee_amount' => 300,
        'status' => 'active',
    ]);

    $member->recordVersion('v1');

    // Nested belongsTo(program) is reference; program attribute-only update should not version.
    $programA->update(['name' => 'Program A Latest']);
    $noVersion = $member->recordVersionIfChanged('program-renamed-only');
    expect($noVersion)->toBeNull();

    $row->update(['fee_amount' => 350]);
    $v2 = $member->recordVersionIfChanged('row-fee-updated');

    expect($v2)->toBeInstanceOf(Version::class)
        ->and($v2->changed_paths)->toContain("memberPrograms[{$row->id}].fee_amount");

    $row->update(['program_id' => $programB->id]);
    $v3 = $member->recordVersionIfChanged('program-reference-switched');

    expect($v3)->toBeInstanceOf(Version::class)
        ->and($v3->changed_paths)->toContain("memberPrograms[{$row->id}].program_id");

    $r1 = $member->fresh()->reconstructVersion(1, [
        'reconstruct_relations' => ['memberPrograms'],
        'attach_unloaded_relations' => true,
    ]);

    $reconstructedRow = $r1->model->memberPrograms->first();

    expect($reconstructedRow->fee_amount)->toBe(300)
        ->and($reconstructedRow->program_id)->toBe($programA->id)
        ->and($reconstructedRow->relationLoaded('program'))->toBeFalse();

    // Reference relation can be loaded on demand from current DB state.
    $r1->model->loadMissing('memberPrograms.program');

    expect($r1->model->memberPrograms->first()->program->name)->toBe('Program A Latest');
});
