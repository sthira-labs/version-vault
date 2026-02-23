<?php

namespace SthiraLabs\VersionVault\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * VersionResolver - Reconstructs model state from snapshots and diffs.
 *
 * Responsibilities:
 * 1. Apply sequential diffs to a base snapshot
 * 2. Hydrate Laravel models from canonical snapshots
 * 3. Handle all relation types correctly
 */
class VersionResolver
{
    /**
     * Apply sequential diffs to base snapshot.
     *
     * Replays changes in order to reconstruct state at target version.
     *
     * @param array|null $base Base snapshot
     * @param array $diffs Sequential diffs to apply
     * @return array Reconstructed snapshot
     */
    public function applyDiffsToSnapshot(?array $base, array $diffs): array
    {
        $state = $base ?? ['attributes' => [], 'relations' => []];

        foreach ($diffs as $diff) {
            $state = $this->applyDiff($state, $diff);
        }

        return $state;
    }

    /**
     * Apply a single diff to a snapshot.
     *
     * @param array $snapshot Current snapshot
     * @param array $diff Diff to apply
     * @return array Updated snapshot
     */
    protected function applyDiff(array $snapshot, array $diff): array
    {
        // Handle full creation/deletion
        if (isset($diff['_created']) && $diff['_created']) {
            return $diff['_data'] ?? [];
        }

        if (isset($diff['_deleted']) && $diff['_deleted']) {
            return [];
        }

        // Apply attribute changes
        if (isset($diff['attributes'])) {
            foreach ($diff['attributes'] as $key => $change) {
                $snapshot['attributes'][$key] = $change['to'];
            }
        }

        // Apply relation changes
        if (isset($diff['relations'])) {
            foreach ($diff['relations'] as $relationName => $relationDiff) {
                $snapshot['relations'][$relationName] = $this->applyRelationDiff(
                    $snapshot['relations'][$relationName] ?? null,
                    $relationDiff
                );
            }
        }

        return $snapshot;
    }

    /**
     * Apply diff to a single relation.
     *
     * @param array|null $relation Current relation state
     * @param array $diff Relation diff
     * @return array|null Updated relation
     */
    protected function applyRelationDiff(?array $relation, array $diff): ?array
    {
        // Handle creation/deletion
        if (isset($diff['_created']) && $diff['_created']) {
            return $diff['_data'] ?? null;
        }

        if (isset($diff['_deleted']) && $diff['_deleted']) {
            return null;
        }

        // Initialize if null
        if ($relation === null) {
            $relation = ['type' => $diff['type'] ?? 'single'];
        }

        $type = $diff['type'] ?? $relation['type'] ?? 'single';

        switch ($type) {
            case 'single':
                return $this->applySingleModelDiff($relation, $diff);

            case 'collection':
                return $this->applyCollectionDiff($relation, $diff);

            case 'pivot':
                return $this->applyPivotDiff($relation, $diff);

            default:
                return $relation;
        }
    }

    /**
     * Apply diff to single model relation.
     *
     * @param array $relation Current relation
     * @param array $diff Diff to apply
     * @return array Updated relation
     */
    protected function applySingleModelDiff(array $relation, array $diff): array
    {
        if (!isset($diff['data'])) {
            return $relation;
        }

        $currentData = $relation['data'] ?? [];
        $updatedData = $this->applyDiff($currentData, $diff['data']);

        return [
            'type' => 'single',
            'data' => $updatedData
        ];
    }

    /**
     * Apply diff to collection relation.
     *
     * @param array $relation Current relation
     * @param array $diff Diff to apply
     * @return array Updated relation
     */
    protected function applyCollectionDiff(array $relation, array $diff): array
    {
        $items = $relation['items'] ?? [];

        // Remove items
        if (isset($diff['removed'])) {
            foreach ($diff['removed'] as $id) {
                unset($items[$id]);
            }
        }

        // Add items
        if (isset($diff['added']) && isset($diff['added_data'])) {
            foreach ($diff['added_data'] as $id => $itemData) {
                $items[$id] = $itemData;
            }
        }

        // Update items
        if (isset($diff['updated'])) {
            foreach ($diff['updated'] as $id => $itemDiff) {
                if (isset($items[$id])) {
                    $items[$id] = $this->applyDiff($items[$id], $itemDiff);
                }
            }
        }

        return [
            'type' => 'collection',
            'items' => $items
        ];
    }

