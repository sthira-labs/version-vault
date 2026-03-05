<?php

use SthiraLabs\VersionVault\Services\ChangeDetector;

it('handles unknown relation types and null relations safely', function () {
    $detector = new ChangeDetector();

    $from = [
        'relations' => [
            'weird' => ['type' => 'mystery', 'data' => ['attributes' => ['id' => 1]]],
            'null_rel' => null,
        ],
    ];
    $to = [
        'relations' => [
            'weird' => ['type' => 'mystery', 'data' => ['attributes' => ['id' => 1]]],
            'null_rel' => null,
        ],
    ];

    $diff = $detector->diffSnapshots($from, $to);
    expect($diff)->toBe([]);
});

it('builds changed paths for top-level ops, nested relations, and recursive arrays', function () {
    $detector = new ChangeDetector();

    $diff = [
        'attributes' => [
            'title' => ['from' => 'A', 'to' => 'B'],
        ],
        'relations' => [
            'tags' => [
                'type' => 'pivot',
                'attached' => [1],
                'updated' => [
                    1 => [
                        'pivot' => [
                            'attributes' => [
                                'order' => ['from' => 1, 'to' => 2],
                            ],
                        ],
                        'relations' => [
                            'author' => [
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
        // Force recursive extraction for arbitrary arrays
        'misc' => [
            'attributes' => [
                'flag' => ['from' => 0, 'to' => 1],
            ],
        ],
    ];

    $paths = $detector->buildChangedPaths($diff);

    expect($paths)->toContain('title')
        ->and($paths)->toContain('tags.attached')
        ->and($paths)->toContain('tags[1].pivot.order')
        ->and($paths)->toContain('tags[1].author.name')
        ->and($paths)->toContain('misc.flag');
});

it('treats primitive non-null diff values as non-empty', function () {
    $detector = new ChangeDetector();

    expect($detector->isEmptyDiff(['foo' => 1]))->toBeFalse()
        ->and($detector->isEmptyDiff(['_deleted' => true]))->toBeFalse();
});

it('records top-level add/remove operations in change paths', function () {
    $detector = new ChangeDetector();

    $diff = [
        'added' => [1],
        'removed' => [2],
    ];

    $paths = $detector->buildChangedPaths($diff);

    expect($paths)->toContain('added')
        ->and($paths)->toContain('removed');
});
