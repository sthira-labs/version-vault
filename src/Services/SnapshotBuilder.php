<?php

namespace SthiraLabs\VersionVault\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;

/**
 * SnapshotBuilder - Captures complete model state in universal node format.
 * 
 * Creates a recursive snapshot containing:
 * - Model attributes
 * - Single relations (belongsTo, hasOne, morphOne)
 * - Collection relations (hasMany, morphMany)
 * - Pivot relations (belongsToMany) with pivot data
 * - Nested relations (recursive)
 * 
 * Output format follows the universal node structure for consistency.
 */
class SnapshotBuilder
{
    protected const DATE_CAST_TYPES = [
        'date',
        'datetime',
        'immutable_date',
        'immutable_datetime',
        'custom_datetime',
        'immutable_custom_datetime',
    ];

    protected ConfigNormalizer $normalizer;

    public function __construct(ConfigNormalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    /**
     * Build complete snapshot for a model.
     *
     * @param Model $model Model to snapshot
     * @param array|null $config Configuration (null = load from model)
     * @return array Universal node snapshot
     */
    public function buildSnapshot(Model $model, ?array $config = null): array
    {
        return $this->buildNode($model, $this->resolveConfig($model, $config));
    }

    /**
     * Build a single node (recursive).
     *
     * @param Model $model Model to snapshot
     * @param array $config Node configuration
     * @return array Node snapshot
     */
    protected function buildNode(Model $model, array $config): array
    {
        $attributeConfig = $config['attributes'] ?? [];
        $attributeConfig = $this->augmentAttributesForReferenceRelations(
            $model,
            $config['relations'] ?? [],
            $attributeConfig
        );

        $node = [
            '__meta' => $this->extractMeta($model),
            'attributes' => $this->extractAttributes(
                $model,
                $attributeConfig
            ),
            'relations' => []
        ];

        // Process each configured relation
        foreach ($config['relations'] ?? [] as $relationName => $relationConfig) {
            $relationData = $this->extractRelation($model, $relationName, $relationConfig);

            if ($relationData !== null) {
                $node['relations'][$relationName] = $relationData;
            }
        }

        // Remove empty relations to keep JSON minimal
        if (empty($node['relations'])) {
            unset($node['relations']);
        }

        return $node;
    }

    /**
     * Extract meta from the model.
     *
     * @param Model $model
     * @return array
     */
    protected function extractMeta(Model $model): array
    {
        return [
            'alias' => Relation::getMorphAlias($model::class),
            'table' => $model->getTable(),
            'primary_key' => $model->getKeyName(),
            'id' => $model->getKey(),
        ];
    }

    /**
     * Extract model attributes based on configuration.
     *
     * @param Model $model Source model
     * @param array $attributeConfig Attribute configuration
     * @return array Attributes map
     */
    protected function extractAttributes(Model $model, array $attributeConfig): array
    {
        $rawAttributes = $model->getAttributes();

        // Handle wildcard
        if (in_array('*', $attributeConfig, true)) {
            $filtered = Arr::except(
                $rawAttributes,
                [
                    $model->getKeyName(),
                    'created_at',
                    'updated_at',
                    'deleted_at',
                ]
            );
            return $this->mapAttributeValues($model, $filtered);
        }

        // Extract specific attributes
        $attributes = [];
        foreach ($attributeConfig as $key) {
            if (array_key_exists($key, $rawAttributes)) {
                $attributes[$key] = $this->valueForAttribute($model, $key, $rawAttributes[$key]);
            } elseif ($model->offsetExists($key)) {
                // Fallback for non-raw attributes/accessors
                $attributes[$key] = $model->getAttribute($key);
            }
        }

        return $attributes;
    }

    protected function shouldUseRawForAttribute(Model $model, string $key): bool
    {
        $casts = $model->getCasts();
        if (isset($casts[$key])) {
            $cast = $casts[$key];
            if (is_string($cast)) {
                $castType = strtolower(explode(':', $cast, 2)[0]);
                if (in_array($castType, self::DATE_CAST_TYPES, true)) {
                    return true;
                }
            }
        }

        if (method_exists($model, 'getDates') && in_array($key, $model->getDates(), true)) {
            return true;
        }

        return false;
    }

    /**
     * Extract pivot attributes based on configuration.
     *
     * @param Model $pivot
     * @param array $allowed
     * @return array
     */
    protected function extractPivotAttributes(
        Model $pivot,
        array $allowed
    ): array {
        if (in_array('*', $allowed, true)) {
            return Arr::except(
                $pivot->getAttributes(),
                [$pivot->getKeyName()]
            );
        }

        return Arr::only($pivot->getAttributes(), $allowed);
    }

    /**
     * Extract and format a relation based on its type.
     *
     * @param Model $model Parent model
     * @param string $relationName Relation name
     * @param array $relationConfig Relation configuration
     * @return array|null Formatted relation data
     */
    protected function extractRelation(
        Model $model, 
        string $relationName, 
        array $relationConfig
    ): ?array {
        if ($this->getRelationMode($relationConfig) === 'reference') {
            return null;
        }

        // Always reload relation to ensure fresh state from DB
        try {
            $model->load($relationName);
        } catch (\Throwable $e) {
            // Relation doesn't exist or failed to load
            return null;
        }

        $related = $model->getRelation($relationName);

        // Null relation
        if ($related === null) {
            return null;
        }

        // Determine relation type and format accordingly
        $relationInstance = $model->{$relationName}();

        // BelongsToMany (pivot relation)
        if ($relationInstance instanceof BelongsToMany) {
            return $this->formatPivotRelation($related, $relationConfig);
        }

        // Collection (hasMany, morphMany, etc.)
        if ($related instanceof Collection) {
            return $this->formatCollectionRelation($related, $relationConfig);
        }

        // Single model (belongsTo, hasOne, morphOne)
        if ($related instanceof Model) {
            return $this->formatSingleRelation($related, $relationConfig);
        }

        return null;
    }

    /**
     * Format single model relation (belongsTo, hasOne, morphOne).
     *
     * @param Model $model Related model
     * @param array $config Relation configuration
     * @return array Formatted relation
     */
    protected function formatSingleRelation(Model $model, array $config): array
    {
        return [
            'type' => 'single',
            'data' => $this->buildNode($model, $config)
        ];
    }

    /**
     * Format collection relation (hasMany, morphMany).
     *
     * @param Collection $collection Related models
     * @param array $config Relation configuration
     * @return array Formatted relation
     */
    protected function formatCollectionRelation(Collection $collection, array $config): array
    {
        $items = [];

        foreach ($collection as $model) {
            $key = $model->getKey();
            $items[$key] = $this->buildNode($model, $config);
        }

        return [
            'type' => 'collection',
            'items' => $items
        ];
    }

    /**
     * Format pivot relation (belongsToMany).
     *
     * @param Collection $collection Related models with pivot
     * @param array $config Relation configuration
     * @return array Formatted relation
     */
    protected function formatPivotRelation(Collection $collection, array $config): array
    {
        $items = [];
        $pivotAttributes = $config['pivot'] ?? [];

        foreach ($collection as $model) {
            $key = $model->getKey();
            $itemNode = $this->buildNode($model, $config);

            // Add pivot data if configured and available
            if (!empty($pivotAttributes) && $model->pivot) {
                $itemNode['pivot'] = $this->extractPivotAttributes(
                    $model->pivot,
                    $pivotAttributes
                );
            }

            $items[$key] = $itemNode;
        }

        return [
            'type' => 'pivot',
            'items' => $items
        ];
    }

    protected function resolveConfig(Model $model, ?array $config): array
    {
        if ($config !== null) {
            return $config;
        }

        $rawConfig = method_exists($model, 'versioningConfig')
            ? $model->versioningConfig()
            : [];

        return $this->normalizer->normalize($rawConfig);
    }

    protected function getRelationMode(array $relationConfig): string
    {
        $mode = $relationConfig['mode'] ?? null;
        if (is_string($mode) && $mode !== '') {
            return $mode;
        }

        $hasAttributes = !empty($relationConfig['attributes'] ?? []);
        $hasRelations = !empty($relationConfig['relations'] ?? []);
        $hasPivot = !empty($relationConfig['pivot'] ?? []);

        return ($hasAttributes || $hasRelations || $hasPivot) ? 'snapshot' : 'reference';
    }

    protected function augmentAttributesForReferenceRelations(
        Model $model,
        array $relationsConfig,
        array $attributeConfig
    ): array {
        if (in_array('*', $attributeConfig, true)) {
            return $attributeConfig;
        }

        foreach ($relationsConfig as $relationName => $relationConfig) {
            if ($this->getRelationMode($relationConfig) !== 'reference') {
                continue;
            }

            if (!method_exists($model, $relationName)) {
                continue;
            }

            try {
                $relationInstance = $model->{$relationName}();
            } catch (\Throwable $e) {
                continue;
            }

            if ($relationInstance instanceof MorphTo) {
                $fk = $relationInstance->getForeignKeyName() ?: "{$relationName}_id";
                $type = $relationInstance->getMorphType() ?: "{$relationName}_type";
                if (!in_array($fk, $attributeConfig, true)) {
                    $attributeConfig[] = $fk;
                }
                if (!in_array($type, $attributeConfig, true)) {
                    $attributeConfig[] = $type;
                }
            } elseif ($relationInstance instanceof BelongsTo) {
                $fk = $relationInstance->getForeignKeyName();
                if (!in_array($fk, $attributeConfig, true)) {
                    $attributeConfig[] = $fk;
                }
            }
        }

        return $attributeConfig;
    }

    protected function mapAttributeValues(Model $model, array $attributes): array
    {
        $mapped = [];
        foreach ($attributes as $key => $value) {
            $mapped[$key] = $this->valueForAttribute($model, (string) $key, $value);
        }
        return $mapped;
    }

    protected function valueForAttribute(Model $model, string $key, mixed $rawValue): mixed
    {
        return $this->shouldUseRawForAttribute($model, $key)
            ? $rawValue
            : $model->getAttribute($key);
    }
}