    /**
     * Apply diff to pivot relation.
     *
     * @param array $relation Current relation
     * @param array $diff Diff to apply
     * @return array Updated relation
     */
    protected function applyPivotDiff(array $relation, array $diff): array
    {
        $items = $relation['items'] ?? [];

        // Detach items
        if (isset($diff['detached'])) {
            foreach ($diff['detached'] as $id) {
                unset($items[$id]);
            }
        }

        // Attach items
        if (isset($diff['attached']) && isset($diff['attached_data'])) {
            foreach ($diff['attached_data'] as $id => $itemData) {
                $items[$id] = $itemData;
            }
        }

        // Update items (attributes or pivot)
        if (isset($diff['updated'])) {
            foreach ($diff['updated'] as $id => $itemDiff) {
                if (isset($items[$id])) {
                    // Apply attribute changes
                    if (isset($itemDiff['attributes'])) {
                        foreach ($itemDiff['attributes'] as $key => $change) {
                            $items[$id]['attributes'][$key] = $change['to'];
                        }
                    }

                    // Apply pivot changes
                    if (isset($itemDiff['pivot'])) {
                        if (!isset($items[$id]['pivot'])) {
                            $items[$id]['pivot'] = [];
                        }
                        $pivotChanges = $itemDiff['pivot']['attributes'] ?? $itemDiff['pivot'];
                        foreach ($pivotChanges as $key => $change) {
                            $items[$id]['pivot'][$key] = $change['to'];
                        }
                    }

                    // Apply nested relation changes
                    if (isset($itemDiff['relations'])) {
                        foreach ($itemDiff['relations'] as $relName => $relDiff) {
                            $items[$id]['relations'][$relName] = $this->applyRelationDiff(
                                $items[$id]['relations'][$relName] ?? null,
                                $relDiff
                            );
                        }
                    }
                }
            }
        }

        return [
            'type' => 'pivot',
            'items' => $items
        ];
    }

