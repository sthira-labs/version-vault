<?php

use SthiraLabs\VersionVault\Services\ChangeDetector;

class ChangeDetectorTestProxy extends ChangeDetector
{
    public function callDiffAttributes(array $from, array $to): array
    {
        // Access the protected method directly from the inherited class
        return $this->diffAttributes($from, $to);
    }
}

dataset('diffAttributesCases', [
    'scalar changes' => [
        ['name' => 'Old', 'age' => 25, 'is_active' => true, 'price' => 10.50],
        ['name' => 'New', 'age' => 26, 'is_active' => false, 'price' => 10.51],
        [
            'name' => ['from' => 'Old', 'to' => 'New'],
            'age' => ['from' => 25, 'to' => 26],
            'is_active' => ['from' => true, 'to' => false],
            'price' => ['from' => 10.50, 'to' => 10.51],
        ],
    ],
    'date strings unchanged' => [
        ['due_date' => '2026-02-25'],
        ['due_date' => '2026-02-25'],
        [],
    ],
    'new and removed keys' => [
        ['a' => 1, 'b' => 2],
        ['a' => 1, 'c' => 3],
        [
            'b' => ['from' => 2, 'to' => null],
            'c' => ['from' => null, 'to' => 3],
        ],
    ],
    'null to string' => [
        ['note' => null],
        ['note' => 'Added note'],
        [
            'note' => ['from' => null, 'to' => 'Added note'],
        ],
    ],
    'numeric strings and integers are treated as equivalent' => [
        ['branch_id' => '1', 'language_id' => '2'],
        ['branch_id' => 1, 'language_id' => 2],
        [],
    ],
    'numeric normalization handles sign, decimals, and zero forms' => [
        [
            'a' => '+.500',
            'b' => '-001.00',
            'c' => '000',
            'd' => '-0',
        ],
        [
            'a' => '0.5',
            'b' => -1,
            'c' => 0,
            'd' => 0,
        ],
        [],
    ],
]);

dataset('isEmptyDiffCases', [
    'empty diff' => [[], true],
    'attribute diff' => [['attributes' => ['title' => ['from' => 'Old', 'to' => 'New']]], false],
    'created diff' => [['_created' => true, '_data' => ['attributes' => ['title' => 'New']]], false],
    'deleted diff' => [['_deleted' => true], false],
    'relation diff' => [[
        'relations' => [
            'comments' => [
                'type' => 'collection',
                'added' => [103],
            ],
        ],
    ], false],
]);

