# Snapshot & Diff Format (Current)

This document reflects the **current, implemented** snapshot and diff structures used by VersionVault.

---

## 1. Snapshot format

A snapshot is a recursive node with attributes and relations.

### Root / model node

```php
[
  '__meta' => [
    'class' => 'App\\Models\\Project',
    'alias' => 'App\\Models\\Project',
    'table' => 'projects',
    'connection' => 'mysql',
    'primary_key' => 'id',
    'id' => 10,
  ],
  'attributes' => [
    'name' => 'Alpha',
  ],
  'relations' => [
    // relation nodes keyed by relation name
  ],
]
```

### Relation node shapes

```php
// Single relation (belongsTo / hasOne / morphOne)
'relations' => [
  'profile' => [
    'type' => 'single',
    'data' => [ /* node */ ],
  ],
]

// Collection relation (hasMany / morphMany)
'relations' => [
  'comments' => [
    'type' => 'collection',
    'items' => [
      101 => [ /* node */ ],
      102 => [ /* node */ ],
    ],
  ],
]

// Pivot relation (belongsToMany)
'relations' => [
  'tags' => [
    'type' => 'pivot',
    'items' => [
      301 => [
        '__meta' => [ /* tag meta */ ],
        'attributes' => [ 'name' => 'Laravel' ],
        'pivot' => [ 'order' => 1 ],
        'relations' => [ /* nested relations if configured */ ],
      ],
    ],
  ],
]
```

Notes:
- `relations` is omitted if empty.
- Pivot data is stored **flat** under `pivot` as attribute key/value pairs.

---

## 2. Diff format

Diffs are **sparse**. They only contain changed fields and relation operations. The root diff is produced by `ChangeDetector::diffSnapshots`.

### Attribute diffs

```php
'attributes' => [
  'name' => [ 'from' => 'Alpha', 'to' => 'Beta' ],
]
```

### Single relation diffs

```php
'relations' => [
  'profile' => [
    'type' => 'single',
    'data' => [
      'attributes' => [ 'phone' => ['from' => '111', 'to' => '222'] ],
    ],
  ],
]
```

Creation/deletion uses markers:

```php
'relations' => [
  'profile' => [ '_created' => true, '_data' => [ /* full snapshot node */ ] ],
]

'relations' => [
  'profile' => [ '_deleted' => true ],
]
```

### Collection diffs

```php
'relations' => [
  'comments' => [
    'type' => 'collection',
    'added' => [103],
    'added_data' => [ 103 => [ /* full node */ ] ],
    'removed' => [102],
    'updated' => [
      101 => [
        'attributes' => [ 'body' => ['from' => 'Old', 'to' => 'New'] ],
      ],
    ],
  ],
]
```

### Pivot diffs

```php
'relations' => [
  'tags' => [
    'type' => 'pivot',
    'attached' => [302],
    'attached_data' => [ 302 => [ /* full node with pivot */ ] ],
    'detached' => [301],
    'updated' => [
      301 => [
        'attributes' => [ 'name' => ['from' => 'A', 'to' => 'B'] ],
        'pivot' => [
          'attributes' => [ 'order' => ['from' => 1, 'to' => 2] ],
        ],
        'relations' => [ /* nested relation diffs */ ],
      ],
    ],
  ],
]
```

---

## 3. Apply (reconstruct) rules

`VersionResolver::applyDiffsToSnapshot` replays diffs sequentially:
- Attribute diffs set the `to` value.
- `_created` replaces with the provided snapshot node.
- `_deleted` removes the node (null for relations).
- Collection and pivot diffs update `items` by `added/removed/updated` or `attached/detached/updated`.

---

## 4. Changed paths

`ChangeDetector::buildChangedPaths()` emits deterministic paths, for example:

- `name`
- `profile.phone`
- `comments[101].body`
- `tags[301].pivot.order`

---

## 5. Debug logging

Enable structured debug logs via:

```php
// config/version-vault.php
'debug' => true,
```

Logs are emitted by `VersionManager` for record/reconstruct/rollback operations.