    /**
     * Hydrate Laravel model from canonical snapshot.
     *
     * Creates a new model instance with attributes and relations per canonical snapshot.
     * Behavior is non-destructive by default: it will not remove attributes or relation
     * items that are absent from the snapshot unless the options explicitly request it.
     *
     * @param Model $templateModel Template model (for class/structure and relation definitions)
     * @param array $snapshot Canonical snapshot produced by applyDiffsToSnapshot()
     * @param array $options Options to control non-destructive behavior:
     *   - hydrate_loaded_relations_only (bool) : only hydrate relations that are already loaded on $templateModel (default true)
     *   - preserve_missing_attributes (bool)   : do not clear attributes missing from snapshot (default true)
     *   - attach_unloaded_relations (bool)     : if true, create relation instances for relations not loaded (default false)
     *   - force_replace_relation (bool)        : if true, replace relation objects rather than update in-place (default false)
     * @return Model Hydrated model instance (NOT persisted)
     */
    public function hydrateModelFromSnapshot(Model $templateModel, array $snapshot, array $options = []): Model
    {
        $opts = array_merge([
            'hydrate_loaded_relations_only' => true,
            'preserve_missing_attributes'   => true,
            'attach_unloaded_relations'     => false,
            'force_replace_relation'        => false,
        ], $options);

        // Create a fresh instance of the same class (do NOT use replicate on an existing persisted instance)
        $class = get_class($templateModel);
        $instance = new $class();

        // ---------- ATTRIBUTES ----------
        $attributes = $this->mergePrimaryKeyFromMeta($snapshot);

        // Apply attributes present in snapshot
        if (!empty($attributes)) {
            // Use setRawAttributes to avoid mutators interfering; still mark as exists if PK present
            $instance->setRawAttributes($attributes, true);
        } else {
            // If no attributes in snapshot and preserve_missing_attributes = false,
            // reset to empty attributes (rare; be cautious)
            if (!$opts['preserve_missing_attributes']) {
                $instance->setRawAttributes([], true);
            }
        }

        // Ensure exists flag if primary key is present
        $pk = $instance->getKeyName();
        if (isset($attributes[$pk]) && $attributes[$pk] !== null) {
            $instance->exists = true;
        }

        // ---------- RELATIONS ----------
        $relations = $snapshot['relations'] ?? [];

        foreach ($relations as $relationName => $relationData) {
            // If relation not defined on model, skip safely
            if (!method_exists($instance, $relationName)) {
                continue;
            }

            $isLoaded = $templateModel->relationLoaded($relationName);
            if ($opts['hydrate_loaded_relations_only'] && !$isLoaded && !$opts['attach_unloaded_relations']) {
                // Respect loaded-only behavior: skip hydration for unloaded relation
                continue;
            }

            // Determine current relation value from templateModel if loaded, else null
            $currentRelationValue = $isLoaded ? $templateModel->getRelation($relationName) : null;

            // Obtain related model class via relation method (safe try/catch)
            try {
                $relationInstance = $templateModel->{$relationName}();
                $relatedClass = get_class($relationInstance->getRelated());
            } catch (\Throwable $e) {
                // Relation resolution failed; skip
                continue;
            }

            // If force_replace_relation or there is no current relation value, build new relation value
            if ($opts['force_replace_relation'] || $currentRelationValue === null) {
                $newRelation = $this->buildRelationFromCanonical($relatedClass, $relationData);
                // setRelation marks relation as loaded on the hydrated instance
                if ($newRelation !== null) {
                    $instance->setRelation($relationName, $newRelation);
                }
                continue;
            }

            // Otherwise update in-place (non-destructive)
            if ($currentRelationValue instanceof \Illuminate\Database\Eloquent\Collection) {
                // Update collection in place
                $updatedCollection = $this->applyCollectionHydrationInPlace(
                    $currentRelationValue,
                    $relationData,
                    $relatedClass,
                    $opts
                );
                $instance->setRelation($relationName, $updatedCollection);
            } elseif ($currentRelationValue instanceof Model) {
                $updatedModel = $this->applySingleModelHydrationInPlace(
                    $currentRelationValue,
                    $relationData,
                    $relatedClass,
                    $opts
                );
                $instance->setRelation($relationName, $updatedModel);
            } else {
                // Unexpected type, fall back to building from canonical
                $built = $this->buildRelationFromCanonical($relatedClass, $relationData);
                if ($built !== null) {
                    $instance->setRelation($relationName, $built);
                }
            }
        }

        return $instance;
    }

    /**
     * Build relation value (Model or Collection) from canonical relation node.
     * Instances created here are NOT persisted.
     *
     * @param string $relatedClass
     * @param array|null $relationData
     * @return Model|\Illuminate\Database\Eloquent\Collection|null
     */
    protected function buildRelationFromCanonical(string $relatedClass, ?array $relationData)
    {
        if ($relationData === null) {
            return null;
        }

        $type = $relationData['type'] ?? 'single';

        switch ($type) {
            case 'single':
                $node = $relationData['data'] ?? [];
                return $this->hydrateSingleModel($relatedClass, $node);

            case 'collection':
            case 'pivot':
                $items = $relationData['items'] ?? [];
                // hydrateCollection will also attach pivot data to each item if present
                return $this->hydrateCollection($relatedClass, $items);

            default:
                return null;
        }
    }

