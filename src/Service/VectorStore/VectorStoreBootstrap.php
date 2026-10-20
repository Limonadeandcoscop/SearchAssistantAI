<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\VectorStore;

use Omeka\Settings\Settings;

/**
 * Creates and recreates the configured collection.
 *
 * The collection name comes from VectorStoreRegistry and the dimension from
 * settings, so callers (lazy bootstrap, dashboard clear-and-recreate) don't
 * duplicate the backend branching.
 */
class VectorStoreBootstrap
{
    public const DEFAULT_DIMENSION = 1024;
    public const DEFAULT_DISTANCE = 'Cosine';

    private VectorStoreRegistry $registry;

    private Settings $settings;

    public function __construct(VectorStoreRegistry $registry, Settings $settings)
    {
        $this->registry = $registry;
        $this->settings = $settings;
    }

    public function getCollectionName(): string
    {
        return $this->registry->getCollectionName();
    }

    public function getDimension(): int
    {
        return (int) $this->settings->get('search_assistant_embedding_dimension', self::DEFAULT_DIMENSION);
    }

    /**
     * Create the configured collection if it does not already exist.
     * Returns true if the collection existed or was created successfully.
     */
    public function ensureCollection(VectorStoreInterface $store): bool
    {
        $collection = $this->getCollectionName();
        if ($store->getCollectionInfo($collection) !== null) {
            return true;
        }
        return $store->createCollection($collection, $this->getDimension(), self::DEFAULT_DISTANCE);
    }

    /**
     * Drop and recreate the configured collection. Used by the admin
     * "Clear vector store" action before a full reindex.
     */
    public function recreateCollection(VectorStoreInterface $store): bool
    {
        $collection = $this->getCollectionName();
        $store->deleteCollection($collection);
        return $store->createCollection($collection, $this->getDimension(), self::DEFAULT_DISTANCE);
    }
}
