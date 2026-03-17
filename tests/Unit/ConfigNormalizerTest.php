<?php

use SthiraLabs\VersionVault\Services\ConfigNormalizer; // Updated namespace and class name

// Helper function to resolve the service for testing
function normalizer(): ConfigNormalizer
{
    return new ConfigNormalizer();
}

// Define the base structure for easy comparison
const CANONICAL_NODE = [
    'attributes' => [],
    'relations' => [],
    'pivot' => [],
];

dataset('configNormalizerSimpleCases', [
    'basic root attributes' => [
        ['first_name', 'email', 'is_active'],
        [
            'attributes' => ['first_name', 'email', 'is_active'],
            'relations' => [],
        ],
    ],
    'canonical keywords as attributes' => [
        ['attributes', 'relations', 'pivot'],
        [
            'attributes' => ['attributes', 'relations', 'pivot'],
            'relations' => [],
        ],
    ],
    'shorthand relation attributes' => [
        ['representativeDetail:type,name,primary_phone'],
        [
            'attributes' => [],
            'relations' => [
                'representativeDetail' => array_merge(CANONICAL_NODE, [
                    'attributes' => ['type', 'name', 'primary_phone'],
                ]),
            ],
        ],
    ],
    'wildcard attributes' => [
        ['addresses:*'],
        [
            'attributes' => [],
            'relations' => [
                'addresses' => array_merge(CANONICAL_NODE, [
                    'attributes' => ['*'],
                ]),
            ],
        ],
    ],
]);

