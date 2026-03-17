<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Services\HydrationPersister;

beforeEach(function () {
    Schema::create('hp_authors', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
    });

    Schema::create('hp_author_profiles', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('author_id');
        $t->string('bio')->nullable();
    });

    Schema::create('hp_posts', function (Blueprint $t) {
        $t->id();
        $t->string('title')->nullable();
        $t->unsignedBigInteger('author_id')->nullable();
        $t->unsignedBigInteger('editor_id')->nullable();
    });

    Schema::create('hp_profiles', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('post_id');
        $t->string('bio')->nullable();
    });

    Schema::create('hp_details', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('post_id');
        $t->string('note')->nullable();
    });

    Schema::create('hp_comments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('post_id');
        $t->string('body')->nullable();
    });

    Schema::create('hp_tags', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
    });

    Schema::create('hp_categories', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('tag_id');
        $t->string('name')->nullable();
    });

    Schema::create('hp_post_tag', function (Blueprint $t) {
        $t->unsignedBigInteger('hp_post_id');
        $t->unsignedBigInteger('hp_tag_id');
        $t->string('label')->nullable();
    });
});

class HpAuthor extends Model
{
    protected $table = 'hp_authors';
    protected $guarded = [];
    public $timestamps = false;

    public function profile()
    {
        return $this->hasOne(HpAuthorProfile::class, 'author_id');
    }
}

class HpAuthorProfile extends Model
{
    protected $table = 'hp_author_profiles';
    protected $guarded = [];
    public $timestamps = false;
}

class HpPost extends Model
{
    protected $table = 'hp_posts';
    protected $guarded = [];
    public $timestamps = false;

    public function author()
    {
        return $this->belongsTo(HpAuthor::class, 'author_id');
    }

    public function editor()
    {
        return $this->belongsTo(HpAuthor::class, 'editor_id');
    }

    public function profile()
    {
        return $this->hasOne(HpProfile::class, 'post_id');
    }

    public function details()
    {
        return $this->hasOne(HpDetail::class, 'post_id');
    }

    public function unused()
    {
        return $this->hasOne(HpProfile::class, 'post_id');
    }

    public function comments()
    {
        return $this->hasMany(HpComment::class, 'post_id');
    }

    public function tags()
    {
        return $this->belongsToMany(HpTag::class, 'hp_post_tag', 'hp_post_id', 'hp_tag_id')
            ->withPivot('label');
    }
}

class HpProfile extends Model
{
    protected $table = 'hp_profiles';
    protected $guarded = [];
    public $timestamps = false;
}

class HpDetail extends Model
{
    protected $table = 'hp_details';
    protected $guarded = [];
    public $timestamps = false;
}

class HpComment extends Model
{
    protected $table = 'hp_comments';
    protected $guarded = [];
    public $timestamps = false;
}

class HpTag extends Model
{
    protected $table = 'hp_tags';
    protected $guarded = [];
    public $timestamps = false;

    public function category()
    {
        return $this->hasOne(HpCategory::class, 'tag_id');
    }
}

class HpCategory extends Model
{
    protected $table = 'hp_categories';
    protected $guarded = [];
    public $timestamps = false;
}

