<?php

namespace SthiraLabs\VersionVault\Services;

use InvalidArgumentException;

/**
 * Normalizes the user-defined version tracking configuration (shorthand format)
 * into a structured, canonical array format.
 */
class ConfigNormalizer
{
    /**
     * The canonical structure for a node (root or relation).
     * @var array
     */
    protected const CANONICAL_NODE_STRUCTURE = [
        'attributes' => [],
        'relations' => [],
        'pivot' => [],
    ];

    /**
     * Aliases for standardizing canonical keys in explicit input.
     * @var array
     */
    protected const CANONICAL_KEY_ALIASES = [
        'realtions' => 'relations',
        'attrs' => 'attributes',
    ];

    /**
     * Converts a version tracking configuration (shorthand) into the canonical format.
     *
     * @param array $config The configuration array, which can contain attributes and relations.
     * @return array The fully normalized canonical configuration.
     * @throws InvalidArgumentException
     */
    public function normalize(array $config): array
    {
        // Start with the root canonical structure
        $canonical = [
            'attributes' => [],
            'relations' => [],
        ];

        foreach ($config as $key => $value) {
            // Case 1: Simple string value (Attribute or Shorthand Relation with no nesting)
            if (is_int($key)) {
                $entry = $value;

                if (!is_string($entry)) {
                    throw new InvalidArgumentException("Array entry at index {$key} must be a string.");
                }

                // If it's a simple attribute (no ':'), add to root attributes
                if (strpos($entry, ':') === false) {
                    $canonical['attributes'][] = $entry;
                    continue;
                }

                // If it's a shorthand relation (with ':')
                [$relationName, $canonicalNode] = $this->parseEntry($entry, []);
                $canonical['relations'][$relationName] = $canonicalNode;

            // Case 2: Key-Value pair (Relation with attributes/pivot AND nested configuration/boolean/canonical node)
            } elseif (is_string($key)) {
                
                // FIX: IDEMPOTENCY CHECK
                // If the input array is already in canonical root format, merge its contents directly.
                if (in_array($key, ['attributes', 'relations']) && is_array($value)) {
                    if ($key === 'attributes') {
                        $canonical['attributes'] = array_merge($canonical['attributes'], $value);
                    }
                    if ($key === 'relations') {
                        // Recursively normalize the relations array to ensure deep idempotency
                        $normalizedRelations = $this->normalize($value);
                        $canonical['relations'] = array_merge($canonical['relations'], $normalizedRelations['relations']);
                    }
                    continue;
                }
                // END FIX

                // Check if the value is ALREADY a canonical node (explicit input)
                if (is_array($value) && (isset($value['attributes']) || isset($value['relations']) || isset($value['pivot']) || isset($value['realtions']) || isset($value['attrs']))) {
                    $relationName = $key;

                    // 1. Standardize and normalize the keys first
                    $standardizedValue = $this->standardizeCanonicalKeys($value);

                    // 2. Extract the relations part that might contain un-normalized shorthand
                    $rawRelations = $standardizedValue['relations'] ?? [];
                    
                    // 3. Recursively normalize the raw relations using the main method.
                    $normalizedRelations = $this->normalize($rawRelations);

                    // 4. Prepare the final node structure by merging the standardized user's input with the defaults
                    $finalNode = array_merge(self::CANONICAL_NODE_STRUCTURE, $standardizedValue);
                    
                    // 5. Overwrite the relations with the recursively normalized version
                    $finalNode['relations'] = $normalizedRelations['relations'];

                    // 6. Place it in the root
                    $canonical['relations'][$relationName] = $finalNode;
                    
                    continue; // Skip the existing shorthand parsing logic
                }

                // Existing Logic: Process as Shorthand (key might be 'relation' or 'relation:fields')
                $entry = $key;

                // Handle the key which contains the relation name and its fields (shorthand)
                [$relationName, $canonicalNode] = $this->parseEntry($entry, $value);

                // Add the resulting canonical node to the root relations
                $canonical['relations'][$relationName] = $canonicalNode;
            }
        }

        return $canonical;
    }

