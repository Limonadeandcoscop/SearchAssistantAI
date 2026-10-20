<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Indexing;

use InvalidArgumentException;

/**
 * Transforms Omeka S resources into the three artefacts the indexer needs:
 *
 *   1. a flat metadata array (title, description, property terms → values),
 *   2. a composite text string optimised for embedding generation, and
 *   3. a structured payload array for vector-store storage.
 *
 * Accepts both API representations (`$resource->values()`, `->url()`, ...)
 * and Doctrine entities (`$resource->getValues()`, `->getId()`, ...), so
 * callers don't have to branch on resource flavour.
 *
 * Property filtering: if the indexer was configured with an allow-list of
 * property IDs, only those properties land in the output; otherwise every
 * value is included (backwards-compatible default).
 *
 * Extracted from VectorIndexService during the v1.0 → v1.1 refactor to
 * separate the "entity → payload" pure transformation from the orchestration
 * (batching, retries, vector-store dispatch) that used to surround it.
 */
class PayloadBuilder
{
    /** @var array<int, int> Allow-list of property IDs to include. Empty = include all. */
    private array $indexedPropertyIds;

    /**
     * @param array<int, int|string> $indexedPropertyIds Property IDs to include,
     *        or empty array to include all properties.
     */
    public function __construct(array $indexedPropertyIds = [])
    {
        $this->indexedPropertyIds = array_map('intval', $indexedPropertyIds);
    }

    /**
     * Update the property allow-list (used when the indexer's configuration
     * is refreshed from admin settings mid-request).
     *
     * @param array<int, int|string> $indexedPropertyIds
     */
    public function setIndexedPropertyIds(array $indexedPropertyIds): void
    {
        $this->indexedPropertyIds = array_map('intval', $indexedPropertyIds);
    }

    /**
     * Extract structured metadata (title, description, property terms → values,
     * item-set membership) from an Omeka S resource.
     *
     * @param mixed $resource
     * @return array<string, mixed>
     */
    public function extractMetadata($resource): array
    {
        $metadata = [];
        $metadata['title'] = $this->getEntityTitle($resource);
        $metadata['description'] = $this->getEntityDescription($resource);

        $filterProperties = !empty($this->indexedPropertyIds);

        if (method_exists($resource, 'values')) {
            // API representation
            foreach ($resource->values() as $propertyRepresentation) {
                $property = $propertyRepresentation->property();
                $propertyId = $property->id();
                $term = $property->term();

                if ($filterProperties && !in_array($propertyId, $this->indexedPropertyIds, true)) {
                    continue;
                }

                $metadata[$term] = array_map(
                    static fn ($value): string => (string) $value,
                    $propertyRepresentation->values()
                );
            }
        } else {
            // Doctrine entity — values come one-per-row, group by property term
            foreach ($resource->getValues() as $valueEntity) {
                $property = $valueEntity->getProperty();
                $propertyId = $property->getId();

                $vocabulary = $property->getVocabulary();
                $term = $vocabulary->getPrefix() . ':' . $property->getLocalName();

                if ($filterProperties && !in_array($propertyId, $this->indexedPropertyIds, true)) {
                    continue;
                }

                if (!isset($metadata[$term])) {
                    $metadata[$term] = [];
                }
                $metadata[$term][] = $valueEntity->getValue();
            }
        }

        // Item-set membership (relevant for Items; harmless for Media/Collections)
        $itemSets = [];
        if (method_exists($resource, 'itemSets')) {
            foreach ($resource->itemSets() as $itemSetRepresentation) {
                $itemSets[] = [
                    'id' => $itemSetRepresentation->id(),
                    'title' => $itemSetRepresentation->displayTitle(),
                    'url' => $itemSetRepresentation->url() ?? '',
                ];
            }
        } elseif (method_exists($resource, 'getItemSets')) {
            foreach ($resource->getItemSets() as $itemSetEntity) {
                $itemSetId = $itemSetEntity->getId();
                $itemSets[] = [
                    'id' => $itemSetId,
                    'title' => $this->getEntityTitle($itemSetEntity),
                    'url' => '/item-set/' . $itemSetId,
                ];
            }
        }
        if (!empty($itemSets)) {
            $metadata['item_sets'] = $itemSets;
        }

        return $metadata;
    }

