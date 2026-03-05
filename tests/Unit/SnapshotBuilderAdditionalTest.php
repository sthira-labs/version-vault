<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SthiraLabs\VersionVault\Services\ConfigNormalizer;
use SthiraLabs\VersionVault\Services\SnapshotBuilder;

beforeEach(function () {
    Schema::create('sb_posts', function (Blueprint $t) {
        $t->id();
        $t->string('title')->nullable();
        $t->timestamp('published_at')->nullable();
        $t->timestamp('expires_at')->nullable();
    });

    Schema::create('sb_tags', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });

    Schema::create('sb_post_tag', function (Blueprint $t) {
        $t->unsignedBigInteger('sb_post_id');
        $t->unsignedBigInteger('sb_tag_id');
        $t->string('note')->nullable();
    });
});

class SbPost extends Model
{
    protected $table = 'sb_posts';
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = [
        'published_at' => 'datetime',
    ];

    protected $dates = [
        'expires_at',
    ];

    public function getDates()
    {
        return ['expires_at'];
    }

    public function getComputedAttribute(): string
    {
        return 'computed-value';
    }

    public function tags()
    {
        return $this->belongsToMany(SbTag::class, 'sb_post_tag', 'sb_post_id', 'sb_tag_id')
            ->withPivot('note');
    }
}

class SbTag extends Model
{
    protected $table = 'sb_tags';
    protected $guarded = [];
    public $timestamps = false;
}

class SbWeird extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    public function load($relations)
    {
        return $this;
    }

    public function odd()
    {
        return new stdClass();
    }
}

it('uses raw values for date-cast and date attributes', function () {
    $post = SbPost::create([
        'title' => 'Hello',
        'published_at' => '2024-01-01 00:00:00',
        'expires_at' => '2024-02-01 00:00:00',
    ]);

    $normalizer = new ConfigNormalizer();
    $builder = new SnapshotBuilder($normalizer);

    $config = $normalizer->normalize([
        'title',
        'published_at',
        'expires_at',
    ]);

    $snapshot = $builder->buildSnapshot($post, $config);

    expect($snapshot['attributes']['published_at'])->toBe('2024-01-01 00:00:00')
        ->and($snapshot['attributes']['expires_at'])->toBe('2024-02-01 00:00:00');
});

it('extracts pivot attributes with wildcard and skips missing relations', function () {
    $post = SbPost::create(['title' => 'Pivot']);
    $tag = SbTag::create(['name' => 'Tag A']);
    $post->tags()->attach($tag->id, ['note' => 'pinned']);

    $normalizer = new ConfigNormalizer();
    $builder = new SnapshotBuilder($normalizer);

    $config = $normalizer->normalize([
        'title',
        'tags:name,pivot(*)' => [],
        'missingRelation' => true,
    ]);

    $snapshot = $builder->buildSnapshot($post, $config);

    expect($snapshot['relations']['tags']['items'][$tag->id]['pivot']['note'])->toBe('pinned')
        ->and(isset($snapshot['relations']['missingRelation']))->toBeFalse();
});

it('uses accessors for non-raw attributes and returns false for non-date casts', function () {
    $post = SbPost::create(['title' => 'Title']);

    $normalizer = new ConfigNormalizer();
    $builder = new SnapshotBuilder($normalizer);

    $config = $normalizer->normalize([
        'title',
        'computed',
    ]);

    $snapshot = $builder->buildSnapshot($post, $config);

    expect($snapshot['attributes']['title'])->toBe('Title')
        ->and($snapshot['attributes']['computed'])->toBe('computed-value');
});

it('returns null for relations that are not models or collections', function () {
    $model = new SbWeird(['name' => 'Odd']);
    $model->setRelation('odd', 'not-a-relation');

    $builder = new SnapshotBuilder(new ConfigNormalizer());
    $config = [
        'attributes' => ['name'],
        'relations' => [
            'odd' => ['attributes' => ['*'], 'relations' => [], 'pivot' => []],
        ],
    ];

    $snapshot = $builder->buildSnapshot($model, $config);

    expect(isset($snapshot['relations']['odd']))->toBeFalse();
});

it('resolves config from model when none is provided', function () {
    $model = new class extends Model {
        protected $guarded = [];
        public $timestamps = false;

        public function versioningConfig(): array
        {
            return ['name'];
        }
    };

    $model->name = 'Configured';

    $builder = new SnapshotBuilder(new ConfigNormalizer());
    $snapshot = $builder->buildSnapshot($model, null);

    expect($snapshot['attributes']['name'])->toBe('Configured');
});

it('defaults to empty config when model has no versioningConfig', function () {
    $model = new class extends Model {
        protected $guarded = [];
        public $timestamps = false;
    };

    $model->name = 'Ignored';

    $builder = new SnapshotBuilder(new ConfigNormalizer());
    $snapshot = $builder->buildSnapshot($model, null);

    expect($snapshot['attributes'])->toBeArray()
        ->and($snapshot['attributes'])->toBe([]);
});