    /**
     * Hydrate single model from node data (standalone use).
     * Instances created are NOT persisted.
     *
     * @param string $modelClass
     * @param array $nodeData
     * @return Model
     */
    protected function hydrateSingleModel(string $modelClass, array $nodeData): Model
    {
        $instance = new $modelClass();

        $attributes = $this->mergePrimaryKeyFromMeta($nodeData);

        if (!empty($attributes)) {
            $instance->setRawAttributes($attributes, true);
        }

        // mark exists if PK present
        $pk = $instance->getKeyName();
        if (isset($attributes[$pk]) && $attributes[$pk] !== null) {
            $instance->exists = true;
        }

        // If pivot attributes are present at this node, attach to instance
        if (isset($nodeData['pivot']) && is_array($nodeData['pivot'])) {
            $this->attachPivotToModel($instance, $nodeData['pivot']);
        }

        // Recursively hydrate nested relations (safe)
        $nested = $nodeData['relations'] ?? [];
        foreach ($nested as $relName => $relNode) {
            // if relation method missing, skip
            if (!method_exists($instance, $relName)) {
                continue;
            }

            try {
                $relationObj = $instance->{$relName}();
                $relatedClass = get_class($relationObj->getRelated());
            } catch (\Throwable $e) {
                continue;
            }

            if (($relNode['type'] ?? 'single') === 'single') {
                $child = $this->hydrateSingleModel($relatedClass, $relNode['data'] ?? []);
                if ($child !== null) {
                    $instance->setRelation($relName, $child);
                }
            } else {
                $coll = $this->hydrateCollection($relatedClass, $relNode['items'] ?? []);
                if ($coll !== null) {
                    $instance->setRelation($relName, $coll);
                }
            }
        }

        return $instance;
    }

    /**
     * Hydrate collection from items data (standalone).
     * Instances are NOT persisted.
     *
     * @param string $modelClass
     * @param array $itemsData keyed by id => node
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function hydrateCollection(string $modelClass, array $itemsData)
    {
        $collection = new \Illuminate\Database\Eloquent\Collection();

        foreach ($itemsData as $id => $itemNode) {
            // itemNode might be a 'created' wrapper or full node; normalize:
            $node = $itemNode;
            if (isset($itemNode['created'])) {
                $node = $itemNode['created'];
            } elseif (isset($itemNode['data'])) {
                $node = $itemNode['data'];
            }

            $instance = $this->hydrateSingleModel($modelClass, $node);

            // If this item node included pivot data (typical for pivot collections), attach it.
            if (isset($node['pivot']) && is_array($node['pivot'])) {
                $this->attachPivotToModel($instance, $node['pivot']);
            }

            $collection->push($instance);
        }

        return $collection;
    }

    /**
     * Update a single related model (already loaded on template model) using canonical relation node.
     * This updates the provided $currentModel in-place (non-destructive), only changing attributes present in the relation node.
     *
     * @param Model $currentModel Existing model instance (from templateModel relation)
     * @param array|null $relationData Canonical relation node (type 'single' expected)
     * @param string $relatedClass
     * @param array $opts
     * @return Model Updated model (same instance)
     */
    protected function applySingleModelHydrationInPlace(Model $currentModel, ?array $relationData, string $relatedClass, array $opts): Model
    {
        if ($relationData === null) {
            // canonical says relation absent — preserve existing unless caller expected replace
            return $currentModel;
        }

        $node = $relationData['data'] ?? $relationData;

        // Only set attributes that are present in node -> attributes
        $attrs = $this->mergePrimaryKeyFromMeta($node);
        foreach ($attrs as $k => $v) {
            $currentModel->setAttribute($k, $v);
        }

        // Ensure exists flag if PK present in node
        $pk = $currentModel->getKeyName();
        if (isset($attrs[$pk]) && $attrs[$pk] !== null) {
            $currentModel->exists = true;
        }

        // If node contains pivot updates, attach / update pivot on the current model
        if (isset($node['pivot']) && is_array($node['pivot'])) {
            $this->attachPivotToModel($currentModel, $node['pivot']);
        }

        // Recursively apply nested relations if present (non-destructive)
        $nested = $node['relations'] ?? [];
        foreach ($nested as $relName => $relNode) {
            if (!method_exists($currentModel, $relName)) {
                continue;
            }

            $isLoaded = $currentModel->relationLoaded($relName);
            // prefer updating only loaded child relations
            if ($opts['hydrate_loaded_relations_only'] && !$isLoaded) {
                continue;
            }

            $currentChild = $isLoaded ? $currentModel->getRelation($relName) : null;
            try {
                $relationObj = $currentModel->{$relName}();
                $childClass = get_class($relationObj->getRelated());
            } catch (\Throwable $e) {
                continue;
            }

            if ($currentChild instanceof \Illuminate\Database\Eloquent\Collection) {
                $updated = $this->applyCollectionHydrationInPlace(
                    $currentChild,
                    $relNode,
                    $childClass,
                    $opts
                );
                $currentModel->setRelation($relName, $updated);
            } elseif ($currentChild instanceof Model) {
                $updated = $this->applySingleModelHydrationInPlace(
                    $currentChild,
                    $relNode,
                    $childClass,
                    $opts
                );
                $currentModel->setRelation($relName, $updated);
            } else {
                // If no current child and attach_unloaded_relations true, build and attach
                if ($opts['attach_unloaded_relations']) {
                    $built = $this->buildRelationFromCanonical($childClass, $relNode);
                    if ($built !== null) {
                        $currentModel->setRelation($relName, $built);
                    }
                }
            }
        }

        return $currentModel;
    }

