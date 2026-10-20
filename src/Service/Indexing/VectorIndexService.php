<?php
declare(strict_types=1);
namespace SearchAssistantAI\Service\Indexing;

use Doctrine\ORM\EntityManager;
use Laminas\Log\LoggerInterface;
use RuntimeException;
use InvalidArgumentException;

use SearchAssistantAI\Service\Embedding\EmbeddingInterface;
use SearchAssistantAI\Service\Embedding\EmbeddingService;
use SearchAssistantAI\Service\Search\QueryProcessorService;
use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;
/**
 * Service implementation for indexing Omeka S resources into vector store.
 *
 * Transforms Omeka S resources (Items, Media, Collections) into vector embeddings
 * with rich metadata payloads for semantic search. Implements optimized batch processing
 * strategy for high-volume indexing (100,000+ items) with three-phase deferred approach:
 *
 * BATCH OPTIMIZATION STRATEGY:
 * - Collects 100 resources per batch using Doctrine iterator for memory efficiency
 * - Phase 1: Metadata extraction and composite text generation (100 items)
 * - Phase 2: Batch embedding generation in sub-batches of 10 (10 API calls for 100 texts)
 * - Phase 3: Bulk upsert to the vector store (single API call for 100 vectors)
 * - Result: 11 API calls per 100 items (10 embeddings + 1 vector-store upsert) vs 200 with individual processing
 *
 * MEMORY MANAGEMENT:
 * - Uses Doctrine iterate() for streaming query results without loading all entities
 * - Detaches entities after processing with EntityManager::detach()
 * - Clears EntityManager and runs gc_collect_cycles() after each batch
 * - Enables stable memory usage even for 100,000+ item repositories
 *
 * DEFERRED INDEXING:
 * - Separates metadata extraction from vector generation and storage
 * - Buffers all metadata/texts before calling embedding APIs
 * - Generates all embeddings before calling vector-store storage APIs
 * - Allows optimal batching of expensive API operations
 *
 * BULK API USAGE:
 * - EmbeddingService::embedBatch() for batch embedding generation (10 texts per call)
 * - VectorStoreInterface::bulkUpsert() for bulk vector storage (100 points per call)
 * - Minimizes network overhead and API rate limiting impact
 *
 * URL STORAGE ARCHITECTURE (Locale-Agnostic):
 * URLs are stored WITHOUT locale prefix (e.g., /item/123 instead of /s/fr/item/123).
 * The locale prefix is dynamically injected at query time based on the user's current
 * site context. This enables multi-locale support where users accessing from different
 * sites (e.g., /s/fr/, /s/co/) see URLs with the correct prefix.
 *
 * MIGRATION REQUIREMENT:
 * If upgrading from a version that stored URLs WITH locale prefix, existing indexed
 * data must be reindexed to update URLs to the new locale-agnostic format. Run:
 *
 *     docker-compose exec omeka-s php /var/www/html/modules/SearchAssistantAI/bin/reindex.php
 *
 * This reindexes all Items, Media, and Collections with the new URL format.
 * The QueryProcessorService handles legacy URLs gracefully by stripping existing
 * locale prefixes before adding the correct one for the current user context.
 */
class VectorIndexService implements VectorIndexInterface
{
    /**
     * @var EntityManager Doctrine ORM entity manager for database access
     */
    private $entityManager;

    /**
     * @var LoggerInterface PSR-3 logger for comprehensive logging
     */
    private $logger;

    /**
     * @var VectorStoreInterface Vector store service for vector storage and retrieval
     */
    private $vectorStore;

    /**
     * @var EmbeddingInterface Embedding service for text-to-vector conversion
     */
    private $embeddingService;

    /**
     * @var PayloadBuilder Resource → metadata/text/payload transformation
     */
    private $payloadBuilder;

    /**
     * @var BatchIndexer|null Bulk-API batch indexer; delegated to by
     *                        indexAll()/reindexAll(). Lazily created by
     *                        the factory; built on demand as a fallback
     *                        so test instantiation without the factory
     *                        still works.
     */
    private ?BatchIndexer $batchIndexer = null;

    /**
     * @var int Default batch size for bulk indexing operations
     */
    private $defaultBatchSize = 100;

    /**
     * @var string vector-store collection name for storing vectors
     */
    private $collectionName;

