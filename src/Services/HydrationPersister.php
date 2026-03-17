<?php

namespace SthiraLabs\VersionVault\Services;

use Illuminate\Database\Eloquent\Model;

class HydrationPersister
{
    public function persist(
        Model $original,
        Model $hydrated,
        array $canonicalState,
        array $options = []
    ): void {
        $options = array_merge([
            'delete_missing' => true,
            'touch_parent' => false,
        ], $options);

        // 1. Root attributes
        $this->persistRoot($original, $hydrated);

        // 2. Relations (recursive)
        if (! empty($canonicalState['relations'])) {
            $this->persistRelationsFromSnapshot(
                $original,
                $hydrated,
                $canonicalState,
                $options
            );
        }
    }

    protected function persistRoot(Model $original, Model $hydrated): void
    {
        $original->fill($hydrated->getAttributes());
        $original->save();
    }

    protected function persistRelationsFromSnapshot(
        Model $original,
        Model $hydrated,
        array $node,
        array $options
    ): void {
        foreach ($node['relations'] ?? [] as $relation => $relationNode) {
            if (!method_exists($original, $relation)) {
                continue;
            }
            if ($relationNode === null) {
                continue;
            }

            $type = $relationNode['type'] ?? 'single';

            if ($type === 'single') {
                $this->persistSingleRelationFromSnapshot(
                    $original,
                    $hydrated,
                    $relation,
                    $relationNode,
                    $options
                );
                continue;
            }

            if ($type === 'collection') {
                $this->persistCollectionRelationFromSnapshot(
                    $original,
                    $hydrated,
                    $relation,
                    $relationNode,
                    $options
                );
                continue;
            }

            if ($type === 'pivot') {
                $this->persistPivotRelationFromSnapshot(
                    $original,
                    $hydrated,
                    $relation,
                    $relationNode,
                    $options
                );
            }
        }
    }

    protected function persistSingleRelationFromSnapshot(
        Model $original,
        Model $hydrated,
        string $relation,
        array $relationNode,
        array $options
    ): void {
        $relationInstance = $original->{$relation}();
        $related = $relationInstance->getRelated();
        $pk = $related->getKeyName();
        $current = $relationInstance->getResults();

        $dataNode = $relationNode['data'] ?? null;
        $target = $hydrated->getRelation($relation);
        $attrs = $dataNode ? $this->attributesFromNode($dataNode) : [];

        if (!$target && $dataNode) {
            $target = $this->buildModelFromAttributes($related, $attrs, $pk);
        }

        if (!$target) {
            if ($options['delete_missing'] && $current) {
                if ($relationInstance instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                    $relationInstance->dissociate();
                    $original->save();
                } else {
                    $current->delete();
                }
            }
            return;
        }

        if ($current) {
            $current->fill($target->getAttributes());
            $current->save();
        } else {
            if ($relationInstance instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                $relationInstance->associate($target);
                $original->save();
                $current = $relationInstance->getResults();
            } else {
                if (!empty($attrs)) {
                    $current = $this->upsertThroughRelation($relationInstance, $attrs, $pk);
                } else {
                    $relationInstance->save($target);
                    $current = $target;
                }
            }
        }

        if ($current && $dataNode && !empty($dataNode['relations'])) {
            $this->persistRelationsFromSnapshot(
                $current,
                $target,
                $dataNode,
                $options
            );
        }
    }