    /**
     * Update collection relation in-place using canonical collection node.
     * Non-destructive: existing items not mentioned in the node remain untouched unless node explicitly lists removed ids.
     *
     * @param \Illuminate\Database\Eloquent\Collection $currentCollection
     * @param array|null $relationData canonical node (type 'collection' expected)
     * @param string $relatedClass
     * @param array $opts
     * @return \Illuminate\Database\Eloquent\Collection Updated collection (may be same instance)
     */
    protected function applyCollectionHydrationInPlace(\Illuminate\Database\Eloquent\Collection $currentCollection, ?array $relationData, string $relatedClass, array $opts)
    {
        // If relationData null -> no change requested; preserve existing collection
        if ($relationData === null) {
            return $currentCollection;
        }

        $itemsNode = $relationData['items'] ?? $relationData;

        // If itemsNode is associative keyed by id, we inspect keys
        // Build a map of existing items by primary key value for fast lookup
        $pkName = null;
        $existingById = [];

        // Attempt to discover pk name from first item or related class
        try {
            $temp = new $relatedClass();
            $pkName = $temp->getKeyName();
        } catch (\Throwable $e) {
            $pkName = 'id';
        }

        foreach ($currentCollection as $item) {
            $existingById[(string)$item->getAttribute($pkName)] = $item;
        }

        // Handle explicit 'removed' list in relationData (if present)
        if (isset($relationData['removed']) && is_array($relationData['removed'])) {
            foreach ($relationData['removed'] as $rid) {
                if (isset($existingById[(string)$rid])) {
                    // remove from collection (non-persistent)
                    $currentCollection = $currentCollection->reject(function ($it) use ($pkName, $rid) {
                        return (string)$it->getAttribute($pkName) === (string)$rid;
                    })->values();
                    unset($existingById[(string)$rid]);
                }
            }
        }

        // Handle 'added' items (with added_data if present) OR generic created nodes
        if (isset($relationData['added_data']) && is_array($relationData['added_data'])) {
            foreach ($relationData['added_data'] as $newId => $newNode) {
                // build model and push
                $node = $newNode;
                if (isset($newNode['created'])) {
                    $node = $newNode['created'];
                }
                $inst = $this->hydrateSingleModel($relatedClass, $node);

                // attach pivot if available
                if (isset($node['pivot']) && is_array($node['pivot'])) {
                    $this->attachPivotToModel($inst, $node['pivot']);
                }

                $currentCollection->push($inst);
                $existingById[(string)$inst->getAttribute($pkName)] = $inst;
            }
        } else {
            // Alternatively the canonical 'items' map may contain created/updated nodes as well
            foreach ($itemsNode as $id => $itemNode) {
                // If item exists in collection, update its attributes present in node
                $node = $itemNode;
                if (isset($itemNode['created'])) {
                    $node = $itemNode['created'];
                } elseif (isset($itemNode['data'])) {
                    $node = $itemNode['data'];
                }

                $idKey = (string)$id;
                if (isset($existingById[$idKey])) {
                    $existing = $existingById[$idKey];
                    $attrs = $this->mergePrimaryKeyFromMeta($node);
                    foreach ($attrs as $k => $v) {
                        $existing->setAttribute($k, $v);
                    }

                    // If node contains pivot data, attach/update pivot on existing item
                    if (isset($node['pivot']) && is_array($node['pivot'])) {
                        $this->attachPivotToModel($existing, $node['pivot']);
                    }

                    // recursively handle nested relations of the collection item if any
                    $nested = $node['relations'] ?? [];
                    foreach ($nested as $childRel => $childNode) {
                        if (!method_exists($existing, $childRel)) continue;
                        $childIsLoaded = $existing->relationLoaded($childRel);
                        $childCurrent = $childIsLoaded ? $existing->getRelation($childRel) : null;

                        try {
                            $relObj = $existing->{$childRel}();
                            $childClass = get_class($relObj->getRelated());
                        } catch (\Throwable $e) {
                            continue;
                        }

                        if ($childCurrent instanceof \Illuminate\Database\Eloquent\Collection) {
                            $updatedChildColl = $this->applyCollectionHydrationInPlace($childCurrent, $childNode, $childClass, $opts);
                            $existing->setRelation($childRel, $updatedChildColl);
                        } elseif ($childCurrent instanceof Model) {
                            $updatedChildModel = $this->applySingleModelHydrationInPlace($childCurrent, $childNode, $childClass, $opts);
                            $existing->setRelation($childRel, $updatedChildModel);
                        } else {
                            if ($opts['attach_unloaded_relations']) {
                                $built = $this->buildRelationFromCanonical($childClass, $childNode);
                                if ($built !== null) {
                                    $existing->setRelation($childRel, $built);
                                }
                            }
                        }
                    }
                } else {
                    // Item does not exist in current collection: treat as created -> append
                    $inst = $this->hydrateSingleModel($relatedClass, $node);
                    if (isset($node['pivot']) && is_array($node['pivot'])) {
                        $this->attachPivotToModel($inst, $node['pivot']);
                    }
                    $currentCollection->push($inst);
                    $existingById[$idKey] = $inst;
                }
            }
        }

        return $currentCollection->values();
    }