    /**
     * @var string Site slug for constructing public URLs (e.g., 'default', 'my-site')
     */
    private $siteSlug;

    /**
     * @var int Maximum number of retry attempts for indexing operations
     */
    private $maxRetries = 3;

    /**
     * @var list<int> Retry delays in seconds for exponential backoff (1s, 2s, 4s)
     */
    private $retryDelays = [1, 2, 4];

    /**
     * @var list<int> Property IDs to include in indexing (empty = include all)
     */
    private $indexedPropertyIds = [];

    /**
     * @var list<int> Collection IDs (ItemSet IDs) to include in indexing (empty = include all)
     */
    private $indexedCollectionIds = [];

    /**
     * @var \Omeka\Settings\Settings|null Settings service for configuration refresh
     */
    private $settings = null;

    /**
     * Constructor.
     *
     * @param EntityManager $entityManager Doctrine entity manager for Omeka S database access
     * @param LoggerInterface $logger Laminas logger for progress tracking and error logging
     * @param VectorStoreInterface $vectorStore Vector store client for vector storage operations
     * @param EmbeddingInterface $embeddingService Embedding service for text-to-vector conversion
     * @param string $collectionName Vector store collection name (default: 'omeka_resources')
     * @param string $siteSlug Site slug for constructing public URLs (default: '')
     * @param list<int> $indexedPropertyIds Property IDs to include in indexing (empty = include all)
     * @param list<int> $indexedCollectionIds Collection IDs (ItemSet IDs) to include in indexing (empty = include all)
     * @param \Omeka\Settings\Settings|null $settings Omeka Settings service for configuration refresh
     */
    public function __construct(
        EntityManager $entityManager,
        LoggerInterface $logger,
        VectorStoreInterface $vectorStore,
        EmbeddingInterface $embeddingService,
        string $collectionName = 'omeka_resources',
        string $siteSlug = '',
        array $indexedPropertyIds = [],
        array $indexedCollectionIds = [],
        $settings = null,
        ?PayloadBuilder $payloadBuilder = null,
        ?BatchIndexer $batchIndexer = null
    ) {
        $this->batchIndexer = $batchIndexer;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->vectorStore = $vectorStore;
        $this->embeddingService = $embeddingService;
        $this->collectionName = $collectionName;
        $this->siteSlug = $siteSlug;
        $this->indexedPropertyIds = $indexedPropertyIds;
        $this->indexedCollectionIds = $indexedCollectionIds;
        $this->settings = $settings;
        // Allow the factory to inject a shared PayloadBuilder, but default to
        // one scoped to this service's own property allow-list so the service
        // is still usable when instantiated directly in tests.
        $this->payloadBuilder = $payloadBuilder ?? new PayloadBuilder($indexedPropertyIds);

        // Debug logging for configuration values received by the service
        $this->logger->debug('VectorIndexService initialized with configuration', [
            'collection_name' => $this->collectionName,
            'site_slug' => $this->siteSlug,
            'indexed_property_ids' => $this->indexedPropertyIds,
            'indexed_property_ids_count' => count($this->indexedPropertyIds),
            'indexed_collection_ids' => $this->indexedCollectionIds,
            'indexed_collection_ids_count' => count($this->indexedCollectionIds),
            'settings_injected' => $this->settings !== null,
        ]);
    }

    /**
     * Update the indexing configuration at runtime.
     *
     * Allows updating the indexedPropertyIds and indexedCollectionIds after
     * the service has been instantiated. This is useful when the configuration
     * needs to be refreshed from the database (e.g., site settings) without
     * recreating the entire service.
     *
     * @param list<int> $indexedPropertyIds Property IDs to include in indexing (empty = include all)
     * @param list<int> $indexedCollectionIds Collection IDs (ItemSet IDs) to include in indexing (empty = include all)
     * @return void
     */
    public function setIndexingConfiguration(array $indexedPropertyIds, array $indexedCollectionIds): void
    {
        $this->indexedPropertyIds = $indexedPropertyIds;
        $this->indexedCollectionIds = $indexedCollectionIds;
        $this->payloadBuilder->setIndexedPropertyIds($indexedPropertyIds);
        if ($this->batchIndexer !== null) {
            $this->batchIndexer->setIndexedCollectionIds($indexedCollectionIds);
        }

        $this->logger->debug('VectorIndexService configuration updated', [
            'indexed_property_ids' => $this->indexedPropertyIds,
            'indexed_property_ids_count' => count($this->indexedPropertyIds),
            'indexed_collection_ids' => $this->indexedCollectionIds,
            'indexed_collection_ids_count' => count($this->indexedCollectionIds),
        ]);
    }