describe('ConfigNormalizer', function () {
    test('it normalizes basic and shorthand configs', function (array $config, array $expected) {
        expect(normalizer()->normalize($config))->toEqual($expected);
    })->with('configNormalizerSimpleCases');
    
    test('it correctly parses relations with attributes and pivot fields (robust test)', function () {
        $config = [
            // Attribute1, Attribute2, Pivot(field1, field2)
            'programs:name,status,pivot(effective_from,effective_till)',
            // Pivot only
            'members:pivot(user_id,role)',
            // Attributes only
            'settings:key,value'
        ];
    
        $expected = [
            'attributes' => [],
            'relations' => [
                'programs' => array_merge(CANONICAL_NODE, [
                    'attributes' => ['name', 'status'],
                    'pivot' => ['effective_from', 'effective_till'],
                ]),
                'members' => array_merge(CANONICAL_NODE, [
                    'attributes' => [], // Correctly empty
                    'pivot' => ['user_id', 'role'],
                ]),
                'settings' => array_merge(CANONICAL_NODE, [
                    'attributes' => ['key', 'value'],
                ]),
            ],
        ];
    
        expect(normalizer()->normalize($config))->toEqual($expected);
    });
    
    test('it handles nested configuration recursively', function () {
        $config = [
            'clientContacts:user_id,name' => [
                'personalContact:*',
                'addresses:line_1,city_name',
            ],
        ];
    
        $expected = [
            'attributes' => [],
            'relations' => [
                'clientContacts' => array_merge(CANONICAL_NODE, [
                    'attributes' => ['user_id', 'name'],
                    'relations' => [
                        'personalContact' => array_merge(CANONICAL_NODE, [
                            'attributes' => ['*'],
                        ]),
                        'addresses' => array_merge(CANONICAL_NODE, [
                            'attributes' => ['line_1', 'city_name'],
                        ]),
                    ],
                ]),
            ],
        ];
    
        expect(normalizer()->normalize($config))->toEqual($expected);
    });
    
    test('it allows mixing shorthand and canonical formats in input', function () {
        $config = [
            // 1. Shorthand
            'user:id,name',
    
            // 2. Explicit Canonical (Metadata node is already normalized)
            'metadata' => [
                'attributes' => ['created_at', 'updated_at'],
                'pivot' => ['revision_id'], // relations is omitted but will be merged
            ],
    
            // 3. Shorthand with nesting
            'children:name' => [
                'favorite_toy:*'
            ]
        ];
    
        $expected = [
            'attributes' => [],
            'relations' => [
                'user' => array_merge(CANONICAL_NODE, [
                    'attributes' => ['id', 'name'],
                ]),
                'metadata' => [ // This node is explicitly checked and merged
                    'attributes' => ['created_at', 'updated_at'],
                    'relations'  => [], // Auto-merged
                    'pivot'      => ['revision_id'],
                ],
                'children' => array_merge(CANONICAL_NODE, [
                    'attributes' => ['name'],
                    'relations' => [
                        'favorite_toy' => array_merge(CANONICAL_NODE, [
                            'attributes' => ['*'],
                        ]),
                    ],
                ]),
            ],
        ];
    
        expect(normalizer()->normalize($config))->toEqual($expected);
    });
    
    test('it handles recursive shorthand inside an explicit canonical node', function () {
        $config = [
            // This is the semi-normalized input: Canonical attributes/pivot, but un-normalized relations
            'document_revision' => [
                'attributes' => ['version_number'],
                'pivot' => ['verified_by'],
                'relations' => [
                    'reviewer:name,email', // <-- This is the shorthand that needs recursive normalization
                    'attached_files:*',
                ]
            ],
        ];
    
        $expected = [
            'attributes' => [],
            'relations' => [
                'document_revision' => [
                    'attributes' => ['version_number'],
                    'pivot' => ['verified_by'],
                    'relations' => [
                        // These two nested relations must now be canonical nodes themselves
                        'reviewer' => array_merge(CANONICAL_NODE, [
                            'attributes' => ['name', 'email'],
                        ]),
                        'attached_files' => array_merge(CANONICAL_NODE, [
                            'attributes' => ['*'],
                        ]),
                    ],
                ],
            ],
        ];
    
        expect(normalizer()->normalize($config))->toEqual($expected);
    });
    
    test('it handles canonical key aliases and recursive normalization within explicit nodes', function () {
        $config = [
            'product' => [
                'attrs' => ['sku', 'price'], // Alias for attributes
                'pivot' => ['quantity'],
                'realtions' => [ // Alias for relations
                    'images:url', // Shorthand needs normalization
                ]
            ],
        ];
    
        $expected = [
            'attributes' => [],
            'relations' => [
                'product' => [
                    'attributes' => ['sku', 'price'],
                    'pivot' => ['quantity'],
                    'relations' => [
                        'images' => array_merge(CANONICAL_NODE, [
                            'attributes' => ['url'],
                        ]),
                    ],
                ],
            ],
        ];
    
        expect(normalizer()->normalize($config))->toEqual($expected);
    });
    
    test('it is idempotent and returns the same structure when canonical input is passed', function () {
        // 1. Define a complex canonical structure
        $canonicalInput = [
            'attributes' => ['name', 'slug'],
            'relations' => [
                'users' => [
                    'attributes' => ['id', 'email'],
                    'relations' => [],
                    'pivot' => ['role'],
                ],
                'settings' => CANONICAL_NODE,
            ],
        ];
    
        // 2. The function should return the identical structure
        $expected = $canonicalInput;
    
        // 3. Run the normalization
        $result = normalizer()->normalize($canonicalInput);
    
        // 4. Assertion: Input should strictly equal output
        expect($result)->toEqual($expected);
    });
    
    
    test('it throws an exception for non-string integer-indexed entries', function () {
        $config = [
            'valid_attribute',
            123, // Invalid entry type
        ];

        expect(fn() => normalizer()->normalize($config))->toThrow(InvalidArgumentException::class);
    });

    test('it supports boolean true shorthand for relations without fields', function () {
        $config = [
            'documents' => true,
        ];

        $expected = [
            'attributes' => [],
            'relations' => [
                'documents' => array_merge(CANONICAL_NODE, [
                    'attributes' => [],
                    'relations' => [],
                    'pivot' => [],
                ]),
            ],
        ];

        expect(normalizer()->normalize($config))->toEqual($expected);
    });
});