    /**
     * Generate the composite text that gets sent to the embedding model.
     * Labels each field so the encoder has some semantic anchoring rather
     * than a bag-of-values soup.
     *
     * @param array<string, mixed> $metadata Output from extractMetadata()
     */
    public function generateCompositeText(array $metadata, string $resourceType): string
    {
        $parts = [];

        if (!empty($metadata['title'])) {
            $parts[] = 'Title: ' . $metadata['title'];
        }
        if (!empty($metadata['description'])) {
            $parts[] = 'Description: ' . $metadata['description'];
        }

        foreach ($metadata as $term => $values) {
            if (in_array($term, ['title', 'description', 'item_sets'], true)) {
                continue;
            }
            $value = is_array($values) ? implode(', ', $values) : $values;
            if ($value !== '') {
                $label = ucfirst(preg_replace('/.*:/', '', $term));
                $parts[] = $label . ': ' . $value;
            }
        }

        if ($resourceType !== '') {
            $parts[] = 'Resource Type: ' . $resourceType;
        }

        return implode("\n", $parts);
    }

    /**
     * Build the vector-store payload. Locale-agnostic URLs (no /s/:site-slug
     * prefix); the locale is injected at query time by the controller/query
     * processor based on the requesting site context.
     *
     * @param mixed $resource
     * @param array<string, mixed> $metadata Output from extractMetadata()
     * @return array<string, mixed>
     */
    public function buildPayload($resource, array $metadata, string $resourceType): array
    {
        $payload = [];
        $payload['id'] = $this->getEntityId($resource);
        $payload['type'] = $resourceType;
        $payload['title'] = $metadata['title'] ?? '';

        if (method_exists($resource, 'url')) {
            $payload['url'] = $resource->url() ?? '';
        } else {
            $resourceId = $this->getEntityId($resource);
            $urlPrefixByType = [
                'item' => '/item/',
                'media' => '/media/',
                'collection' => '/item-set/',
            ];
            $prefix = $urlPrefixByType[$resourceType] ?? null;
            $payload['url'] = $prefix === null ? '' : $prefix . $resourceId;
        }

        $payload['description'] = $metadata['description'] ?? '';

        $payload['resource_class'] = '';
        if (method_exists($resource, 'resourceClass') && $resource->resourceClass()) {
            $payload['resource_class'] = $resource->resourceClass()->term();
        }

        // Dynamic storage of all vocabulary properties under a dedicated key
        $payload['properties'] = [];
        foreach ($metadata as $term => $values) {
            if (in_array($term, ['title', 'description', 'item_sets'], true)) {
                continue;
            }
            $payload['properties'][$term] = is_array($values)
                ? implode(', ', $values)
                : $values;
        }

        // Backwards-compatible top-level aliases used by older consumers
        $payload['creator'] = $payload['properties']['dcterms:creator'] ?? '';
        $payload['date'] = $payload['properties']['dcterms:date'] ?? '';
        $payload['subject'] = $payload['properties']['dcterms:subject'] ?? '';

        if ($resourceType === 'item') {
            $payload['item_sets'] = $metadata['item_sets'] ?? [];
        }

        return $payload;
    }

    /**
     * Read an Omeka entity's ID regardless of whether it's an API
     * representation (id()) or a Doctrine entity (getId()).
     *
     * @param mixed $entity
     */
    public function getEntityId($entity): int
    {
        if (method_exists($entity, 'id')) {
            return (int) $entity->id();
        }
        if (method_exists($entity, 'getId')) {
            return (int) $entity->getId();
        }
        throw new InvalidArgumentException('Entity must expose id() or getId()');
    }

    /**
     * @param mixed $entity
     */
    public function getEntityTitle($entity): string
    {
        if (method_exists($entity, 'displayTitle')) {
            return (string) $entity->displayTitle();
        }
        if (method_exists($entity, 'getTitle')) {
            return (string) ($entity->getTitle() ?? 'Untitled');
        }
        return 'Untitled';
    }

    /**
     * @param mixed $entity
     */
    public function getEntityDescription($entity): string
    {
        if (method_exists($entity, 'displayDescription')) {
            return (string) $entity->displayDescription();
        }

        // For Doctrine entities, extract from dcterms:description
        if (method_exists($entity, 'getValues')) {
            foreach ($entity->getValues() as $value) {
                $property = $value->getProperty();
                if (!$property) {
                    continue;
                }

                if (method_exists($property, 'term')) {
                    $term = $property->term();
                } elseif (method_exists($property, 'getTerm')) {
                    $term = $property->getTerm();
                } else {
                    $vocabulary = $property->getVocabulary();
                    $term = $vocabulary->getPrefix() . ':' . $property->getLocalName();
                }

                if ($term === 'dcterms:description') {
                    return (string) $value->getValue();
                }
            }
        }

        return '';
    }
}
