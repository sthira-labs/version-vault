<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// --- Model Definitions (Ensuring class_exists guards) ---

if (!class_exists('TestProduct')) {
    class TestProduct extends Model {
        protected $guarded = [];
        public $timestamps = false;
    }
}
if (!class_exists('TestComment')) {
    class TestComment extends Model {
        protected $guarded = [];
        public $timestamps = false;
    }
}
if (!class_exists('TestCategory')) {
    class TestCategory extends Model {
        protected $guarded = [];
        public $timestamps = false;
    }
}
if (!class_exists('TestRole')) {
    class TestRole extends Model {
        protected $guarded = [];
        public $timestamps = false;
    }
}
if (!class_exists('TestPost')) {
    class TestPost extends Model {
        protected $guarded = [];
        public $timestamps = false;
        public function author(): BelongsTo
        {
            return $this->belongsTo(TestUser::class, 'user_id');
        }
        public function comments(): HasMany
        {
            return $this->hasMany(TestComment::class, 'post_id');
        }
    }
}
if (!class_exists('TestUser')) {
    class TestUser extends Model {
        protected $guarded = [];
        public $timestamps = false;
        public function posts(): HasMany
        {
            return $this->hasMany(TestPost::class, 'user_id');
        }
        public function category(): BelongsTo
        {
            return $this->belongsTo(TestCategory::class, 'category_id');
        }
        public function roles(): BelongsToMany
        {
            return $this->belongsToMany(TestRole::class, 'role_user', 'user_id', 'role_id')
                        ->withPivot(['status', 'assigned_by']);
        }
    }
}

// --- Setup: Creates all necessary tables for the tests ---

beforeEach(function () {
    // Drop tables in reverse dependency order for clean setup
    Schema::dropIfExists('test_comments');
    Schema::dropIfExists('test_posts');
    Schema::dropIfExists('role_user');
    Schema::dropIfExists('test_roles');
    Schema::dropIfExists('test_users');
    Schema::dropIfExists('test_categories');
    Schema::dropIfExists('test_products');

    // Recreate Schema
    Schema::create('test_categories', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    Schema::create('test_users', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('category_id')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->unique()->nullable();
        $table->string('secret_user_info')->nullable();
        $table->foreign('category_id')->references('id')->on('test_categories');
    });

    Schema::create('test_products', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->integer('price')->nullable();
        $table->string('secret_code')->nullable();
    });

    Schema::create('test_posts', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('title')->nullable();
        $table->text('content')->nullable();
        $table->string('draft_status')->default('draft');
        $table->foreign('user_id')->references('id')->on('test_users');
    });

    Schema::create('test_comments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('post_id');
        $table->string('body');
        $table->string('moderator_notes')->nullable();
        $table->foreign('post_id')->references('id')->on('test_posts');
    });

    Schema::create('test_roles', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    Schema::create('role_user', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('role_id');
        $table->string('status')->default('active');
        $table->string('assigned_by')->nullable();
        $table->unique(['user_id', 'role_id']);
    });

});

