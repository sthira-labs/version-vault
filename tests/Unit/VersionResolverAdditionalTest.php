<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use SthiraLabs\VersionVault\Services\VersionResolver;

class VrModel extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    public function child()
    {
        return $this->hasOne(VrModel::class, 'parent_id');
    }

    public function items()
    {
        return $this->hasMany(VrModel::class, 'parent_id');
    }
}

class VrParentModel extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    public function child()
    {
        return $this->hasOne(VrChildModel::class, 'parent_id');
    }

    public function items()
    {
        return $this->hasMany(VrChildModel::class, 'parent_id');
    }

    public function explodeRel()
    {
        throw new RuntimeException('boom');
    }
}

class VrChildModel extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    public function items()
    {
        return $this->hasMany(VrGrandModel::class, 'child_id');
    }

    public function grandchild()
    {
        return $this->hasOne(VrGrandModel::class, 'child_id');
    }

    public function extraChild()
    {
        return $this->hasOne(VrGrandModel::class, 'child_id');
    }

    public function explodeRel()
    {
        throw new RuntimeException('child boom');
    }
}

class VrGrandModel extends Model
{
    protected $guarded = [];
    public $timestamps = false;
}

class VrNestedModel extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    public function child()
    {
        return $this->hasOne(VrNestedChild::class, 'parent_id');
    }

    public function items()
    {
        return $this->hasMany(VrNestedItem::class, 'parent_id');
    }

    public function explodeRel()
    {
        throw new RuntimeException('explode');
    }
}

class VrNestedChild extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    public function child()
    {
        return $this->hasOne(VrNestedItem::class, 'parent_id');
    }

    public function explodeRel()
    {
        throw new RuntimeException('nested boom');
    }
}

class VrNestedItem extends Model
{
    protected $guarded = [];
    public $timestamps = false;
}

class VrBadCtorModel extends Model
{
    public function __construct(array $attributes = [])
    {
        throw new RuntimeException('ctor');
    }
}

class VrBadCtorParent extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    public function items()
    {
        return $this->hasMany(VrBadCtorModel::class, 'parent_id');
    }
}

class VrToggleCtorModel extends Model
{
    public static bool $throwOnConstruct = false;

    public function __construct(array $attributes = [])
    {
        if (self::$throwOnConstruct) {
            throw new RuntimeException('toggle');
        }
        parent::__construct($attributes);
    }
}

class VrToggleParent extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    public function items()
    {
        VrToggleCtorModel::$throwOnConstruct = false;
        $relation = $this->hasMany(VrToggleCtorModel::class, 'parent_id');
        VrToggleCtorModel::$throwOnConstruct = true;
        return $relation;
    }
}

it('keeps relations unchanged for unknown relation type and missing data', function () {
    $resolver = new VersionResolver();

    $base = [
        'attributes' => ['id' => 1],
        'relations' => [
            'mystery' => [
                'type' => 'mystery',
                'data' => ['attributes' => ['id' => 2]],
            ],
            'child' => [
                'type' => 'single',
                'data' => ['attributes' => ['id' => 3, 'name' => 'Child']],
            ],
        ],
    ];

    $diff = [
        'relations' => [
            'mystery' => [
                'type' => 'mystery',
                'data' => ['attributes' => ['id' => 99]],
            ],
            'child' => [
                'type' => 'single',
            ],
        ],
    ];

    $result = $resolver->applyDiffsToSnapshot($base, [$diff]);

    expect($result['relations']['mystery']['data']['attributes']['id'])->toBe(2)
        ->and($result['relations']['child']['data']['attributes']['id'])->toBe(3);
});

