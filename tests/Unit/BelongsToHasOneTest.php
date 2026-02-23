<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Models\Version;
use SthiraLabs\VersionVault\Traits\HasVersioning;

beforeEach(function () {
    Schema::create('bt_statuses', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('bt_users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->foreignId('status_id')->nullable();
    });

    Schema::create('bt_profiles', function (Blueprint $t) {
        $t->id();
        $t->foreignId('user_id');
        $t->string('phone');
    });

    ensureVersionsTable();
});

class BtStatus extends Model
{
    protected $table = 'bt_statuses';
    protected $guarded = [];
    public $timestamps = false;
}

class BtUser extends Model
{
    use HasVersioning;

    protected $table = 'bt_users';
    protected $guarded = [];
    public $timestamps = false;

    public function status()
    {
        return $this->belongsTo(BtStatus::class, 'status_id');
    }

    public function profile()
    {
        return $this->hasOne(BtProfile::class, 'user_id');
    }

    public function versioningConfig(): array
    {
        return [
            'name',
            'status_id',
            'status:name',
            'profile:phone',
        ];
    }
}

class BtProfile extends Model
{
    protected $table = 'bt_profiles';
    protected $guarded = [];
    public $timestamps = false;
}

it('records, reconstructs, and rolls back belongsTo + hasOne changes', function () {
    $open = BtStatus::create(['name' => 'Open']);
    $closed = BtStatus::create(['name' => 'Closed']);

    $user = BtUser::create(['name' => 'Alice', 'status_id' => $open->id]);
    $user->profile()->create(['phone' => '111']);

    $v1 = $user->recordVersion('v1');

    $user->update(['status_id' => $closed->id]);
    $user->profile->update(['phone' => '222']);

    $v2 = $user->recordVersionIfChanged('v2');

    expect($v1->version)->toBe(1);
    expect($v2)->toBeInstanceOf(Version::class);
    expect($v2->changed_paths)->toContain('status_id', 'status.name', 'profile.phone');

    $r1 = $user->fresh()->reconstructVersion(1);
    expect($r1->status->name)->toBe('Open');
    expect($r1->profile->phone)->toBe('111');

    $rollback = $user->rollbackToVersion(1);
    expect($rollback)->toBeInstanceOf(Version::class);
    expect($user->fresh()->status->name)->toBe('Open');
    expect($user->fresh()->profile->phone)->toBe('111');
});