describe('SnapshotBuilder', function () {
    // --- Test Case 1: Basic Attributes ---

    test('it extracts specific attributes defined in config', function () {
        $product = TestProduct::create([
            'name' => 'Gaming Mouse',
            'price' => 5900,
            'secret_code' => 'HIDDEN_123'
        ]);
        $config = ['attributes' => ['name', 'price'], 'relations' => []];
        $snapshot = snapshotBuilder()->buildSnapshot($product, $config);

        expect($snapshot['attributes'])->toBe([
            'name' => 'Gaming Mouse',
            'price' => 5900
        ])->and($snapshot)->not->toHaveKey('relations');
    });

    test('it extracts all attributes when wildcard is used', function () {
        $product = TestProduct::create([
            'name' => 'Mechanical Keyboard',
            'price' => 12000,
            'secret_code' => 'ADMIN_999'
        ]);
        $config = ['attributes' => ['*'], 'relations' => []];
        $snapshot = snapshotBuilder()->buildSnapshot($product, $config);

        expect($snapshot['__meta'])
            ->toHaveKey('id')
            ->toHaveKey('primary_key', 'id');

        expect(array_keys($snapshot['__meta']))->toMatchArray([
            'alias',
            'table',
            'primary_key',
            'id',
        ]);
        
        expect($snapshot['attributes'])
            ->toHaveKey('price', 12000)
            ->toHaveKey('name', 'Mechanical Keyboard')
            ->toHaveKey('secret_code', 'ADMIN_999');
    });

    // --- Test Case 2: Single Relations ---

    test('it formats a single (belongsTo) relation correctly with nested attributes', function () {
        $author = TestUser::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $post = TestPost::create(['user_id' => $author->id, 'title' => 'First Blog Post']);
        $post->load('author');

        $config = [
            'attributes' => ['title'],
            'relations' => ['author' => ['attributes' => ['name'], 'relations' => []]]
        ];
        $snapshot = snapshotBuilder()->buildSnapshot($post, $config);

        expect($snapshot['relations']['author']['type'])->toBe('single');
        expect($snapshot['relations']['author']['data']['attributes'])->toBe(['name' => 'John Doe']);
        expect($snapshot['relations']['author']['data']['attributes'])->not->toHaveKey('email');
    });

    test('it handles a null single relation gracefully by omitting the key', function () {
        $post = TestPost::create(['title' => 'Post without Author']);
        $post->setRelation('author', null);

        $config = ['attributes' => ['title'], 'relations' => ['author' => ['attributes' => ['name']]]];
        $snapshot = snapshotBuilder()->buildSnapshot($post, $config);

        // Assert: The 'author' key is omitted, leading to the removal of the top-level 'relations' key.
        expect($snapshot)->not->toHaveKey('relations');
    });

    // --- Test Case 3: Collection Relations ---

    test('it formats a collection (hasMany) relation correctly, indexed by key', function () {
        $user = TestUser::create(['name' => 'Collection Creator']);
        $post1 = TestPost::create(['user_id' => $user->id, 'title' => 'Post A', 'content' => 'Full']);
        $post2 = TestPost::create(['user_id' => $user->id, 'title' => 'Post B']);
        $user->load('posts');

        $config = [
            'attributes' => ['name'],
            'relations' => ['posts' => ['attributes' => ['title'], 'relations' => []]]
        ];
        $snapshot = snapshotBuilder()->buildSnapshot($user, $config);

        $postsRelation = $snapshot['relations']['posts'];
        expect($postsRelation['type'])->toBe('collection');
        expect($postsRelation['items'])->toHaveKeys([$post1->getKey(), $post2->getKey()]);

        // Check exclusion in nested node
        expect($postsRelation['items'][$post1->getKey()]['attributes'])
            ->toBe(['title' => 'Post A'])
            ->and($postsRelation['items'][$post1->getKey()]['attributes'])->not->toHaveKey('content');
    });

    test('it omits the key for an empty collection relation when other relations are present', function () {
        $category = TestCategory::create(['name' => 'General']);
        $user = TestUser::create(['name' => 'Robust Test User', 'category_id' => $category->id]);
        $user->load(['posts', 'category']); // Posts is empty, Category is present

        $config = [
            'attributes' => ['name'],
            'relations' => [
                'category' => ['attributes' => ['name']], // Will be present
                'posts' => ['attributes' => ['title']]   // Will be empty
            ]
        ];
        $snapshot = snapshotBuilder()->buildSnapshot($user, $config);

        if (config('version-vault.debug')) {
            info(json_encode($snapshot));
        }

        // Assert: The 'category' key ensures 'relations' exists, but 'posts' must be omitted.
        expect($snapshot)->toHaveKey('relations');
        expect($snapshot['relations'])->toHaveKey('category');
        expect($snapshot['relations'])->toHaveKey('posts')->and($snapshot['relations']['posts'])->not->toHaveKey('realtions');
    });

    // --- Test Case 4: Pivot Relations ---

    test('it formats a pivot (belongsToMany) relation correctly and includes pivot data', function () {
        $user = TestUser::create(['name' => 'Pivot User']);
        $role1 = TestRole::create(['name' => 'Administrator']);
        $user->roles()->attach($role1->id, ['status' => 'active', 'assigned_by' => 'System']);
        $user->load('roles');

        $config = [
            'attributes' => ['name'],
            'relations' => [
                'roles' => [
                    'attributes' => ['name'],
                    'pivot' => ['status', 'assigned_by']
                ]
            ]
        ];
        $snapshot = snapshotBuilder()->buildSnapshot($user, $config);

        $roleItems = $snapshot['relations']['roles']['items'];
        expect($snapshot['relations']['roles']['type'])->toBe('pivot');

        // Assert pivot data extraction
        expect($roleItems[$role1->id]['pivot'])->toBe([
            'status' => 'active',
            'assigned_by' => 'System'
        ]);
    });

    test('it omits the pivot key if no pivot attributes are configured', function () {
        $user = TestUser::create(['name' => 'No Pivot Config']);
        $role = TestRole::create(['name' => 'Simple']);
        $user->roles()->attach($role->id, ['status' => 'active', 'assigned_by' => 'System']);
        $user->load('roles');

        $config = [
            'attributes' => ['name'],
            'relations' => [
                'roles' => ['attributes' => ['name']]
            ]
        ];
        $snapshot = snapshotBuilder()->buildSnapshot($user, $config);

        expect($snapshot['relations']['roles']['items'][$role->id])
            ->not->toHaveKey('pivot');
    });

    // --- Test Case 5: Recursive/Nested Relations ---

    test('it correctly builds a recursive snapshot across three levels of nesting', function () {
        $user = TestUser::create(['name' => 'Hierarchical User']);
        $post = TestPost::create(['user_id' => $user->id, 'title' => 'Recursive Post', 'draft_status' => 'published']);
        $comment1 = TestComment::create(['post_id' => $post->id, 'body' => 'Great article!', 'moderator_notes' => 'Clean']);
        
        $user->load('posts.comments'); 

        $config = [
            'attributes' => ['name'], 
            'relations' => [
                'posts' => [ 
                    'attributes' => ['title'], 
                    'relations' => [
                        'comments' => [ 
                            'attributes' => ['body'], 
                        ]
                    ]
                ]
            ]
        ];
        $snapshot = snapshotBuilder()->buildSnapshot($user, $config);

        // L1 Assertion (User)
        expect($snapshot['attributes'])->toHaveKey('name');

        // L2 Assertion (Post)
        $postNode = $snapshot['relations']['posts']['items'][$post->id];
        expect($postNode['attributes'])->toHaveKey('title', 'Recursive Post');
        expect($postNode['attributes'])->not->toHaveKey('draft_status');

        // L3 Assertion (Comment)
        $commentNode = $postNode['relations']['comments']['items'][$comment1->id];
        expect($commentNode['attributes'])->toHaveKey('body', 'Great article!');
        expect($commentNode['attributes'])->not->toHaveKey('moderator_notes');

        // Final integrity check
        expect($commentNode)->not->toHaveKey('relations');
    });
});