    /**
     * Set the Settings service for configuration refresh.
     *
     * Allows injecting the Settings service after construction to enable
     * the refreshConfiguration() method. This is useful when the service
     * is retrieved from the service manager and needs to refresh its
     * configuration before indexing operations.
     *
     * @param \Omeka\Settings\Settings $settings Omeka Settings service
     * @return void
     */
    public function setSettings($settings): void
    {
        $this->settings = $settings;
    }

    /**
     * Refresh the indexing configuration from Omeka Settings API.
     *
     * Reads the current values of search_assistant_indexed_properties and
     * search_assistant_indexed_collections from the Omeka Settings API and updates
     * the service's configuration. This ensures that any changes made to
     * the configuration via the admin interface are applied before the
     * next indexing operation.
     *
     * This method should be called before starting a reindex operation
     * or processing entity events to ensure the latest configuration is used.
     *
     * @return void
     * @throws RuntimeException If Settings service is not available
     */
    public function refreshConfiguration(): void
    {
        if ($this->settings === null) {
            throw new RuntimeException(
                'Cannot refresh configuration: Settings service not available. ' .
                'Call setSettings() first or inject Settings via constructor.'
            );
        }

        $this->logger->debug('VectorIndexService refreshing configuration from Omeka Settings');

        // Read indexed property IDs from module settings
        // This JSON array specifies which properties to include in indexing
        // Empty array [] means index all properties (backward compatible default)
        $indexedPropertiesJson = $this->settings->get('search_assistant_indexed_properties', '[]');
        $indexedPropertyIds = json_decode($indexedPropertiesJson, true);

        // Default to empty array if JSON decode fails or returns null
        if (!is_array($indexedPropertyIds)) {
            $indexedPropertyIds = [];
        }

        // Read indexed collection IDs from module settings
        // This JSON array specifies which collections (ItemSets) to include in indexing
        // Empty array [] means index items from all collections (backward compatible default)
        $indexedCollectionsJson = $this->settings->get('search_assistant_indexed_collections', '[]');
        $indexedCollectionIds = json_decode($indexedCollectionsJson, true);

        // Default to empty array if JSON decode fails or returns null
        if (!is_array($indexedCollectionIds)) {
            $indexedCollectionIds = [];
        }

        $this->logger->debug('VectorIndexService configuration refreshed from settings', [
            'search_assistant_indexed_properties_raw' => $indexedPropertiesJson,
            'search_assistant_indexed_collections_raw' => $indexedCollectionsJson,
            'indexed_property_ids' => $indexedPropertyIds,
            'indexed_property_ids_count' => count($indexedPropertyIds),
            'indexed_collection_ids' => $indexedCollectionIds,
            'indexed_collection_ids_count' => count($indexedCollectionIds),
        ]);

        // Update the configuration using the existing method
        $this->setIndexingConfiguration($indexedPropertyIds, $indexedCollectionIds);
    }