    /**
     * Parses a single configuration entry (a string key/value) and returns the
     * relation name and its canonical node structure, handling recursion if needed.
     *
     * @param string $shorthand The relation shorthand (e.g., 'relation:attr1,pivot(...)').
     * @param array|bool $nestedConfig The value (nested config array or boolean).
     * @return array An array containing [relationName, canonicalNode].
     */
    protected function parseEntry(string $shorthand, array|bool $nestedConfig): array
    {
        $parts = explode(':', $shorthand, 2);
        $relationName = $parts[0];
        $fieldsString = $parts[1] ?? '*'; // Default to '*' if no fields specified

        // Initialize the canonical node for this relation
        $node = self::CANONICAL_NODE_STRUCTURE;

        // If the value is a boolean true (e.g., 'documents' => true),
        // we assume the relation is tracked without specific attribute rules defined here.
        if ($nestedConfig === true) {
            // Node remains with empty attributes, relations, and pivot as per the example.
            return [$relationName, $node];
        }

        // --- 1. Extract Attributes and Pivot Fields ---
        $fields = $this->extractFields($fieldsString);
        $node['attributes'] = $fields['attributes'];
        $node['pivot'] = $fields['pivot'];

        // --- 2. Handle Nested Relations (Recursion) ---
        if (is_array($nestedConfig) && !empty($nestedConfig)) {
            // Recursively normalize the nested configuration
            $nestedCanonical = $this->normalize($nestedConfig);

            // Merge the resulting relations into the current node's relations
            $node['relations'] = $nestedCanonical['relations'];
        }

        return [$relationName, $node];
    }

    /**
     * Extracts attribute names and pivot field names from a fields string.
     * Handles the 'pivot(...)' syntax robustly, regardless of internal commas.
     *
     * @param string $fieldsString The comma-separated string of fields.
     * @return array An array with keys 'attributes' and 'pivot'.
     */
    protected function extractFields(string $fieldsString): array
    {
        $attributes = [];
        $pivot = [];

        // 1. Handle Simple '*' case
        if ($fieldsString === '*') {
            return ['attributes' => ['*'], 'pivot' => []];
        }

        // 2. Extract Pivot fields using a global regex match
        // Searches for 'pivot(...)' and captures the content non-greedily.
        if (preg_match('/pivot\((.+?)\)/', $fieldsString, $matches)) {
            $pivotFieldsString = $matches[1]; // The content inside the parentheses
            
            // Split the pivot fields by comma and trim whitespace
            $pivot = array_map('trim', explode(',', $pivotFieldsString));

            // Remove the pivot section from the fieldsString, including surrounding commas/spaces
            $fieldsString = str_replace($matches[0], '', $fieldsString);
            $fieldsString = trim($fieldsString, ', '); // Clean up any resulting leading/trailing commas
        }

        // 3. Process remaining attributes (which are now guaranteed not to contain pivot blocks)
        $fieldsString = trim($fieldsString);

        if (!empty($fieldsString)) {
            // Split remaining attributes by comma
            $attributes = array_map('trim', explode(',', $fieldsString));
        }

        // 4. Filter and return
        return [
            'attributes' => array_filter($attributes),
            'pivot' => array_filter($pivot)
        ];
    }

    /**
     * Standardizes canonical keys (e.g., 'realtions' to 'relations') in an array.
     *
     * @param array $node The input array to standardize.
     * @return array The array with standardized keys.
     */
    protected function standardizeCanonicalKeys(array $node): array
    {
        $standardized = [];
        foreach ($node as $key => $value) {
            $key = strtolower($key);
            $newKey = self::CANONICAL_KEY_ALIASES[$key] ?? $key;
            $standardized[$newKey] = $value;
        }
        return $standardized;
    }
}