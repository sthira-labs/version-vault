<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as FoundationUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Contracts\Versionable;
use SthiraLabs\VersionVault\Traits\HasVersioning;

/*
|--------------------------------------------------------------------------
| Schema
|--------------------------------------------------------------------------
*/
beforeEach(function () {
    Schema::create('vcb_users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->nullable();
        $t->string('password')->nullable();
    });

    Schema::create('vcb_articles', function (Blueprint $t) {
        $t->id();
        $t->string('title');
    });

    ensureVersionsTable();

    config(['auth.providers.users.model' => VcbUser::class]);
});

/*
|--------------------------------------------------------------------------
| Models (file-scoped)
|--------------------------------------------------------------------------
*/
class VcbUser extends FoundationUser
{
    protected $table = 'vcb_users';
    protected $guarded = [];
    public $timestamps = false;
}

class VcbArticle extends Model implements Versionable
{
    use HasVersioning;

    protected $table = 'vcb_articles';
    protected $guarded = [];
    public $timestamps = false;

    public function versioningConfig(): array
    {
        return [
            'title',
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/
it('stores created_by when a user is authenticated', function () {
    $user = VcbUser::create([
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'secret',
    ]);

    Auth::login($user);

    $article = VcbArticle::create(['title' => 'Hello']);
    $version = $article->recordVersion('created');

    expect($version->created_by)->toBe($user->id);
});