    protected function persistCollectionRelationFromSnapshot(
        Model $original,
        Model $hydrated,
        string $relation,
        array $relationNode,
        array $options
    ): void {
        $relationInstance = $original->{$relation}();
        $related = $relationInstance->getRelated();
        $pk = $related->getKeyName();

        $existing = $relationInstance->get()->keyBy(fn ($m) => (string) $m->getKey());
        $target = $hydrated->getRelation($relation);
        $targetById = $target?->keyBy(fn ($m) => (string) $m->getKey()) ?? collect();

        $itemsNode = $relationNode['items'] ?? [];

        foreach ($itemsNode as $id => $itemNode) {
            $idKey = (string) $id;
            $current = $existing->get($idKey);
            $desired = $targetById->get($idKey);
            $attrs = $this->attributesFromNode($itemNode);

            if (!$desired) {
                $desired = $this->buildModelFromAttributes($related, $attrs, $pk);
            }

            if ($current) {
                $current->fill($desired->getAttributes());
                $current->save();
                $this->persistRelationsFromSnapshot($current, $desired, $itemNode, $options);
                continue;
            }

            if (!empty($attrs)) {
                $created = $this->upsertThroughRelation($relationInstance, $attrs, $pk);
                $this->persistRelationsFromSnapshot($created, $desired, $itemNode, $options);
            } else {
                $relationInstance->save($desired);
                $this->persistRelationsFromSnapshot($desired, $desired, $itemNode, $options);
            }
        }

        if ($options['delete_missing']) {
            $idsToKeep = collect(array_keys($itemsNode))->map(fn ($id) => (string) $id);
            $existing->reject(fn ($model, $id) => $idsToKeep->contains($id))
                ->each(fn ($model) => $model->delete());
        }
    }

    protected function persistPivotRelationFromSnapshot(
        Model $original,
        Model $hydrated,
        string $relation,
        array $relationNode,
        array $options
    ): void {
        $relationInstance = $original->{$relation}();
        $itemsNode = $relationNode['items'] ?? [];

        $desiredIds = collect(array_keys($itemsNode))->map(fn ($id) => (string) $id)->all();
        $currentIds = $relationInstance->get()->modelKeys();
        $currentIds = array_map('strval', $currentIds);

        $toAttach = array_diff($desiredIds, $currentIds);
        $toDetach = array_diff($currentIds, $desiredIds);

        foreach ($toAttach as $id) {
            $pivot = $itemsNode[$id]['pivot'] ?? [];
            $relationInstance->attach($id, $pivot);
        }

        if ($options['delete_missing']) {
            foreach ($toDetach as $id) {
                $relationInstance->detach($id);
            }
        }

        foreach ($desiredIds as $id) {
            $pivot = $itemsNode[$id]['pivot'] ?? null;
            if (is_array($pivot) && !empty($pivot)) {
                $relationInstance->updateExistingPivot($id, $pivot);
            }
        }

        $target = $hydrated->getRelation($relation);
        $targetById = $target?->keyBy(fn ($m) => (string) $m->getKey()) ?? collect();
        $existing = $relationInstance->get()->keyBy(fn ($m) => (string) $m->getKey());

        foreach ($itemsNode as $id => $itemNode) {
            $idKey = (string) $id;
            $current = $existing->get($idKey);
            $desired = $targetById->get($idKey);

            if ($current && $desired) {
                $current->fill($desired->getAttributes());
                $current->save();
                $this->persistRelationsFromSnapshot($current, $desired, $itemNode, $options);
            }
        }
    }

    protected function attributesFromNode(array $node): array
    {
        $attributes = $node['attributes'] ?? [];

        if (isset($node['__meta']['primary_key']) && isset($node['__meta']['id'])) {
            $pk = $node['__meta']['primary_key'];
            if (!isset($attributes[$pk]) || $attributes[$pk] === null) {
                $attributes[$pk] = $node['__meta']['id'];
            }
        }

        return $attributes;
    }

    protected function buildModelFromAttributes(Model $related, array $attrs, string $pk): Model
    {
        $model = $related->newInstance($attrs);
        if (isset($attrs[$pk]) && $attrs[$pk] !== null) {
            $model->exists = $related->newQuery()->whereKey($attrs[$pk])->exists();
        }
        return $model;
    }

    protected function upsertThroughRelation($relationInstance, array $attrs, string $pk): Model
    {
        if (isset($attrs[$pk]) && $attrs[$pk] !== null) {
            return $relationInstance->updateOrCreate([$pk => $attrs[$pk]], $attrs);
        }

        return $relationInstance->create($attrs);
    }
}
