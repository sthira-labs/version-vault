<?php

use SthiraLabs\VersionVault\Services\VersionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;

// Mock the required Laravel Model class structure for the tests
class MockModel extends Model
{
    protected $guarded = [];
    public $exists = false;

    // Simulate relation methods for relation resolution
    public function author() { return $this->hasOne(MockModel::class); }
    public function comments() { return $this->hasMany(MockModel::class); }
    public function tags() { return $this->belongsToMany(MockModel::class, 'post_tag'); }
    public function morphComments() { return $this->morphMany(MockModel::class, 'commentable'); }
    public function profile() { return $this->belongsTo(MockModel::class); }
}

// Helper to create a Resolver instance
function resolver(): VersionResolver {
    return new VersionResolver();
}

// The core test group for the VersionResolver
describe('VersionResolver', function () {

    // --- SNAPSHOT MANIPULATION TESTS (applyDiffsToSnapshot/applyDiff) ---

    // Define a robust starting state (Snapshot A)
    $baseSnapshot = [
        'attributes' => [
            'id' => 100,
            'name' => 'Initial Document Name',
            'status' => 'draft',
        ],
        'relations' => [
            // Single Relation (Author)
            'author' => [
                'type' => 'single',
                'data' => [
                    'attributes' => [
                        'id' => 1,
                        'email' => 'author_a@example.com',
                    ],
                ],
            ],
            // Collection Relation (Comments)
            'comments' => [
                'type' => 'collection',
                'items' => [
                    201 => [
                        'attributes' => ['id' => 201, 'content' => 'Comment One'],
                    ],
                    202 => [
                        'attributes' => ['id' => 202, 'content' => 'Comment Two'],
                    ],
                ],
            ],
            // Pivot Relation (Tags)
            'tags' => [
                'type' => 'pivot',
                'items' => [
                    301 => [
                        'attributes' => ['id' => 301, 'name' => 'Tag A'],
                        'pivot' => ['post_id' => 100, 'tag_id' => 301, 'order' => 1],
                    ],
                ],
            ],
        ],
    ];

    it('applies attribute diffs correctly', function () use ($baseSnapshot) {
        $diff = [
            'attributes' => [
                'name' => ['from' => 'Initial Document Name', 'to' => 'Updated Document Name'],
                'status' => ['from' => 'draft', 'to' => 'published'],
                'new_attr' => ['from' => null, 'to' => 'value'],
            ]
        ];

        $final = resolver()->applyDiffsToSnapshot($baseSnapshot, [$diff]);

        expect($final['attributes']['name'])->toBe('Updated Document Name')
            ->and($final['attributes']['status'])->toBe('published')
            ->and($final['attributes']['new_attr'])->toBe('value')
            ->and($final['attributes']['id'])->toBe(100); // Unchanged
    });

    it('applies full creation/deletion diffs correctly', function () {
        // Full Creation
        $createdDiff = ['_created' => true, '_data' => ['attributes' => ['id' => 500, 'data' => 'new']]];
        $createdFinal = resolver()->applyDiffsToSnapshot(null, [$createdDiff]);
        expect($createdFinal['attributes']['id'])->toBe(500);

        // Full Deletion
        $deletedDiff = ['_deleted' => true];
        $deletedFinal = resolver()->applyDiffsToSnapshot($createdFinal, [$deletedDiff]);
        expect($deletedFinal)->toBe([]);
    });

    it('applies single relation diffs correctly (update/create/delete)', function () use ($baseSnapshot) {
        $diff = [
            'relations' => [
                'author' => [
                    'type' => 'single',
                    'data' => [
                        'attributes' => [
                            'email' => ['from' => 'author_a@example.com', 'to' => 'author_b@example.com'],
                            'phone' => ['from' => null, 'to' => '555-1234'],
                        ],
                    ],
                ],
                'profile' => [ // Relation that was null, now created
                    '_created' => true,
                    '_data' => [
                        'type' => 'single',
                        'data' => ['attributes' => ['id' => 50, 'bio' => 'A new profile']],
                    ],
                ],
            ]
        ];

        $final = resolver()->applyDiffsToSnapshot($baseSnapshot, [$diff]);

        // Updated existing single relation
        expect($final['relations']['author']['data']['attributes']['email'])->toBe('author_b@example.com')
            ->and($final['relations']['author']['data']['attributes']['phone'])->toBe('555-1234');

        // Created new single relation
        expect($final['relations']['profile']['data']['attributes']['id'])->toBe(50);
    });

    it('applies collection relation diffs correctly (add/remove/update)', function () use ($baseSnapshot) {
        $diff = [
            'relations' => [
                'comments' => [
                    'type' => 'collection',
                    'removed' => [202], // Remove 'Comment Two'
                    'added' => [203], // Add 'Comment Three'
                    'added_data' => [
                        203 => ['attributes' => ['id' => 203, 'content' => 'Comment Three']],
                    ],
                    'updated' => [
                        201 => [ // Update 'Comment One'
                            'attributes' => [
                                'content' => ['from' => 'Comment One', 'to' => 'Updated Comment One'],
                            ],
                        ],
                    ],
                ],
            ]
        ];

        $final = resolver()->applyDiffsToSnapshot($baseSnapshot, [$diff]);

        // Removed item 202
        expect(isset($final['relations']['comments']['items'][202]))->toBeFalse();
        // Added item 203
        expect($final['relations']['comments']['items'][203]['attributes']['content'])->toBe('Comment Three');
        // Updated item 201
        expect($final['relations']['comments']['items'][201]['attributes']['content'])->toBe('Updated Comment One');
    });

    it('applies pivot relation diffs correctly (attach/detach/update pivot/update attribute)', function () use ($baseSnapshot) {
        $diff = [
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'detached' => [301], // Detach 'Tag A'
                    'attached' => [302], // Attach 'Tag B'
                    'attached_data' => [
                        302 => [
                            'attributes' => ['id' => 302, 'name' => 'Tag B'],
                            'pivot' => ['post_id' => 100, 'tag_id' => 302, 'order' => 2],
                        ],
                    ],
                ],
            ]
        ];

        $intermediate = resolver()->applyDiffsToSnapshot($baseSnapshot, [$diff]);

        // Detached 301
        expect(isset($intermediate['relations']['tags']['items'][301]))->toBeFalse();
        // Attached 302
        expect($intermediate['relations']['tags']['items'][302]['attributes']['name'])->toBe('Tag B');
        expect($intermediate['relations']['tags']['items'][302]['pivot']['order'])->toBe(2);

        // Apply a pivot update to the newly attached tag 302
        $updateDiff = [
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'updated' => [
                        302 => [
                            'attributes' => [
                                'name' => ['from' => 'Tag B', 'to' => 'Tag Beta'], // Attribute update
                            ],
                            'pivot' => [
                                'order' => ['from' => 2, 'to' => 5], // Pivot update
                            ],
                        ],
                    ],
                ],
            ]
        ];
        
        $final = resolver()->applyDiffsToSnapshot($intermediate, [$updateDiff]);

        // Verify updates
        expect($final['relations']['tags']['items'][302]['attributes']['name'])->toBe('Tag Beta');
        expect($final['relations']['tags']['items'][302]['pivot']['order'])->toBe(5);
    });

    it('stores detached_data as removed tombstones during pivot diff replay', function () use ($baseSnapshot) {
        $diff = [
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'detached' => [301],
                    'detached_data' => [
                        301 => [
                            'attributes' => ['id' => 301, 'name' => 'Tag A (detached)'],
                            'pivot' => ['post_id' => 100, 'tag_id' => 301, 'order' => 1],
                        ],
                    ],
                ],
            ],
        ];

        $final = resolver()->applyDiffsToSnapshot($baseSnapshot, [$diff]);

        expect(isset($final['relations']['tags']['items'][301]))->toBeFalse()
            ->and($final['relations']['tags']['_removed_items'][301]['attributes']['name'])->toBe('Tag A (detached)');
    });

    it('does not keep removed tombstones across later diffs without remove/detach operations', function () use ($baseSnapshot) {
        $removeDiff = [
            'relations' => [
                'comments' => [
                    'type' => 'collection',
                    'removed' => [202],
                    'removed_data' => [
                        202 => [
                            'attributes' => ['id' => 202, 'content' => 'Removed Comment'],
                        ],
                    ],
                ],
            ],
        ];

        $laterUnrelatedDiff = [
            'attributes' => [
                'name' => ['from' => 'Initial Document Name', 'to' => 'Later Name'],
            ],
        ];

        $final = resolver()->applyDiffsToSnapshot($baseSnapshot, [$removeDiff, $laterUnrelatedDiff]);

        expect($final['attributes']['name'])->toBe('Later Name')
            ->and(isset($final['relations']['comments']['_removed_items']))->toBeFalse();
    });

    it('clears tombstones safely when collection items include unexpected non-array entries', function () {
        $snapshot = [
            'attributes' => ['id' => 100, 'name' => 'Base'],
            'relations' => [
                'comments' => [
                    'type' => 'collection',
                    '_removed_items' => [
                        999 => ['attributes' => ['id' => 999, 'content' => 'Old Tombstone']],
                    ],
                    'items' => [
                        201 => 'unexpected-scalar-item',
                    ],
                ],
            ],
        ];

        $diff = [
            'attributes' => [
                'name' => ['from' => 'Base', 'to' => 'Updated'],
            ],
        ];

        $final = resolver()->applyDiffsToSnapshot($snapshot, [$diff]);

        expect($final['attributes']['name'])->toBe('Updated')
            ->and(isset($final['relations']['comments']['_removed_items']))->toBeFalse();
    });

    // --- HYDRATION TESTS (hydrateModelFromSnapshot) ---
    $hydrationSnapshot = [
        'attributes' => [
            'id' => 10,
            'title' => 'Hydration Test',
            'content' => 'Lorem ipsum',
        ],
        'relations' => [
            'author' => [
                'type' => 'single',
                'data' => [
                    'attributes' => ['id' => 1, 'username' => 'test_user'],
                ],
            ],
            'tags' => [
                'type' => 'pivot',
                'items' => [
                    51 => [
                        'attributes' => ['id' => 51, 'label' => 'A'],
                        'pivot' => ['doc_id' => 10, 'tag_id' => 51, 'order' => 1],
                    ],
                ],
            ],
        ],
    ];

    it('hydrates a fresh model instance with attributes and sets exists flag', function () use ($hydrationSnapshot) {
        $model = new MockModel();
        $hydrated = resolver()->hydrateModelFromSnapshot($model, $hydrationSnapshot);

        expect($hydrated->getAttribute('id'))->toBe(10)
            ->and($hydrated->getAttribute('title'))->toBe('Hydration Test')
            ->and($hydrated->exists)->toBeTrue();
    });

    it('hydrates relations by building new instances when no current relation exists (default behavior)', function () use ($hydrationSnapshot) {
        $model = new MockModel();
        $hydrated = resolver()->hydrateModelFromSnapshot($model, $hydrationSnapshot, ['hydrate_loaded_relations_only' => false, 'attach_unloaded_relations' => true]);

        // Single Relation
        expect($hydrated->relationLoaded('author'))->toBeTrue()
            ->and($hydrated->author)->toBeInstanceOf(MockModel::class)
            ->and($hydrated->author->username)->toBe('test_user');

        // Pivot Relation
        expect($hydrated->relationLoaded('tags'))->toBeTrue()
            ->and($hydrated->tags)->toBeInstanceOf(Collection::class)
            ->and($hydrated->tags->first()->pivot->order)->toBe(1);
    });

    it('preserves template attributes and loaded relations not present in the snapshot', function () use ($hydrationSnapshot) {
        $model = new MockModel(['id' => 10, 'title' => 'Old Title', 'untracked' => 'keep_me']);
        $model->exists = true;

        $profile = new MockModel(['id' => 77, 'bio' => 'Keep Profile']);
        $profile->exists = true;
        $model->setRelation('profile', $profile);

        $hydrated = resolver()->hydrateModelFromSnapshot($model, $hydrationSnapshot, [
            'hydrate_loaded_relations_only' => true,
            'preserve_missing_attributes' => true,
        ]);

        // Snapshot values override, but missing attributes are preserved
        expect($hydrated->title)->toBe('Hydration Test')
            ->and($hydrated->untracked)->toBe('keep_me');

        // Relation not present in snapshot remains intact
        expect($hydrated->relationLoaded('profile'))->toBeTrue()
            ->and($hydrated->profile)->toBe($profile)
            ->and($hydrated->profile->bio)->toBe('Keep Profile');
    });

    it('updates single relation in-place (non-destructive)', function () use ($hydrationSnapshot) {
        $model = new MockModel();
        
        // 1. Create a template model with a loaded relation to simulate 'in-place' update target
        $existingAuthor = new MockModel(['id' => 1, 'username' => 'old_user', 'extra' => 'keep_this']);
        $existingAuthor->exists = true;
        $model->setRelation('author', $existingAuthor);
        
        $hydrated = resolver()->hydrateModelFromSnapshot($model, $hydrationSnapshot, [
            'hydrate_loaded_relations_only' => true,
        ]);
        
        // Should update username but keep extra attribute
        expect($hydrated->author->username)->toBe('test_user')
            ->and($hydrated->author->extra)->toBe('keep_this')
            // Crucially, it should be the same instance
            ->and($hydrated->author)->toBe($existingAuthor);
    });

    it('updates collection relation in-place (non-destructive, updates/adds/removes)', function () use ($hydrationSnapshot) {
        $model = new MockModel();
        
        // 1. Create a template model with a loaded collection
        $currentTags = new Collection([
            // Tag 51 exists, will be updated
            new MockModel(['id' => 51, 'label' => 'OLD', 'extra_prop' => 'keep']),
            // Tag 52 exists, will remain (non-destructive)
            new MockModel(['id' => 52, 'label' => 'Unchanged Tag']),
        ]);
        $model->setRelation('tags', $currentTags);

        // Alter the snapshot to include an explicit 'removed' and a new item 53
        $diffedSnapshot = $hydrationSnapshot;
        $diffedSnapshot['relations']['tags']['removed'] = [52]; // Explicit removal
        $diffedSnapshot['relations']['tags']['items'][53] = [
            'attributes' => ['id' => 53, 'label' => 'B'],
            'pivot' => ['doc_id' => 10, 'tag_id' => 53, 'order' => 2],
        ];
        
        $hydrated = resolver()->hydrateModelFromSnapshot($model, $diffedSnapshot, [
            'hydrate_loaded_relations_only' => true,
        ]);

        expect($hydrated->tags)->toBeInstanceOf(Collection::class)
            ->and($hydrated->tags->count())->toBe(2); // Item 52 removed, item 53 added

        // Check updated item 51
        $tag51 = $hydrated->tags->firstWhere('id', 51);
        expect($tag51->label)->toBe('A') // Attribute updated
            ->and($tag51->extra_prop)->toBe('keep') // Existing attribute preserved
            ->and($tag51->pivot->order)->toBe(1); // Pivot updated/attached

        // Check new item 53
        $tag53 = $hydrated->tags->firstWhere('id', 53);
        expect($tag53->label)->toBe('B')
            ->and($tag53->pivot->order)->toBe(2);
    });

    it('syncs hasMany collections to canonical snapshot and unsets stale loaded items', function () {
        $snapshot = [
            'attributes' => ['id' => 10, 'title' => 'Hydration Test'],
            'relations' => [
                'comments' => [
                    'type' => 'collection',
                    'items' => [
                        201 => [
                            'attributes' => ['id' => 201, 'content' => 'From Snapshot'],
                        ],
                    ],
                ],
            ],
        ];

        $model = new MockModel(['id' => 10]);
        $model->exists = true;
        $model->setRelation('comments', new Collection([
            new MockModel(['id' => 201, 'content' => 'Old Value']),
            new MockModel(['id' => 202, 'content' => 'Stale']),
        ]));

        $hydrated = resolver()->hydrateModelFromSnapshot($model, $snapshot, [
            'hydrate_loaded_relations_only' => true,
        ]);

        expect($hydrated->comments->pluck('id')->all())->toBe([201])
            ->and($hydrated->comments->first()->content)->toBe('From Snapshot');
    });

    it('syncs belongsToMany collections to canonical snapshot and unsets stale loaded items', function () {
        $snapshot = [
            'attributes' => ['id' => 10, 'title' => 'Hydration Test'],
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'items' => [
                        51 => [
                            'attributes' => ['id' => 51, 'label' => 'A'],
                            'pivot' => ['doc_id' => 10, 'tag_id' => 51, 'order' => 1],
                        ],
                    ],
                ],
            ],
        ];

        $model = new MockModel(['id' => 10]);
        $model->exists = true;

        $tag51 = new MockModel(['id' => 51, 'label' => 'Old A']);
        $tag52 = new MockModel(['id' => 52, 'label' => 'Stale B']);
        $tag52->setRelation('pivot', new MockModel(['order' => 99]));
        $model->setRelation('tags', new Collection([$tag51, $tag52]));

        $hydrated = resolver()->hydrateModelFromSnapshot($model, $snapshot, [
            'hydrate_loaded_relations_only' => true,
        ]);

        expect($hydrated->tags->pluck('id')->all())->toBe([51])
            ->and($hydrated->tags->first()->label)->toBe('A')
            ->and($hydrated->tags->first()->pivot->order)->toBe(1);
    });

    it('syncs morphMany collections to canonical snapshot and unsets stale loaded items', function () {
        $snapshot = [
            'attributes' => ['id' => 10, 'title' => 'Hydration Test'],
            'relations' => [
                'morphComments' => [
                    'type' => 'collection',
                    'items' => [
                        901 => [
                            'attributes' => ['id' => 901, 'content' => 'A'],
                        ],
                    ],
                ],
            ],
        ];

        $model = new MockModel(['id' => 10]);
        $model->exists = true;
        $model->setRelation('morphComments', new Collection([
            new MockModel(['id' => 901, 'content' => 'Old A']),
            new MockModel(['id' => 902, 'content' => 'Stale B']),
        ]));

        $hydrated = resolver()->hydrateModelFromSnapshot($model, $snapshot, [
            'hydrate_loaded_relations_only' => true,
        ]);

        expect($hydrated->morphComments->pluck('id')->all())->toBe([901])
            ->and($hydrated->morphComments->first()->content)->toBe('A');
    });

    it('syncs to target-version many items even when prune_missing_many_relations is disabled', function () {
        $snapshot = [
            'attributes' => ['id' => 10, 'title' => 'Hydration Test'],
            'relations' => [
                'comments' => [
                    'type' => 'collection',
                    'items' => [
                        201 => [
                            'attributes' => ['id' => 201, 'content' => 'From Snapshot'],
                        ],
                    ],
                ],
            ],
        ];

        $model = new MockModel(['id' => 10]);
        $model->exists = true;
        $model->setRelation('comments', new Collection([
            new MockModel(['id' => 201, 'content' => 'Old Value']),
            new MockModel(['id' => 202, 'content' => 'Keep Me']),
        ]));

        $hydrated = resolver()->hydrateModelFromSnapshot($model, $snapshot, [
            'hydrate_loaded_relations_only' => true,
            'prune_missing_many_relations' => false,
        ]);

        expect($hydrated->comments->pluck('id')->all())->toBe([201])
            ->and($hydrated->comments->firstWhere('id', 201)->content)->toBe('From Snapshot')
            ->and($hydrated->comments->firstWhere('id', 202))->toBeNull();
    });

    it('keeps explicitly removed records but drops unrelated stale records when pruning is disabled', function () {
        $snapshot = [
            'attributes' => ['id' => 10, 'title' => 'Hydration Test'],
            'relations' => [
                'comments' => [
                    'type' => 'collection',
                    'removed' => [202],
                    'items' => [
                        201 => [
                            'attributes' => ['id' => 201, 'content' => 'From Snapshot'],
                        ],
                    ],
                ],
            ],
        ];

        $model = new MockModel(['id' => 10]);
        $model->exists = true;
        $model->setRelation('comments', new Collection([
            new MockModel(['id' => 201, 'content' => 'Old Value']),
            new MockModel(['id' => 202, 'content' => 'Deleted In Target']),
            new MockModel(['id' => 999, 'content' => 'Unrelated Stale']),
        ]));

        $hydrated = resolver()->hydrateModelFromSnapshot($model, $snapshot, [
            'hydrate_loaded_relations_only' => true,
            'prune_missing_many_relations' => false,
        ]);

        expect($hydrated->comments->pluck('id')->sort()->values()->all())->toBe([201, 202])
            ->and($hydrated->comments->firstWhere('id', 201)->content)->toBe('From Snapshot')
            ->and($hydrated->comments->firstWhere('id', 202)->content)->toBe('Deleted In Target')
            ->and($hydrated->comments->firstWhere('id', 999))->toBeNull();
    });

    it('rehydrates removed many-relation items from canonical tombstones when pruning is disabled', function () {
        $snapshot = [
            'attributes' => ['id' => 10, 'title' => 'Hydration Test'],
            'relations' => [
                'comments' => [
                    'type' => 'collection',
                    'items' => [
                        201 => [
                            'attributes' => ['id' => 201, 'content' => 'Current'],
                        ],
                    ],
                    '_removed_items' => [
                        202 => [
                            'attributes' => ['id' => 202, 'content' => 'Removed Earlier'],
                        ],
                    ],
                ],
            ],
        ];

        $model = new MockModel(['id' => 10]);
        $model->exists = true;
        $model->setRelation('comments', new Collection([
            new MockModel(['id' => 201, 'content' => 'Old Current']),
        ]));

        $hydrated = resolver()->hydrateModelFromSnapshot($model, $snapshot, [
            'hydrate_loaded_relations_only' => true,
            'prune_missing_many_relations' => false,
        ]);

        expect($hydrated->comments->pluck('id')->sort()->values()->all())->toBe([201, 202])
            ->and($hydrated->comments->firstWhere('id', 201)->content)->toBe('Current')
            ->and($hydrated->comments->firstWhere('id', 202)->content)->toBe('Removed Earlier');
    });

    it('does not duplicate removed tombstones when the item already exists in the loaded collection', function () {
        $snapshot = [
            'attributes' => ['id' => 10, 'title' => 'Hydration Test'],
            'relations' => [
                'comments' => [
                    'type' => 'collection',
                    'items' => [
                        201 => [
                            'attributes' => ['id' => 201, 'content' => 'Current'],
                        ],
                    ],
                    '_removed_items' => [
                        201 => [
                            'attributes' => ['id' => 201, 'content' => 'Removed Tombstone'],
                        ],
                    ],
                ],
            ],
        ];

        $model = new MockModel(['id' => 10]);
        $model->exists = true;
        $model->setRelation('comments', new Collection([
            new MockModel(['id' => 201, 'content' => 'Already Loaded']),
        ]));

        $hydrated = resolver()->hydrateModelFromSnapshot($model, $snapshot, [
            'hydrate_loaded_relations_only' => true,
            'prune_missing_many_relations' => false,
        ]);

        expect($hydrated->comments->pluck('id')->all())->toBe([201])
            ->and($hydrated->comments->first()->content)->toBe('Current');
    });

    it('restores removed pivot tombstones with pivot attributes when pruning is disabled', function () {
        $snapshot = [
            'attributes' => ['id' => 10, 'title' => 'Hydration Test'],
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'items' => [
                        51 => [
                            'attributes' => ['id' => 51, 'label' => 'A'],
                            'pivot' => ['doc_id' => 10, 'tag_id' => 51, 'order' => 1],
                        ],
                    ],
                    '_removed_items' => [
                        52 => [
                            'attributes' => ['id' => 52, 'label' => 'B'],
                            'pivot' => ['doc_id' => 10, 'tag_id' => 52, 'order' => 2],
                        ],
                    ],
                ],
            ],
        ];

        $model = new MockModel(['id' => 10]);
        $model->exists = true;
        $model->setRelation('tags', new Collection([
            new MockModel(['id' => 51, 'label' => 'Old A']),
        ]));

        $hydrated = resolver()->hydrateModelFromSnapshot($model, $snapshot, [
            'hydrate_loaded_relations_only' => true,
            'prune_missing_many_relations' => false,
        ]);

        expect($hydrated->tags->pluck('id')->sort()->values()->all())->toBe([51, 52])
            ->and($hydrated->tags->firstWhere('id', 52)->pivot->order)->toBe(2);
    });

    it('handles detached ids in pivot hydration and does not re-add detached items present in items map', function () {
        $snapshot = [
            'attributes' => ['id' => 10, 'title' => 'Hydration Test'],
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'detached' => [52],
                    'items' => [
                        51 => [
                            'attributes' => ['id' => 51, 'label' => 'A'],
                            'pivot' => ['doc_id' => 10, 'tag_id' => 51, 'order' => 1],
                        ],
                        // If this id appears in both items and detached, detached must win.
                        52 => [
                            'attributes' => ['id' => 52, 'label' => 'B'],
                            'pivot' => ['doc_id' => 10, 'tag_id' => 52, 'order' => 2],
                        ],
                    ],
                ],
            ],
        ];

        $model = new MockModel(['id' => 10]);
        $model->exists = true;
        $model->setRelation('tags', new Collection([
            new MockModel(['id' => 51, 'label' => 'Old A']),
            new MockModel(['id' => 52, 'label' => 'Old B']),
        ]));

        $hydrated = resolver()->hydrateModelFromSnapshot($model, $snapshot, [
            'hydrate_loaded_relations_only' => true,
            'prune_missing_many_relations' => true,
        ]);

        expect($hydrated->tags->pluck('id')->all())->toBe([51])
            ->and($hydrated->tags->first()->label)->toBe('A')
            ->and($hydrated->tags->first()->pivot->order)->toBe(1)
            ->and($hydrated->tags->firstWhere('id', 52))->toBeNull();
    });
});