it('applies pivot diffs with nested relations', function () {
    $resolver = new VersionResolver();

    $base = [
        'attributes' => ['id' => 1],
        'relations' => [
            'items' => [
                'type' => 'pivot',
                'items' => [
                    5 => [
                        'attributes' => ['id' => 5, 'name' => 'Item'],
                        'pivot' => ['order' => 1],
                        'relations' => [
                            'child' => [
                                'type' => 'single',
                                'data' => ['attributes' => ['id' => 7, 'name' => 'Old']],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $diff = [
        'relations' => [
            'items' => [
                'type' => 'pivot',
                'updated' => [
                    5 => [
                        'pivot' => ['attributes' => ['order' => ['from' => 1, 'to' => 2]]],
                        'relations' => [
                            'child' => [
                                'type' => 'single',
                                'data' => [
                                    'attributes' => [
                                        'name' => ['from' => 'Old', 'to' => 'New'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $result = $resolver->applyDiffsToSnapshot($base, [$diff]);

    expect($result['relations']['items']['items'][5]['pivot']['order'])->toBe(2)
        ->and($result['relations']['items']['items'][5]['relations']['child']['data']['attributes']['name'])->toBe('New');
});

it('hydrates with strict attribute preservation and skips unknown relations', function () {
    $resolver = new VersionResolver();
    $template = new VrModel(['keep' => 'yes']);
    $template->exists = true;

    $currentItems = new Collection([
        new VrModel(['id' => 1, 'name' => 'Item1']),
    ]);
    $template->setRelation('items', $currentItems);

    $snapshot = [
        'attributes' => [],
        'relations' => [
            'items' => null,
            'unknown' => ['type' => 'single', 'data' => ['attributes' => ['id' => 99]]],
            'child' => ['type' => 'mystery'],
        ],
    ];

    $hydrated = $resolver->hydrateModelFromSnapshot($template, $snapshot, [
        'preserve_missing_attributes' => false,
        'hydrate_loaded_relations_only' => false,
        'attach_unloaded_relations' => true,
    ]);

    expect($hydrated->getAttributes())->toBe([])
        ->and($hydrated->relationLoaded('items'))->toBeTrue()
        ->and($hydrated->items)->toBe($currentItems)
        ->and($hydrated->relationLoaded('unknown'))->toBeFalse();
});

it('applies relation diffs for creation, deletion, and null initialization', function () {
    $resolver = new VersionResolver();

    $base = [
        'attributes' => [],
        'relations' => [
            'child' => [
                'type' => 'single',
                'data' => ['attributes' => ['id' => 1]],
            ],
        ],
    ];

    $diffs = [
        [
            'relations' => [
                'child' => ['_deleted' => true],
            ],
        ],
        [
            'relations' => [
                'items' => [
                    'type' => 'collection',
                    'added' => [1],
                    'added_data' => [
                        1 => ['attributes' => ['id' => 1, 'name' => 'Item']],
                    ],
                ],
            ],
        ],
    ];

    $result = $resolver->applyDiffsToSnapshot($base, $diffs);

    expect($result['relations']['child'])->toBeNull()
        ->and($result['relations']['items']['items'][1]['attributes']['name'])->toBe('Item');
});

it('creates pivot containers when missing during pivot updates', function () {
    $resolver = new VersionResolver();

    $base = [
        'attributes' => ['id' => 1],
        'relations' => [
            'tags' => [
                'type' => 'pivot',
                'items' => [
                    5 => [
                        'attributes' => ['id' => 5, 'name' => 'Tag'],
                    ],
                ],
            ],
        ],
    ];

    $diff = [
        'relations' => [
            'tags' => [
                'type' => 'pivot',
                'updated' => [
                    5 => [
                        'pivot' => [
                            'order' => ['from' => null, 'to' => 3],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $result = $resolver->applyDiffsToSnapshot($base, [$diff]);

    expect($result['relations']['tags']['items'][5]['pivot']['order'])->toBe(3);
});

it('hydrates attributes with preserve_missing_attributes disabled', function () {
    $resolver = new VersionResolver();

    $template = new VrParentModel(['keep' => 'yes']);
    $template->exists = true;

    $snapshot = [
        'attributes' => ['id' => 10, 'name' => 'Only'],
        'relations' => [],
    ];

    $hydrated = $resolver->hydrateModelFromSnapshot($template, $snapshot, [
        'preserve_missing_attributes' => false,
    ]);

    expect($hydrated->getAttributes())->toEqual(['id' => 10, 'name' => 'Only'])
        ->and($hydrated->exists)->toBeTrue();
});

it('skips relations when resolution fails and handles unexpected current relation types', function () {
    $resolver = new VersionResolver();

    $template = new VrParentModel(['id' => 1]);
    $template->setRelation('child', 'weird');

    $snapshot = [
        'attributes' => ['id' => 1],
        'relations' => [
            'child' => [
                'type' => 'single',
                'data' => ['attributes' => ['id' => 2]],
            ],
            'explodeRel' => [
                'type' => 'single',
                'data' => ['attributes' => ['id' => 3]],
            ],
        ],
    ];

    $hydrated = $resolver->hydrateModelFromSnapshot($template, $snapshot, [
        'hydrate_loaded_relations_only' => false,
        'attach_unloaded_relations' => true,
    ]);

    expect($hydrated->child)->toBeInstanceOf(Model::class)
        ->and($hydrated->relationLoaded('explodeRel'))->toBeFalse();
});

it('does not set relations when canonical data is null in force-replace', function () {
    $resolver = new VersionResolver();

    $template = new VrParentModel(['id' => 1]);
    $snapshot = [
        'attributes' => ['id' => 1],
        'relations' => [
            'child' => null,
        ],
    ];

    $hydrated = $resolver->hydrateModelFromSnapshot($template, $snapshot, [
        'force_replace_relation' => true,
        'hydrate_loaded_relations_only' => false,
        'attach_unloaded_relations' => true,
    ]);

    expect($hydrated->relationLoaded('child'))->toBeFalse();
});

it('hydrates nested relations, including created/data nodes and pivot attachments', function () {
    $resolver = new VersionResolver();

    $template = new VrNestedModel();

    $snapshot = [
        'attributes' => ['id' => 1],
        'relations' => [
            'items' => [
                'type' => 'collection',
                'items' => [
                    1 => [
                        'created' => [
                            'attributes' => ['id' => 1, 'name' => 'Created'],
                            'pivot' => ['role' => 'a'],
                        ],
                    ],
                    2 => [
                        'data' => [
                            'attributes' => ['id' => 2, 'name' => 'Data'],
                        ],
                    ],
                ],
            ],
            'child' => [
                'type' => 'single',
                'data' => [
                    'attributes' => ['id' => 3, 'name' => 'Child'],
                    'relations' => [
                        'items' => [
                            'type' => 'collection',
                            'items' => [
                                4 => [
                                    'attributes' => ['id' => 4, 'name' => 'Nested Item'],
                                ],
                            ],
                        ],
                        'explodeRel' => [
                            'type' => 'single',
                            'data' => ['attributes' => ['id' => 5]],
                        ],
                        'child' => [
                            'type' => 'single',
                            'data' => ['attributes' => ['id' => 8, 'name' => 'Sub']],
                        ],
                        'missingRel' => [
                            'type' => 'single',
                            'data' => ['attributes' => ['id' => 6]],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $hydrated = $resolver->hydrateModelFromSnapshot($template, $snapshot, [
        'hydrate_loaded_relations_only' => false,
        'attach_unloaded_relations' => true,
        'force_replace_relation' => true,
    ]);

    expect($hydrated->items)->toBeInstanceOf(Collection::class)
        ->and($hydrated->items->first()->pivot->role)->toBe('a')
        ->and($hydrated->child)->toBeInstanceOf(Model::class);
});

it('updates in-place nested relations with added_data and attach_unloaded_relations', function () {
    $resolver = new VersionResolver();

    $template = new VrParentModel(['id' => 1]);
    $child = new VrChildModel(['id' => 10, 'name' => 'Old']);
    $child->setRelation('items', new Collection([new VrGrandModel(['id' => 1, 'name' => 'A'])]));
    $template->setRelation('child', $child);

    $snapshot = [
        'attributes' => ['id' => 1],
        'relations' => [
            'child' => [
                'type' => 'single',
                'data' => [
                    'attributes' => ['id' => 10, 'name' => 'New'],
                    'pivot' => ['role' => 'p'],
                    'relations' => [
                        'items' => [
                            'type' => 'collection',
                            'removed' => [1],
                            'added_data' => [
                                2 => [
                                    'created' => [
                                        'attributes' => ['id' => 2, 'name' => 'B'],
                                        'pivot' => ['role' => 'child'],
                                    ],
                                ],
                            ],
                        ],
                        'grandchild' => [
                            'type' => 'single',
                            'data' => ['attributes' => ['id' => 50, 'name' => 'Skip']],
                        ],
                        'missingRel' => [
                            'type' => 'single',
                            'data' => ['attributes' => ['id' => 99]],
                        ],
                        'explodeRel' => [
                            'type' => 'single',
                            'data' => ['attributes' => ['id' => 98]],
                        ],
                        'items_extra' => [
                            'type' => 'single',
                            'data' => ['attributes' => ['id' => 97]],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $hydrated = $resolver->hydrateModelFromSnapshot($template, $snapshot, [
        'hydrate_loaded_relations_only' => true,
        'attach_unloaded_relations' => true,
    ]);

    expect($hydrated->child->pivot->role)->toBe('p')
        ->and($hydrated->child->items)->toBeInstanceOf(Collection::class);
});

it('keeps existing single relations when relation data is null', function () {
    $resolver = new VersionResolver();

    $template = new VrParentModel(['id' => 1]);
    $child = new VrChildModel(['id' => 2]);
    $template->setRelation('child', $child);

    $snapshot = [
        'attributes' => ['id' => 1],
        'relations' => [
            'child' => null,
        ],
    ];

    $hydrated = $resolver->hydrateModelFromSnapshot($template, $snapshot, [
        'hydrate_loaded_relations_only' => true,
    ]);

    expect($hydrated->child)->toBe($child);
});

it('attaches unloaded nested relations when configured', function () {
    $resolver = new VersionResolver();

    $template = new VrParentModel(['id' => 1]);
    $child = new VrChildModel(['id' => 10, 'name' => 'Old']);
    $template->setRelation('child', $child);

    $snapshot = [
        'attributes' => ['id' => 1],
        'relations' => [
            'child' => [
                'type' => 'single',
                'data' => [
                    'attributes' => ['id' => 10, 'name' => 'New'],
                    'relations' => [
                        'grandchild' => [
                            'type' => 'single',
                            'data' => ['attributes' => ['id' => 99, 'name' => 'GC']],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $hydrated = $resolver->hydrateModelFromSnapshot($template, $snapshot, [
        'hydrate_loaded_relations_only' => false,
        'attach_unloaded_relations' => true,
    ]);

    expect($hydrated->child->grandchild)->toBeInstanceOf(Model::class);
});

it('hydrates collections with created/data nodes and attaches nested relations', function () {
    $resolver = new VersionResolver();

    $template = new VrParentModel(['id' => 1]);
    $existingItem = new VrChildModel(['id' => 1, 'name' => 'Existing']);
    $existingItem->setRelation('items', new Collection([new VrGrandModel(['id' => 10, 'name' => 'Nested'])]));
    $existingItem->setRelation('grandchild', new VrGrandModel(['id' => 11, 'name' => 'GC']));
    $template->setRelation('items', new Collection([$existingItem]));

    $snapshot = [
        'attributes' => ['id' => 1],
        'relations' => [
            'items' => [
                'type' => 'collection',
                'items' => [
                    1 => [
                        'data' => [
                            'attributes' => ['id' => 1, 'name' => 'Updated'],
                            'relations' => [
                                'grandchild' => [
                                    'type' => 'single',
                                    'data' => ['attributes' => ['id' => 2, 'name' => 'GC']],
                                ],
                                'items' => [
                                    'type' => 'collection',
                                    'items' => [
                                        10 => [
                                            'attributes' => ['id' => 10, 'name' => 'Nested Updated'],
                                        ],
                                    ],
                                ],
                                'extraChild' => [
                                    'type' => 'single',
                                    'data' => ['attributes' => ['id' => 12, 'name' => 'Extra']],
                                ],
                                'explodeRel' => [
                                    'type' => 'single',
                                    'data' => ['attributes' => ['id' => 3]],
                                ],
                            ],
                        ],
                    ],
                    2 => [
                        'created' => [
                            'attributes' => ['id' => 2, 'name' => 'Created'],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $hydrated = $resolver->hydrateModelFromSnapshot($template, $snapshot, [
        'hydrate_loaded_relations_only' => false,
        'attach_unloaded_relations' => true,
    ]);

    expect($hydrated->items)->toBeInstanceOf(Collection::class)
        ->and($hydrated->items->count())->toBe(2);
});

it('handles related class constructor failures when hydrating collections', function () {
    $resolver = new VersionResolver();

    $template = new VrBadCtorParent(['id' => 1]);
    $template->setRelation('items', new Collection([new VrChildModel(['id' => 1])]));

    $snapshot = [
        'attributes' => ['id' => 1],
        'relations' => [
            'items' => [
                'type' => 'collection',
                'added_data' => [
                    2 => ['attributes' => ['id' => 2, 'name' => 'B']],
                ],
            ],
        ],
    ];

    $hydrated = $resolver->hydrateModelFromSnapshot($template, $snapshot, [
        'hydrate_loaded_relations_only' => false,
    ]);

    expect($hydrated->items)->toBeInstanceOf(Collection::class);
});

it('catches relation resolution errors during in-place hydration', function () {
    $resolver = new VersionResolver();

    $template = new VrParentModel(['id' => 1]);
    $child = new VrChildModel(['id' => 10, 'name' => 'Old']);
    $template->setRelation('child', $child);

    $snapshot = [
        'attributes' => ['id' => 1],
        'relations' => [
            'child' => [
                'type' => 'single',
                'data' => [
                    'attributes' => ['id' => 10, 'name' => 'New'],
                    'relations' => [
                        'explodeRel' => [
                            'type' => 'single',
                            'data' => ['attributes' => ['id' => 99]],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $hydrated = $resolver->hydrateModelFromSnapshot($template, $snapshot, [
        'hydrate_loaded_relations_only' => false,
    ]);

    expect($hydrated->child->name)->toBe('New');
});

it('falls back when related class instantiation fails in collection hydration', function () {
    $resolver = new VersionResolver();

    $template = new VrToggleParent(['id' => 1]);
    $template->setRelation('items', new Collection([new VrChildModel(['id' => 1])]));

    VrToggleCtorModel::$throwOnConstruct = true;

    $snapshot = [
        'attributes' => ['id' => 1],
        'relations' => [
            'items' => [
                'type' => 'collection',
                'items' => [],
            ],
        ],
    ];

    $hydrated = $resolver->hydrateModelFromSnapshot($template, $snapshot, [
        'hydrate_loaded_relations_only' => false,
    ]);

    expect($hydrated->items)->toBeInstanceOf(Collection::class);
});