dataset('buildChangedPathsCases', [
    'top level attributes' => [[
        'attributes' => [
            'title' => ['from' => 'Old', 'to' => 'New'],
            'price' => ['from' => 10, 'to' => 20],
        ],
    ], ['title', 'price']],
    'single relation attribute' => [[
        'relations' => [
            'author' => [
                'type' => 'single',
                'data' => [
                    'attributes' => [
                        'name' => ['from' => 'J', 'to' => 'K'],
                    ],
                ],
            ],
        ],
    ], ['author.name']],
    'collection ops' => [[
        'relations' => [
            'comments' => [
                'type' => 'collection',
                'added' => [103],
                'removed' => [102],
                'updated' => [
                    101 => [
                        'attributes' => [
                            'text' => ['from' => 'A', 'to' => 'B'],
                        ],
                    ],
                ],
            ],
        ],
    ], [
        'comments.added',
        'comments.103.added',
        'comments.removed',
        'comments.102.removed',
        'comments[101].text',
    ]],
    'pivot ops + nested' => [[
        'relations' => [
            'tags' => [
                'type' => 'pivot',
                'attached' => [7],
                'detached' => [6],
                'updated' => [
                    5 => [
                        'attributes' => [
                            'name' => ['from' => 'O', 'to' => 'N'],
                        ],
                        'pivot' => [
                            'attributes' => ['order' => ['from' => 1, 'to' => 2]],
                        ],
                        'relations' => [
                            'owner' => [
                                'type' => 'single',
                                'data' => [
                                    'attributes' => [
                                        'username' => ['from' => 'A', 'to' => 'B'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ], [
        'tags.attached',
        'tags.7.attached',
        'tags.detached',
        'tags.6.detached',
        'tags[5].name',
        'tags[5].pivot.order',
        'tags[5].owner.username',
    ]],
]);

describe('ChangeDetector', function () {
    // --- Core Function: diffSnapshots ---
    
    test('diffSnapshots returns empty array when both snapshots are null', function () {
        $diff = changeDetector()->diffSnapshots(null, null);
        expect($diff)->toBe([]);
    });
    
    test('diffSnapshots handles full creation when from is null', function () {
        $to = [
            'attributes' => ['title' => 'New Post'],
            'relations' => ['author' => ['type' => 'single', 'data' => ['attributes' => ['name' => 'John']]]]
        ];
        $diff = changeDetector()->diffSnapshots(null, $to);
        
        expect($diff)->toEqual([
            '_created' => true,
            '_data' => $to,
        ]);
    });
    
    test('diffSnapshots handles full deletion when to is null', function () {
        $from = ['attributes' => ['title' => 'Old Post']];
        $diff = changeDetector()->diffSnapshots($from, null);
        
        expect($diff)->toEqual(['_deleted' => true]);
    });
    
    test('diffSnapshots returns empty array when snapshots are identical', function () {
        $snapshot = [
            'attributes' => ['title' => 'Same Title', 'content' => 'Same Content'],
            'relations' => ['author' => ['type' => 'single', 'data' => ['attributes' => ['name' => 'Same']]]]
        ];
        $diff = changeDetector()->diffSnapshots($snapshot, $snapshot);
        expect($diff)->toBe([]);
    });
    
    test('diffSnapshots captures attribute changes and ignores identical relations', function () {
        $from = [
            'attributes' => ['title' => 'Old Title', 'status' => 'draft'],
            'relations' => ['author' => ['type' => 'single', 'data' => ['attributes' => ['name' => 'John']]]]
        ];
        $to = [
            'attributes' => ['title' => 'New Title', 'status' => 'draft'],
            'relations' => ['author' => ['type' => 'single', 'data' => ['attributes' => ['name' => 'John']]]]
        ];
    
        $diff = changeDetector()->diffSnapshots($from, $to);
        
        expect($diff)->toEqual([
            'attributes' => [
                'title' => ['from' => 'Old Title', 'to' => 'New Title']
            ]
        ]);
    });
    
    // --- Attribute Diffing ---
    
    test('diffAttributes captures changes', function (array $from, array $to, array $expected) {
        $diff = changeDetectorProxy()->callDiffAttributes($from, $to);
        expect($diff)->toEqual($expected);
    })->with('diffAttributesCases');
    
    // --- Relation Diffing: Single Model ---
    
    test('diffSnapshots captures changes in a single model relation', function () {
        $from = [
            'relations' => [
                'author' => [
                    'type' => 'single',
                    'data' => [
                        'attributes' => ['name' => 'Jane']
                    ]
                ]
            ]
        ];
        $to = [
            'relations' => [
                'author' => [
                    'type' => 'single',
                    'data' => [
                        'attributes' => ['name' => 'Janet']
                    ]
                ]
            ]
        ];
    
        $diff = changeDetector()->diffSnapshots($from, $to);
    
        expect($diff)->toEqual([
            'relations' => [
                'author' => [
                    'type' => 'single',
                    'data' => [
                        'attributes' => [
                            'name' => ['from' => 'Jane', 'to' => 'Janet']
                        ]
                    ]
                ]
            ]
        ]);
    });
    
    test('diffSnapshots handles deletion of a single model relation', function () {
        $from = [
            'relations' => [
                'author' => ['type' => 'single', 'data' => ['attributes' => ['name' => 'Jane']]]
            ]
        ];
        $to = ['relations' => []];
    
        $diff = changeDetector()->diffSnapshots($from, $to);
        
        expect($diff)->toEqual([
            'relations' => [
                'author' => ['_deleted' => true]
            ]
        ]);
    });
    
    test('diffSnapshots handles creation of a single model relation', function () {
        $from = ['relations' => []];
        $to = [
            'relations' => [
                'author' => ['type' => 'single', 'data' => ['attributes' => ['name' => 'Janet']]]
            ]
        ];
    
        $diff = changeDetector()->diffSnapshots($from, $to);
    
        expect($diff)->toEqual([
            'relations' => [
                'author' => ['_created' => true, '_data' => $to['relations']['author']]
            ]
        ]);
    });
    
    
    // --- Relation Diffing: Collection (hasMany) ---
    
    test('diffSnapshots captures added, removed, and updated items in a collection', function () {
        $from = [
            'relations' => [
                'comments' => [
                    'type' => 'collection',
                    'items' => [
                        101 => ['attributes' => ['text' => 'First Comment']], // Will be updated
                        102 => ['attributes' => ['text' => 'Second Comment']], // Will be removed
                    ]
                ]
            ]
        ];
        $to = [
            'relations' => [
                'comments' => [
                    'type' => 'collection',
                    'items' => [
                        101 => ['attributes' => ['text' => 'Updated Comment']], // Updated
                        103 => ['attributes' => ['text' => 'Third Comment']],  // Added
                    ]
                ]
            ]
        ];
    
        $diff = changeDetector()->diffSnapshots($from, $to);
    
        expect($diff)->toEqual([
            'relations' => [
                'comments' => [
                    'type' => 'collection',
                    'added' => [103],
                    'added_data' => [103 => $to['relations']['comments']['items'][103]],
                    'removed' => [102],
                    'removed_data' => [102 => $from['relations']['comments']['items'][102]],
                    'updated' => [
                        101 => [
                            'attributes' => [
                                'text' => ['from' => 'First Comment', 'to' => 'Updated Comment']
                            ]
                        ]
                    ]
                ]
            ]
        ]);
    });
    
    test('diffSnapshots ignores collection items that are present but unchanged', function () {
        $from = [
            'relations' => [
                'comments' => [
                    'type' => 'collection',
                    'items' => [
                        101 => ['attributes' => ['text' => 'Same Comment']],
                        102 => ['attributes' => ['text' => 'Changed Comment']],
                    ]
                ]
            ]
        ];
        $to = [
            'relations' => [
                'comments' => [
                    'type' => 'collection',
                    'items' => [
                        101 => ['attributes' => ['text' => 'Same Comment']],
                        102 => ['attributes' => ['text' => 'Changed Comment Now']],
                    ]
                ]
            ]
        ];
    
        $diff = changeDetector()->diffSnapshots($from, $to);
    
        expect($diff)->toEqual([
            'relations' => [
                'comments' => [
                    'type' => 'collection',
                    'updated' => [
                        102 => [
                            'attributes' => [
                                'text' => ['from' => 'Changed Comment', 'to' => 'Changed Comment Now']
                            ]
                        ]
                    ]
                ]
            ]
        ]);
    });
    
    // --- Relation Diffing: Pivot (belongsToMany) ---
    
    test('diffSnapshots captures attached, detached, and updated pivot items', function () {
        $from = [
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'items' => [
                        5 => ['attributes' => ['name' => 'Old Tag'], 'pivot' => ['order' => 1]], // Will be updated
                        6 => ['attributes' => ['name' => 'Remove Me'], 'pivot' => ['order' => 2]], // Will be detached
                    ]
                ]
            ]
        ];
        $to = [
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'items' => [
                        5 => ['attributes' => ['name' => 'New Tag'], 'pivot' => ['order' => 1]], // Updated attribute
                        7 => ['attributes' => ['name' => 'New Tag'], 'pivot' => ['order' => 3]], // Attached
                    ]
                ]
            ]
        ];
    
        $diff = changeDetector()->diffSnapshots($from, $to);
    
        expect($diff)->toEqual([
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'attached' => [7],
                    'attached_data' => [7 => $to['relations']['tags']['items'][7]],
                    'detached' => [6],
                    'detached_data' => [6 => $from['relations']['tags']['items'][6]],
                    'updated' => [
                        5 => [
                            'attributes' => [
                                'name' => ['from' => 'Old Tag', 'to' => 'New Tag']
                            ],
                            // Note: Pivot data for tag 5 is NOT included as it's unchanged ('order' is still 1)
                        ]
                    ]
                ]
            ]
        ]);
    });
    
    test('diffSnapshots captures only pivot data change', function () {
        $from = [
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'items' => [
                        5 => ['attributes' => ['name' => 'Tag 1'], 'pivot' => ['attributes' => ['order' => 1, 'active' => true]]],
                    ]
                ]
            ]
        ];
        $to = [
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'items' => [
                        5 => ['attributes' => ['name' => 'Tag 1'], 'pivot' => ['attributes' => ['order' => 2, 'active' => true]]], // Order changed
                    ]
                ]
            ]
        ];
    
        $diff = changeDetector()->diffSnapshots($from, $to);
    
        expect($diff)->toEqual([
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'updated' => [
                        5 => [
                            'pivot' => [
                                'attributes' => ['order' => ['from' => 1, 'to' => 2]]
                            ]
                        ]
                    ]
                ]
            ]
        ]);
    });
    
    test('diffSnapshots handles nested relations within a pivot item update', function () {
        $from = [
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'items' => [
                        5 => [
                            'attributes' => ['name' => 'Tag 1'],
                            'pivot' => ['order' => 1],
                            'relations' => [
                                'owner' => ['type' => 'single', 'data' => ['attributes' => ['username' => 'A']]]
                            ]
                        ],
                    ]
                ]
            ]
        ];
        $to = [
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'items' => [
                        5 => [
                            'attributes' => ['name' => 'Tag 1'],
                            'pivot' => ['order' => 1],
                            'relations' => [
                                'owner' => ['type' => 'single', 'data' => ['attributes' => ['username' => 'B']]] // Nested change
                            ]
                        ],
                    ]
                ]
            ]
        ];
    
        $diff = changeDetector()->diffSnapshots($from, $to);
    
        expect($diff)->toEqual([
            'relations' => [
                'tags' => [
                    'type' => 'pivot',
                    'updated' => [
                        5 => [
                            'relations' => [
                                'owner' => [
                                    'type' => 'single',
                                    'data' => [
                                        'attributes' => [
                                            'username' => ['from' => 'A', 'to' => 'B']
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]);
    });
    
    // --- Utility Method: isEmptyDiff ---
    
    test('isEmptyDiff identifies empty vs non-empty', function (array $diff, bool $expected) {
        expect(changeDetector()->isEmptyDiff($diff))->toBe($expected);
    })->with('isEmptyDiffCases');
    
    // --- Utility Method: buildChangedPaths ---
    
    test('buildChangedPaths returns expected paths', function (array $diff, array $expected) {
        $paths = changeDetector()->buildChangedPaths($diff);
        expect($paths)->toEqual($expected);
    })->with('buildChangedPathsCases');
    
    test('buildChangedPaths handles root creation/deletion metadata correctly', function () {
        $diff = [
            '_created' => true,
            '_data' => ['attributes' => ['title' => 'New']],
            'attributes' => [
                'title' => ['from' => null, 'to' => 'New']
            ]
        ];
        $paths = changeDetector()->buildChangedPaths($diff);
        expect($paths)->toEqual(['title']);
    
        $diff = ['_deleted' => true];
        $paths = changeDetector()->buildChangedPaths($diff);
        expect($paths)->toEqual([]);
    });
});