it('persists root, single, collection, and pivot relations including deletes', function () {
    $author = HpAuthor::create(['name' => 'Author A']);
    $editor = HpAuthor::create(['name' => 'Editor A']);
    $authorProfile = $author->profile()->create(['bio' => 'Old Bio']);
    $author->setRelation('profile', null);

    $post = HpPost::create([
        'title' => 'Old',
        'author_id' => $author->id,
        'editor_id' => $editor->id,
    ]);

    $postProfile = $post->profile()->create(['bio' => 'Post Bio']);
    $comment1 = $post->comments()->create(['body' => 'C1']);
    $comment2 = $post->comments()->create(['body' => 'C2']);

    $tag1 = HpTag::create(['name' => 'T1']);
    $tag2 = HpTag::create(['name' => 'T2']);
    $tag3 = HpTag::create(['name' => 'T3']);
    $tag1->category()->create(['name' => 'OldCat']);

    $post->tags()->attach($tag1->id, ['label' => 'old']);
    $post->tags()->attach($tag2->id, ['label' => 'old2']);

    $hydrated = new HpPost(['title' => 'New']);
    $hydrated->setRelation('author', $author);
    $hydrated->setRelation('editor', null);
    $hydrated->setRelation('profile', null);
    $hydrated->setRelation('details', null);
    $hydrated->setRelation('comments', new Collection([
        new HpComment(['body' => 'C3']),
        new HpComment([]),
    ]));

    $updatedCategory = new HpCategory(['name' => 'NewCat']);
    $tag1Hydrated = new HpTag(['id' => $tag1->id, 'name' => 'T1 Updated']);
    $tag1Hydrated->setRelation('category', $updatedCategory);
    $tag3Hydrated = new HpTag(['id' => $tag3->id, 'name' => 'T3']);

    $hydrated->setRelation('tags', new Collection([$tag1Hydrated, $tag3Hydrated]));

    $canonical = [
        'attributes' => ['title' => 'New'],
        'relations' => [
            'author' => [
                'type' => 'single',
                'data' => [
                    '__meta' => ['primary_key' => 'id', 'id' => $author->id],
                    'attributes' => ['name' => 'Author A Updated'],
                    'relations' => [
                        'profile' => [
                            'type' => 'single',
                            'data' => [
                                '__meta' => ['primary_key' => 'id', 'id' => $authorProfile->id],
                                'attributes' => ['bio' => 'New Bio'],
                            ],
                        ],
                    ],
                ],
            ],
            'editor' => [
                'type' => 'single',
                'data' => null,
            ],
            'profile' => [
                'type' => 'single',
                'data' => null,
            ],
            'details' => [
                'type' => 'single',
                'data' => [
                    'attributes' => ['id' => 100, 'note' => 'Note'],
                ],
            ],
            'comments' => [
                'type' => 'collection',
                'items' => [
                    $comment1->id => [
                        '__meta' => ['primary_key' => 'id', 'id' => $comment1->id],
                        'attributes' => ['body' => 'C1 Updated'],
                    ],
                    'new' => [
                        'attributes' => ['body' => 'C3'],
                    ],
                    'empty' => [
                        'attributes' => [],
                    ],
                ],
            ],
            'tags' => [
                'type' => 'pivot',
                'items' => [
                    $tag1->id => [
                        'attributes' => ['id' => $tag1->id, 'name' => 'T1 Updated'],
                        'pivot' => ['label' => 'new'],
                        'relations' => [
                            'category' => [
                                'type' => 'single',
                                'data' => ['attributes' => ['name' => 'NewCat']],
                            ],
                        ],
                    ],
                    $tag3->id => [
                        'attributes' => ['id' => $tag3->id, 'name' => 'T3'],
                        'pivot' => ['label' => 'c'],
                    ],
                ],
            ],
            'missingRelation' => [
                'type' => 'single',
                'data' => ['attributes' => ['id' => 1]],
            ],
            'unused' => null,
        ],
    ];

    $persister = new HydrationPersister();
    $persister->persist($post, $hydrated, $canonical, ['delete_missing' => true]);

    expect($post->fresh()->title)->toBe('New')
        ->and($post->fresh()->editor_id)->toBeNull()
        ->and($post->profile()->exists())->toBeFalse()
        ->and($post->comments()->count())->toBe(3)
        ->and($post->comments()->where('body', 'C2')->exists())->toBeFalse()
        ->and($post->tags()->count())->toBe(2);
});

it('associates missing belongsTo relations and saves empty hasOne targets', function () {
    $author = HpAuthor::create(['name' => 'Author B']);
    $post = HpPost::create(['title' => 'Post', 'author_id' => null]);

    $hydrated = new HpPost(['title' => 'Post']);
    $hydrated->setRelation('author', $author);
    $hydrated->setRelation('profile', new HpProfile());

    $canonical = [
        'attributes' => ['title' => 'Post'],
        'relations' => [
            'author' => [
                'type' => 'single',
                'data' => [
                    'attributes' => ['id' => $author->id, 'name' => 'Author B'],
                ],
            ],
            'profile' => [
                'type' => 'single',
                'data' => [
                    'attributes' => [],
                ],
            ],
        ],
    ];

    $persister = new HydrationPersister();
    $persister->persist($post, $hydrated, $canonical, ['delete_missing' => false]);

    expect($post->fresh()->author_id)->toBe($author->id)
        ->and($post->profile()->exists())->toBeTrue();
});