    /**
     * Attach a pivot-like object to a hydrated model instance.
     *
     * We use a lightweight Eloquent model instance to represent the pivot so that
     * consumers can access ->pivot->attributeName like a normal pivot object.
     *
     * @param Model $model
     * @param array $pivotAttributes
     * @return void
     */
    protected function attachPivotToModel(Model $model, array $pivotAttributes): void
    {
        // Create a pivot instance
        $pivot = Pivot::fromAttributes(
            $model,
            $pivotAttributes,
            $model->getTable(),
            true // exists = true
        );

        // Attach as a relation so ->pivot works and relationLoaded checks can behave
        $model->setRelation('pivot', $pivot);
    }

    /**
     * Merge primary key from __meta into attributes if not already present.
     *
     * @param array $nodeData
     * @return array Updated attributes
     */
    protected function mergePrimaryKeyFromMeta(array $nodeData): array
    {
        $attributes = $nodeData['attributes'] ?? [];
        
        if (isset($nodeData['__meta']['primary_key']) && isset($nodeData['__meta']['id'])) {
            $pk = $nodeData['__meta']['primary_key'];
            if (!isset($attributes[$pk]) || $attributes[$pk] === null) {
                $attributes[$pk] = $nodeData['__meta']['id'];
            }
        }
        
        return $attributes;
    }
}
