<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Contracts\Versionable;
use SthiraLabs\VersionVault\Services\VersionManager;
use SthiraLabs\VersionVault\Traits\HasVersioning;
use SthiraLabs\VersionVault\Models\Version;

/*
|--------------------------------------------------------------------------
| Schema
|--------------------------------------------------------------------------
*/
beforeEach(function () {

    Schema::create('vm_users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('vm_profiles', function (Blueprint $t) {
        $t->id();
        $t->foreignId('user_id');
        $t->string('phone');
    });

    Schema::create('vm_addresses', function (Blueprint $t) {
        $t->id();
        $t->foreignId('profile_id');
        $t->string('city');
    });

    Schema::create('vm_posts', function (Blueprint $t) {
        $t->id();
        $t->string('title');
    });

    Schema::create('vm_comments', function (Blueprint $t) {
        $t->id();
        $t->foreignId('post_id');
        $t->string('body');
    });

    Schema::create('vm_tags', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('vm_post_tag', function (Blueprint $t) {
        $t->foreignId('vm_post_id');
        $t->foreignId('vm_tag_id');
        $t->integer('order')->nullable();
    });

    ensureVersionsTable();
});

/*
|--------------------------------------------------------------------------
| Models (file-scoped)
|--------------------------------------------------------------------------
*/
class VmUser extends Model implements Versionable {
    use HasVersioning;
    protected $table = 'vm_users';
    protected $guarded = [];
    public $timestamps = false;

    public function profile() {
        return $this->hasOne(VmProfile::class, 'user_id');
    }

    public function versioningConfig(): array {
        return [
            'name',
            'profile:phone' => [
                'address:city'
            ],
        ];
    }
}

class VmProfile extends Model {
    protected $table = 'vm_profiles';
    protected $guarded = [];
    public $timestamps = false;

    public function address() {
        return $this->hasOne(VmAddress::class, 'profile_id');
    }
}

class VmAddress extends Model {
    protected $table = 'vm_addresses';
    protected $guarded = [];
    public $timestamps = false;
}

class VmPost extends Model implements Versionable {
    use HasVersioning;
    protected $table = 'vm_posts';
    protected $guarded = [];
    public $timestamps = false;

    public function comments() {
        return $this->hasMany(VmComment::class, 'post_id');
    }

    public function tags() {
        return $this->belongsToMany(VmTag::class, 'vm_post_tag')
            ->withPivot('order');
    }

    public function versioningConfig(): array {
        return [
            'title',
            'comments:body',
            'tags:name,pivot(order)',
        ];
    }
}

class VmComment extends Model {
    protected $table = 'vm_comments';
    protected $guarded = [];
    public $timestamps = false;
}

class VmTag extends Model {
    protected $table = 'vm_tags';
    protected $guarded = [];
    public $timestamps = false;
}

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/

it('records and reconstructs deep nested relations', function () {
    $user = VmUser::create(['name' => 'Alice']);
    $profile = $user->profile()->create(['phone' => '111']);
    $profile->address()->create(['city' => 'Delhi']);

    $v1 = $user->recordVersion('created');

    $profile->update(['phone' => '222']);
    $profile->address->update(['city' => 'Mumbai']);

    $v2 = $user->recordVersionIfChanged('updated');

    expect($v2->changed_paths)->toContain(
        'profile.phone',
        'profile.address.city'
    );

    $old = $user->reconstructVersion(1);
    expect($old->profile->phone)->toBe('111');
    expect($old->profile->address->city)->toBe('Delhi');
});

it('handles collections and pivot relations correctly', function () {
    $post = VmPost::create(['title' => 'Post']);
    $tag = VmTag::create(['name' => 'Laravel']);

    $post->comments()->create(['body' => 'First']);
    $post->tags()->attach($tag->id, ['order' => 1]);

    $post->recordVersion('initial');

    $post->comments()->first()->update(['body' => 'Edited']);
    $post->tags()->updateExistingPivot($tag->id, ['order' => 2]);

    $v2 = $post->recordVersionIfChanged('changed');

    expect($v2->changed_paths)->toContain(
        'comments[1].body',
        'tags[1].pivot.order'
    );

    $old = $post->reconstructVersion(1);
    expect($old->comments->first()->body)->toBe('First');
    expect($old->tags->first()->pivot->order)->toBe(1);
});