    /**
     * Execute a callable with retry logic for transient failures.
     *
     * Implements exponential backoff strategy with delays of 1s, 2s, 4s between retries.
     * Retries on RuntimeException (service failures), but not on InvalidArgumentException
     * (validation errors that won't be fixed by retrying).
     *
     * @param callable $operation The operation to execute
     * @param array<string, mixed> $context Context for error logging (e.g., resource ID, type)
     * @return mixed The result of the operation
     * @throws RuntimeException If all retry attempts fail
     * @throws InvalidArgumentException If validation fails (not retried)
     */
    private function executeWithRetry(callable $operation, array $context = [])
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                return $operation();
            } catch (InvalidArgumentException $e) {
                // Don't retry validation errors - they won't be fixed by retrying
                throw $e;
            } catch (RuntimeException $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < $this->maxRetries) {
                    $delay = $this->retryDelays[$attempt - 1];
                    $this->logger->warn('Indexing operation failed, retrying', array_merge($context, [
                        'attempt' => $attempt,
                        'max_retries' => $this->maxRetries,
                        'retry_delay' => $delay,
                        'error' => $e->getMessage()
                    ]));
                    sleep($delay);
                }
            } catch (\Exception $e) {
                // Unexpected errors - log and wrap in RuntimeException
                $this->logger->err('Unexpected error during indexing operation', array_merge($context, [
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e)
                ]));
                throw new RuntimeException('Unexpected error during indexing: ' . $e->getMessage(), 0, $e);
            }
        }

        // All retries exhausted
        $this->logger->err('Indexing operation failed after all retry attempts', array_merge($context, [
            'max_retries' => $this->maxRetries,
            'final_error' => $lastException->getMessage()
        ]));

        throw new RuntimeException(
            "Failed to complete indexing operation after {$this->maxRetries} attempts: " . $lastException->getMessage(),
            0,
            $lastException
        );
    }

    /**
     * Index a single Item resource into the vector store.
     *
     * Extracts metadata, generates composite text optimized for embeddings,
     * converts text to vector via embeddings API, and stores in the vector store with
     * complete payload including ID, type, title, URL, and all properties.
     * Implements retry logic with exponential backoff for transient failures.
     *
     * @param mixed $item Omeka S Item entity to index
     * @return bool True if item indexed successfully, false otherwise
     * @throws \InvalidArgumentException If item is invalid or missing required fields
     * @throws \RuntimeException If embeddings API or vector-store storage fails after retries
     */
    public function indexItem($item): bool
    {
        // Validate item is not null
        if ($item === null) {
            throw new InvalidArgumentException('Item cannot be null');
        }

        $itemId = $this->payloadBuilder->getEntityId($item);

        // Check collection filtering - skip items not in indexed collections
        if (!$this->isItemInIndexedCollections($item)) {
            $this->logger->debug(sprintf(
                '[VectorIndex] Item %d skipped: not in indexed collections (configured: %s)',
                $itemId,
                implode(',', $this->indexedCollectionIds)
            ));
            return false;
        }

        $itemTitle = null;

        // Wrap entire indexing operation in retry logic
        return $this->executeWithRetry(function () use ($item, $itemId, &$itemTitle) {
            try {
                // Extract metadata from item
                $metadata = $this->payloadBuilder->extractMetadata($item);
                $itemTitle = $metadata['title'] ?? 'Untitled';

                // Log collection membership (item_sets) if present
                $itemSetsCount = !empty($metadata['item_sets']) ? count($metadata['item_sets']) : 0;
                $itemSetIds = !empty($metadata['item_sets']) ? array_column($metadata['item_sets'], 'id') : [];

                // Generate composite text for embedding
                $compositeText = $this->payloadBuilder->generateCompositeText($metadata, 'item');

                // Validate composite text is not empty
                if (empty(trim($compositeText))) {
                    throw new InvalidArgumentException('Generated composite text is empty for item ID ' . $itemId);
                }

                // Generate embedding vector (with retry logic in EmbeddingService)
                try {
                    $vector = $this->embeddingService->embed($compositeText);
                } catch (RuntimeException $e) {
                    $this->logger->err('Embedding service failed for item', [
                        'item_id' => $itemId,
                        'title' => $itemTitle,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }

                // Build vector-store payload
                $payload = $this->payloadBuilder->buildPayload($item, $metadata, 'item');

                // Create point structure for the vector store
                $point = [
                    'id' => $itemId,
                    'vector' => $vector,
                    'payload' => $payload
                ];

                // Store in the vector store (retry logic lives on the backend)
                try {
                    $result = $this->vectorStore->upsertPoint($this->collectionName, $point);

                    if (!$result) {
                        throw new RuntimeException('Vector-store upsert operation returned false');
                    }

                    $this->logger->debug('Successfully indexed item ' . $itemId);

                    return true;
                } catch (RuntimeException $e) {
                    $this->logger->err('vector-store storage failed for item', [
                        'item_id' => $itemId,
                        'title' => $itemTitle,
                        'collection' => $this->collectionName,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            } catch (InvalidArgumentException $e) {
                // Log validation errors with full context
                $this->logger->err('Validation failed for item', [
                    'item_id' => $itemId,
                    'title' => $itemTitle,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }, ['resource_type' => 'item', 'resource_id' => $itemId]);
    }

    /**
     * Index a single Media resource into the vector store.
     *
     * Handles media-specific metadata extraction including parent item relationships,
     * generates composite text, and stores vector with media-specific payload.
     * Implements retry logic with exponential backoff for transient failures.
     *
     * @param mixed $media Omeka S Media entity to index
     * @return bool True if media indexed successfully, false otherwise
     * @throws \InvalidArgumentException If media is invalid or missing required fields
     * @throws \RuntimeException If embeddings API or vector-store storage fails after retries
     */
    public function indexMedia($media): bool
    {
        // Validate media is not null
        if ($media === null) {
            throw new InvalidArgumentException('Media cannot be null');
        }

        $mediaId = $this->payloadBuilder->getEntityId($media);

        // Check collection filtering via parent item - skip media whose parent item
        // is not in an indexed collection
        if (!empty($this->indexedCollectionIds)) {
            $parentItem = null;
            if (method_exists($media, 'item') && $media->item()) {
                $parentItem = $media->item();
            } elseif (method_exists($media, 'getItem') && $media->getItem()) {
                $parentItem = $media->getItem();
            }

            if ($parentItem && !$this->isItemInIndexedCollections($parentItem)) {
                $this->logger->debug(sprintf(
                    '[VectorIndex] Media %d skipped: parent item not in indexed collections',
                    $mediaId
                ));
                return false;
            }
        }

        $mediaTitle = null;

        // Wrap entire indexing operation in retry logic
        return $this->executeWithRetry(function () use ($media, $mediaId, &$mediaTitle) {
            try {
                // Extract metadata from media
                $metadata = $this->payloadBuilder->extractMetadata($media);
                $mediaTitle = $metadata['title'] ?? 'Untitled';

                // Add parent item relationship if available
                if (method_exists($media, 'item') && $media->item()) {
                    $parentItem = $media->item();
                    $metadata['parent_item_id'] = $this->payloadBuilder->getEntityId($parentItem);
                    $metadata['parent_item_title'] = $this->payloadBuilder->getEntityTitle($parentItem);
                }

                // Add media-specific metadata
                if (method_exists($media, 'mediaType') && $media->mediaType()) {
                    $metadata['media_type'] = $media->mediaType();
                }

                if (method_exists($media, 'source') && $media->source()) {
                    $metadata['media_source'] = $media->source();
                }

                // Generate composite text for embedding
                $compositeText = $this->payloadBuilder->generateCompositeText($metadata, 'media');

                // Validate composite text is not empty
                if (empty(trim($compositeText))) {
                    throw new InvalidArgumentException('Generated composite text is empty for media ID ' . $mediaId);
                }

                // Generate embedding vector (with retry logic in EmbeddingService)
                try {
                    $vector = $this->embeddingService->embed($compositeText);
                } catch (RuntimeException $e) {
                    $this->logger->err('Embedding service failed for media', [
                        'media_id' => $mediaId,
                        'title' => $mediaTitle,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }

                // Build vector-store payload with media-specific fields
                $payload = $this->payloadBuilder->buildPayload($media, $metadata, 'media');

                // Add media-specific fields to payload
                if (!empty($metadata['parent_item_id'])) {
                    $payload['parent_item_id'] = $metadata['parent_item_id'];
                    $payload['parent_item_title'] = $metadata['parent_item_title'] ?? '';
                }

                if (!empty($metadata['media_type'])) {
                    $payload['media_type'] = $metadata['media_type'];
                }

                if (!empty($metadata['media_source'])) {
                    $payload['media_source'] = $metadata['media_source'];
                }

                // Create point structure for the vector store
                $point = [
                    'id' => $mediaId,
                    'vector' => $vector,
                    'payload' => $payload
                ];

                // Store in the vector store (retry logic lives on the backend)
                try {
                    $result = $this->vectorStore->upsertPoint($this->collectionName, $point);

                    if (!$result) {
                        throw new RuntimeException('Vector-store upsert operation returned false');
                    }

                    $this->logger->debug('Successfully indexed media ' . $mediaId);

                    return true;
                } catch (RuntimeException $e) {
                    $this->logger->err('vector-store storage failed for media', [
                        'media_id' => $mediaId,
                        'title' => $mediaTitle,
                        'collection' => $this->collectionName,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            } catch (InvalidArgumentException $e) {
                // Log validation errors with full context
                $this->logger->err('Validation failed for media', [
                    'media_id' => $mediaId,
                    'title' => $mediaTitle,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }, ['resource_type' => 'media', 'resource_id' => $mediaId]);
    }

    /**
     * Index a single Collection (ItemSet) resource into the vector store.
     *
     * Extracts collection metadata including items count and relationships,
     * handles empty collections gracefully, and stores with collection-specific payload.
     * Implements retry logic with exponential backoff for transient failures.
     *
     * @param mixed $collection Omeka S ItemSet entity to index
     * @return bool True if collection indexed successfully, false otherwise
     * @throws \InvalidArgumentException If collection is invalid or missing required fields
     * @throws \RuntimeException If embeddings API or vector-store storage fails after retries
     */
    public function indexCollection($collection): bool
    {
        // Validate collection is not null
        if ($collection === null) {
            throw new InvalidArgumentException('Collection cannot be null');
        }

        $collectionId = $this->payloadBuilder->getEntityId($collection);

        // Check collection filtering - only index selected collections
        if (!empty($this->indexedCollectionIds) && !in_array($collectionId, $this->indexedCollectionIds)) {
            $this->logger->debug(sprintf(
                '[VectorIndex] Collection %d skipped: not in indexed collections',
                $collectionId
            ));
            return false;
        }

        $collectionTitle = null;

        // Wrap entire indexing operation in retry logic
        return $this->executeWithRetry(function () use ($collection, $collectionId, &$collectionTitle) {
            try {
                // Extract metadata from collection
                $metadata = $this->payloadBuilder->extractMetadata($collection);
                $collectionTitle = $metadata['title'] ?? 'Untitled';

                // Add collection-specific metadata (items count)
                if (method_exists($collection, 'items') && $collection->items()) {
                    $metadata['items_count'] = count($collection->items());
                } else {
                    // Handle empty collections gracefully
                    $metadata['items_count'] = 0;
                }

                // Generate composite text for embedding
                $compositeText = $this->payloadBuilder->generateCompositeText($metadata, 'collection');

                // Validate composite text is not empty
                if (empty(trim($compositeText))) {
                    throw new InvalidArgumentException('Generated composite text is empty for collection ID ' . $collectionId);
                }

                // Generate embedding vector (with retry logic in EmbeddingService)
                try {
                    $vector = $this->embeddingService->embed($compositeText);
                } catch (RuntimeException $e) {
                    $this->logger->err('Embedding service failed for collection', [
                        'collection_id' => $collectionId,
                        'title' => $collectionTitle,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }

                // Build vector-store payload with collection-specific fields
                $payload = $this->payloadBuilder->buildPayload($collection, $metadata, 'collection');

                // Add collection-specific fields to payload
                $payload['items_count'] = $metadata['items_count'];

                // Create point structure for the vector store
                $point = [
                    'id' => $collectionId,
                    'vector' => $vector,
                    'payload' => $payload
                ];

                // Store in the vector store (retry logic lives on the backend)
                try {
                    $result = $this->vectorStore->upsertPoint($this->collectionName, $point);

                    if (!$result) {
                        throw new RuntimeException('Vector-store upsert operation returned false');
                    }

                    $this->logger->debug('Successfully indexed collection', [
                        'collection_id' => $collectionId,
                        'title' => $collectionTitle,
                        'items_count' => $metadata['items_count'],
                        'collection' => $this->collectionName
                    ]);

                    return true;
                } catch (RuntimeException $e) {
                    $this->logger->err('vector-store storage failed for collection', [
                        'collection_id' => $collectionId,
                        'title' => $collectionTitle,
                        'collection' => $this->collectionName,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            } catch (InvalidArgumentException $e) {
                // Log validation errors with full context
                $this->logger->err('Validation failed for collection', [
                    'collection_id' => $collectionId,
                    'title' => $collectionTitle,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }, ['resource_type' => 'collection', 'resource_id' => $collectionId]);
    }

    /**
     * Delete a vector point from the vector store collection.
     *
     * Removes the specified point ID from the configured vector-store collection.
     * Implements idempotent deletion - if point doesn't exist (404), operation
     * is treated as success to handle race conditions where entities are deleted
     * before vector indexing completes.
     *
     * @param int $pointId Point ID to delete (corresponds to Omeka entity ID)
     * @return bool True if deletion succeeded or point didn't exist, false otherwise
     * @throws RuntimeException If vector-store API returns server error
     */
    public function deleteVector(int $pointId): bool
    {
        // Validate point ID
        if ($pointId <= 0) {
            throw new InvalidArgumentException('Point ID must be positive integer');
        }


        try {
            $result = $this->vectorStore->deletePoint($this->collectionName, $pointId);

            if (!$result) {
                $this->logger->warn('VectorIndexService: Vector deletion returned false (point may not exist)', [
                    'point_id' => $pointId,
                    'collection' => $this->collectionName
                ]);
            }

            $this->logger->info('VectorIndexService: Vector point deleted successfully', [
                'point_id' => $pointId,
                'collection' => $this->collectionName
            ]);

            return $result;

        } catch (\Exception $e) {
            // Check if error is "not found" (404) - treat as success for idempotency
            if (strpos($e->getMessage(), '404') !== false ||
                strpos($e->getMessage(), 'not found') !== false ||
                strpos($e->getMessage(), 'Not found') !== false) {

                $this->logger->info('VectorIndexService: Vector point not found (already deleted or never indexed)', [
                    'point_id' => $pointId,
                    'collection' => $this->collectionName
                ]);

                return true;
            }

            $this->logger->err('VectorIndexService: Failed to delete vector point', [
                'point_id' => $pointId,
                'collection' => $this->collectionName,
                'error' => $e->getMessage()
            ]);

            throw new RuntimeException(
                "Failed to delete vector point {$pointId} from collection '{$this->collectionName}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Index every resource of a given type (default: Items) through the
     * bulk-API batch indexer. See BatchIndexer::indexAll for the full
     * contract — this method is kept on the facade so existing callers
     * (VectorIndexJob, the CLI command, tests) don't need to be rewritten.
     */
    public function indexAll(?string $resourceType = null, int $batchSize = 100): void
    {
        $this->batchIndexer()->indexAll($resourceType, $batchSize);
    }

    /**
     * Reindex every resource type sequentially (Items → Media → ItemSets).
     * Delegates to BatchIndexer::reindexAll.
     */
    public function reindexAll(int $batchSize = 100): void
    {
        $this->batchIndexer()->reindexAll($batchSize);
    }

    /**
     * Return the BatchIndexer injected by the factory, or lazily construct
     * one from the current dependencies so direct instantiation (tests,
     * ad-hoc scripts) still works without the DI wiring.
     */
    private function batchIndexer(): BatchIndexer
    {
        if ($this->batchIndexer === null) {
            $this->batchIndexer = new BatchIndexer(
                $this->entityManager,
                $this->logger,
                $this->vectorStore,
                $this->embeddingService,
                $this->payloadBuilder,
                $this->collectionName,
                $this->indexedCollectionIds,
                $this->defaultBatchSize
            );
        }
        return $this->batchIndexer;
    }

    /**
     * Check if an item belongs to at least one of the indexed collections.
     *
     * If indexedCollectionIds is empty, returns true (include all items).
     * Otherwise, checks if the item's ItemSets include at least one of the indexed collection IDs.
     *
     * @param mixed $item Omeka S Item entity (API representation or Doctrine entity)
     * @return bool True if item should be indexed, false if it should be skipped
     */
    private function isItemInIndexedCollections($item): bool
    {
        // If no collection filter is configured, include all items
        if (empty($this->indexedCollectionIds)) {
            return true;
        }

        // Get item's collection IDs
        $itemCollectionIds = [];

        if (method_exists($item, 'itemSets')) {
            // API representation - itemSets() returns ItemSetRepresentation objects
            foreach ($item->itemSets() as $itemSetRepresentation) {
                $itemCollectionIds[] = $itemSetRepresentation->id();
            }
        } elseif (method_exists($item, 'getItemSets')) {
            // Doctrine entity - getItemSets() returns ItemSet entities
            foreach ($item->getItemSets() as $itemSetEntity) {
                $itemCollectionIds[] = $itemSetEntity->getId();
            }
        }

        // Check if any of the item's collections match the indexed collection IDs
        $matchingCollections = array_intersect($itemCollectionIds, $this->indexedCollectionIds);
        return !empty($matchingCollections);
    }
}
