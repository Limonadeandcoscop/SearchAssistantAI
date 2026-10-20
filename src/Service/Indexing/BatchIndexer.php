<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Indexing;

use SearchAssistantAI\Service\Embedding\EmbeddingInterface;
use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use Laminas\Log\LoggerInterface;
use RuntimeException;

/**
 * Throughput-oriented batch indexer for large Omeka S collections.
 *
 * Extracted from VectorIndexService during the v1.0 → v1.1 refactor so
 * `indexAll()`, `processBatch()`, and `reindexAll()` live alongside their
 * bulk-API concerns instead of mixing with single-resource indexing and
 * retry glue. Single-item indexing stays on VectorIndexService.
 *
 * Batching strategy (per 100-item batch):
 *   1. Stream resources out of Doctrine with iterate() + detach() to keep
 *      memory bounded on 100k+ collections.
 *   2. Generate embeddings in sub-batches of 10 via the embeddings bulk
 *      API (10 calls per batch instead of 100).
 *   3. Bulk-upsert all 100 vectors to the vector store in a single call.
 *   4. Clear the EntityManager + explicit gc_collect_cycles() between
 *      batches to prevent heap growth.
 *
 * Compared to per-item indexing this cuts total API calls from 200 to 11
 * per 100 items (~94.5% reduction) and keeps peak memory roughly flat
 * regardless of total collection size.
 */
class BatchIndexer
{
    private const EMBEDDING_SUB_BATCH_SIZE = 10;

    private EntityManager $entityManager;
    private LoggerInterface $logger;
    private VectorStoreInterface $vectorStore;
    private EmbeddingInterface $embeddingService;
    private PayloadBuilder $payloadBuilder;
    private string $collectionName;
    private int $defaultBatchSize;

    /** @var array<int, int> Item-set IDs to include; empty = include all items. */
    private array $indexedCollectionIds;

    /**
     * @param array<int, int|string> $indexedCollectionIds Item-set IDs to
     *        include; empty = include all items (backwards-compatible default).
     */
    public function __construct(
        EntityManager $entityManager,
        LoggerInterface $logger,
        VectorStoreInterface $vectorStore,
        EmbeddingInterface $embeddingService,
        PayloadBuilder $payloadBuilder,
        string $collectionName,
        array $indexedCollectionIds = [],
        int $defaultBatchSize = 100
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->vectorStore = $vectorStore;
        $this->embeddingService = $embeddingService;
        $this->payloadBuilder = $payloadBuilder;
        $this->collectionName = $collectionName;
        $this->indexedCollectionIds = array_map('intval', $indexedCollectionIds);
        $this->defaultBatchSize = $defaultBatchSize;
    }

    /**
     * Keep the collection-scope filter in sync when the host service's
     * configuration is refreshed from admin settings mid-request.
     *
     * @param array<int, int|string> $indexedCollectionIds
     */
    public function setIndexedCollectionIds(array $indexedCollectionIds): void
    {
        $this->indexedCollectionIds = array_map('intval', $indexedCollectionIds);
    }

    public function indexAll(?string $resourceType = null, int $batchSize = 100): void
    {
        if ($batchSize <= 0) {
            throw new InvalidArgumentException('Batch size must be a positive integer');
        }

        if ($batchSize === 100 && $this->defaultBatchSize !== 100) {
            $batchSize = $this->defaultBatchSize;
        }

        $resourceClass = $resourceType ?? 'Omeka\Entity\Item';

        $supportedTypes = [
            'Omeka\Entity\Item',
            'Omeka\Entity\Media',
            'Omeka\Entity\ItemSet',
        ];

        if (!in_array($resourceClass, $supportedTypes, true)) {
            throw new InvalidArgumentException('Resource type must be one of: ' . implode(', ', $supportedTypes));
        }

        $resourceTypeName = 'items';
        if ($resourceClass === 'Omeka\Entity\Media') {
            $resourceTypeName = 'media';
        } elseif ($resourceClass === 'Omeka\Entity\ItemSet') {
            $resourceTypeName = 'collections';
        }

        try {
            $repository = $this->entityManager->getRepository($resourceClass);
            $totalCount = $repository->count([]);

            $this->logger->info('Starting batch indexation', [
                'resource_type' => $resourceTypeName,
                'total_count' => $totalCount,
                'batch_size' => $batchSize,
                'embedding_sub_batch_size' => self::EMBEDDING_SUB_BATCH_SIZE,
                'collection' => $this->collectionName,
                'timestamp' => date('c'),
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            ]);

            $successCount = 0;
            $failureCount = 0;
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);

            $totalEmbeddingApiCalls = 0;
            $totalVectorStoreApiCalls = 0;

            $queryBuilder = $repository->createQueryBuilder('r');
            $query = $queryBuilder->getQuery();
            $iterableResult = $query->iterate();

            $batchNumber = 0;
            $batchStartTime = microtime(true);
            $batchStartMemory = memory_get_usage(true);
            $batchStartSuccessCount = 0;
            $batchStartFailureCount = 0;
            $batchEmbeddingApiCalls = 0;
            $batchVectorStoreApiCalls = 0;

            $batchResources = [];
            $batchMetadata = [];
            $batchCompositeTexts = [];

            foreach ($iterableResult as $row) {
                $resource = $row[0];

                if ($resourceClass === 'Omeka\Entity\Item' && !$this->isItemInIndexedCollections($resource)) {
                    $this->entityManager->detach($resource);
                    continue;
                }

                if (count($batchResources) === 0) {
                    $batchNumber++;
                    $batchStartTime = microtime(true);
                    $batchStartMemory = memory_get_usage(true);
                    $batchStartSuccessCount = $successCount;
                    $batchStartFailureCount = $failureCount;
                    $batchEmbeddingApiCalls = 0;
                    $batchVectorStoreApiCalls = 0;

                    $this->logger->info('Batch started', [
                        'resource_type' => $resourceTypeName,
                        'batch_number' => $batchNumber,
                        'total_processed_so_far' => $successCount + $failureCount,
                        'total_count' => $totalCount,
                        'timestamp' => date('c'),
                        'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    ]);
                }

                try {
                    $metadata = $this->payloadBuilder->extractMetadata($resource);

                    if ($resourceClass === 'Omeka\Entity\Media') {
                        if (method_exists($resource, 'item') && $resource->item()) {
                            $parentItem = $resource->item();
                            $metadata['parent_item_id'] = $this->payloadBuilder->getEntityId($parentItem);
                            $metadata['parent_item_title'] = $this->payloadBuilder->getEntityTitle($parentItem);
                        }
                        if (method_exists($resource, 'mediaType') && $resource->mediaType()) {
                            $metadata['media_type'] = $resource->mediaType();
                        }
                        if (method_exists($resource, 'source') && $resource->source()) {
                            $metadata['media_source'] = $resource->source();
                        }
                    } elseif ($resourceClass === 'Omeka\Entity\ItemSet') {
                        if (method_exists($resource, 'items') && $resource->items()) {
                            $metadata['items_count'] = count($resource->items());
                        } else {
                            $metadata['items_count'] = 0;
                        }
                    }

                    $resourceTypeSlug = $resourceTypeName === 'collections' ? 'collection' : ($resourceTypeName === 'media' ? 'media' : 'item');
                    $compositeText = $this->payloadBuilder->generateCompositeText($metadata, $resourceTypeSlug);

                    if (trim($compositeText) === '') {
                        throw new InvalidArgumentException('Generated composite text is empty for resource ID ' . $this->payloadBuilder->getEntityId($resource));
                    }

                    $batchResources[] = $resource;
                    $batchMetadata[] = $metadata;
                    $batchCompositeTexts[] = $compositeText;
                } catch (InvalidArgumentException $e) {
                    $failureCount++;
                    $this->logger->warn('Skipped invalid resource during metadata extraction', [
                        'resource_type' => $resourceTypeName,
                        'resource_id' => $this->payloadBuilder->getEntityId($resource),
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Exception $e) {
                    $failureCount++;
                    $this->logger->err('Unexpected error extracting metadata', [
                        'resource_type' => $resourceTypeName,
                        'resource_id' => $this->payloadBuilder->getEntityId($resource),
                        'error' => $e->getMessage(),
                        'exception_class' => get_class($e),
                    ]);
                }

                $this->entityManager->detach($resource);

                if (count($batchResources) >= $batchSize) {
                    $this->processBatch(
                        $batchResources,
                        $batchMetadata,
                        $batchCompositeTexts,
                        $resourceClass,
                        $resourceTypeName,
                        $successCount,
                        $failureCount,
                        $batchEmbeddingApiCalls,
                        $batchVectorStoreApiCalls
                    );

                    $totalEmbeddingApiCalls += $batchEmbeddingApiCalls;
                    $totalVectorStoreApiCalls += $batchVectorStoreApiCalls;

                    $this->entityManager->clear();
                    gc_collect_cycles();

                    $this->logBatchCompletion(
                        $resourceTypeName,
                        $batchNumber,
                        $batchResources,
                        $batchStartTime,
                        $batchStartMemory,
                        $successCount,
                        $failureCount,
                        $batchStartSuccessCount,
                        $batchStartFailureCount,
                        $totalCount,
                        $batchEmbeddingApiCalls,
                        $batchVectorStoreApiCalls
                    );

                    $batchResources = [];
                    $batchMetadata = [];
                    $batchCompositeTexts = [];
                }
            }

            // Flush partial trailing batch
            if (count($batchResources) > 0) {
                $this->processBatch(
                    $batchResources,
                    $batchMetadata,
                    $batchCompositeTexts,
                    $resourceClass,
                    $resourceTypeName,
                    $successCount,
                    $failureCount,
                    $batchEmbeddingApiCalls,
                    $batchVectorStoreApiCalls
                );

                $totalEmbeddingApiCalls += $batchEmbeddingApiCalls;
                $totalVectorStoreApiCalls += $batchVectorStoreApiCalls;

                $this->entityManager->clear();
                gc_collect_cycles();

                $this->logBatchCompletion(
                    $resourceTypeName,
                    $batchNumber,
                    $batchResources,
                    $batchStartTime,
                    $batchStartMemory,
                    $successCount,
                    $failureCount,
                    $batchStartSuccessCount,
                    $batchStartFailureCount,
                    $totalCount,
                    $batchEmbeddingApiCalls,
                    $batchVectorStoreApiCalls
                );
            }

            $totalDuration = microtime(true) - $startTime;
            $totalMemoryDelta = memory_get_usage(true) - $startMemory;
            $totalProcessed = (int) $successCount + (int) $failureCount;

            $this->logger->info('Indexation completed', [
                'resource_type' => $resourceTypeName,
                'total_processed' => $totalProcessed,
                'total_success' => $successCount,
                'total_failures' => $failureCount,
                'success_rate' => $totalProcessed > 0 ? round(((int) $successCount / $totalProcessed) * 100, 2) : 0,
                'total_duration_seconds' => round($totalDuration, 2),
                'average_per_second' => $totalDuration > 0 ? round($totalProcessed / $totalDuration, 2) : 0,
                'timestamp' => date('c'),
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'memory_delta_mb' => round($totalMemoryDelta / 1024 / 1024, 2),
                'collection' => $this->collectionName,
            ]);

            // Best-effort collection optimization; non-fatal if it fails.
            try {
                $this->logger->info('Starting collection optimization', [
                    'resource_type' => $resourceTypeName,
                    'collection' => $this->collectionName,
                    'timestamp' => date('c'),
                ]);

                $this->vectorStore->optimizeCollection($this->collectionName);

                $this->logger->info('Collection optimization completed', [
                    'resource_type' => $resourceTypeName,
                    'collection' => $this->collectionName,
                    'timestamp' => date('c'),
                ]);
            } catch (\Exception $e) {
                $this->logger->err('Collection optimization failed', [
                    'resource_type' => $resourceTypeName,
                    'collection' => $this->collectionName,
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->err('Batch indexation failed with critical error', [
                'resource_type' => $resourceTypeName,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'timestamp' => date('c'),
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            ]);

            throw new RuntimeException('Batch indexation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function reindexAll(int $batchSize = 100): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $this->logger->info('Starting full reindexing of all resource types', [
            'batch_size' => $batchSize,
            'collection' => $this->collectionName,
            'timestamp' => date('c'),
            'memory_usage_mb' => round($startMemory / 1024 / 1024, 2),
        ]);

        $resourceTypes = [
            'Omeka\Entity\Item',
            'Omeka\Entity\Media',
            'Omeka\Entity\ItemSet',
        ];
        $resourceTypeNames = [
            'Omeka\Entity\Item' => 'Item',
            'Omeka\Entity\Media' => 'Media',
            'Omeka\Entity\ItemSet' => 'ItemSet',
        ];
        $completedTypes = [];
        $failedTypes = [];

        foreach ($resourceTypes as $resourceType) {
            $typeName = $resourceTypeNames[$resourceType];
            $typeStartTime = microtime(true);

            try {
                $this->logger->info('Starting reindexing for resource type', [
                    'resource_type' => $typeName,
                    'batch_size' => $batchSize,
                    'timestamp' => date('c'),
                    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                ]);

                $this->indexAll($resourceType, $batchSize);

                $typeDuration = microtime(true) - $typeStartTime;
                $completedTypes[$typeName] = ['duration_seconds' => round($typeDuration, 2)];

                $this->logger->info('Completed reindexing for resource type', [
                    'resource_type' => $typeName,
                    'duration_seconds' => round($typeDuration, 2),
                    'timestamp' => date('c'),
                    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                ]);
            } catch (RuntimeException $e) {
                $typeDuration = microtime(true) - $typeStartTime;
                $failedTypes[$typeName] = [
                    'error' => $e->getMessage(),
                    'duration_seconds' => round($typeDuration, 2),
                ];

                $this->logger->err('Failed to reindex resource type', [
                    'resource_type' => $typeName,
                    'error' => $e->getMessage(),
                    'duration_seconds' => round($typeDuration, 2),
                    'timestamp' => date('c'),
                    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                ]);
            } catch (\Exception $e) {
                $typeDuration = microtime(true) - $typeStartTime;
                $failedTypes[$typeName] = [
                    'error' => $e->getMessage(),
                    'duration_seconds' => round($typeDuration, 2),
                    'exception_class' => get_class($e),
                ];

                $this->logger->err('Unexpected error reindexing resource type', [
                    'resource_type' => $typeName,
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                    'duration_seconds' => round($typeDuration, 2),
                    'timestamp' => date('c'),
                    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                ]);
            }
        }

        $totalDuration = microtime(true) - $startTime;
        $peakMemory = memory_get_peak_usage(true);

        $this->logger->info('Full reindexing completed', [
            'completed_types' => array_keys($completedTypes),
            'failed_types' => array_keys($failedTypes),
            'total_completed' => count($completedTypes),
            'total_failed' => count($failedTypes),
            'total_duration_seconds' => round($totalDuration, 2),
            'timestamp' => date('c'),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mb' => round($peakMemory / 1024 / 1024, 2),
            'collection' => $this->collectionName,
        ]);

        if (count($failedTypes) === count($resourceTypes)) {
            throw new RuntimeException('Full reindexing failed: all resource types encountered errors');
        }

        if (!empty($failedTypes)) {
            $failedTypesList = implode(', ', array_keys($failedTypes));
            $this->logger->warn('Full reindexing completed with partial failures', [
                'failed_types' => $failedTypesList,
            ]);
        }
    }

    /**
     * Two-phase bulk processing of one batch: embed in sub-batches of 10,
     * then bulk-upsert vectors. Success/failure counters are passed by
     * reference so the caller's batch-boundary logging stays accurate.
     *
     * @param array<int, mixed> $batchResources
     * @param array<int, array<string, mixed>> $batchMetadata
     * @param array<int, string> $batchCompositeTexts
     */
    private function processBatch(
        array $batchResources,
        array $batchMetadata,
        array $batchCompositeTexts,
        string $resourceClass,
        string $resourceTypeName,
        int &$successCount,
        int &$failureCount,
        int &$embeddingApiCalls,
        int &$vectorStoreApiCalls
    ): void {
        $batchSize = count($batchResources);
        $allVectors = [];

        $subBatchCount = (int) ceil($batchSize / self::EMBEDDING_SUB_BATCH_SIZE);

        for ($i = 0; $i < $subBatchCount; $i++) {
            $startIdx = $i * self::EMBEDDING_SUB_BATCH_SIZE;
            $endIdx = min($startIdx + self::EMBEDDING_SUB_BATCH_SIZE, $batchSize);
            $subBatchTexts = array_slice($batchCompositeTexts, $startIdx, $endIdx - $startIdx);

            try {
                $subBatchVectors = $this->embeddingService->embedBatch($subBatchTexts);
                $embeddingApiCalls++;
                $allVectors = array_merge($allVectors, $subBatchVectors);

                $this->logger->debug('Generated embeddings for sub-batch', [
                    'resource_type' => $resourceTypeName,
                    'sub_batch_number' => $i + 1,
                    'sub_batch_count' => $subBatchCount,
                    'sub_batch_size' => count($subBatchTexts),
                    'vectors_generated' => count($subBatchVectors),
                ]);
            } catch (RuntimeException $e) {
                $this->logger->err('Embedding service failed for sub-batch', [
                    'resource_type' => $resourceTypeName,
                    'sub_batch_number' => $i + 1,
                    'sub_batch_size' => count($subBatchTexts),
                    'error' => $e->getMessage(),
                ]);

                for ($j = $startIdx; $j < $endIdx; $j++) {
                    $failureCount++;
                }
                for ($j = 0; $j < count($subBatchTexts); $j++) {
                    $allVectors[] = null;
                }
            } catch (\Exception $e) {
                $this->logger->err('Unexpected error generating embeddings for sub-batch', [
                    'resource_type' => $resourceTypeName,
                    'sub_batch_number' => $i + 1,
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);

                for ($j = $startIdx; $j < $endIdx; $j++) {
                    $failureCount++;
                }
                for ($j = 0; $j < count($subBatchTexts); $j++) {
                    $allVectors[] = null;
                }
            }
        }

        $points = [];
        for ($i = 0; $i < $batchSize; $i++) {
            $resource = $batchResources[$i];
            $metadata = $batchMetadata[$i];
            $vector = $allVectors[$i];

            if ($vector === null) {
                continue;
            }

            try {
                $resourceTypeSlug = $resourceTypeName === 'collections' ? 'collection' : ($resourceTypeName === 'media' ? 'media' : 'item');
                $payload = $this->payloadBuilder->buildPayload($resource, $metadata, $resourceTypeSlug);

                if ($resourceClass === 'Omeka\Entity\Media') {
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
                } elseif ($resourceClass === 'Omeka\Entity\ItemSet') {
                    $payload['items_count'] = $metadata['items_count'];
                }

                $points[] = [
                    'id' => $this->payloadBuilder->getEntityId($resource),
                    'vector' => $vector,
                    'payload' => $payload,
                ];
            } catch (\Exception $e) {
                $failureCount++;
                $this->logger->err('Failed to build vector-store payload', [
                    'resource_type' => $resourceTypeName,
                    'resource_id' => $this->payloadBuilder->getEntityId($resource),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (count($points) > 0) {
            try {
                $result = $this->vectorStore->bulkUpsert($this->collectionName, $points);
                $vectorStoreApiCalls++;

                if (!$result) {
                    throw new RuntimeException('Bulk upsert operation returned false');
                }

                $successCount += count($points);

                $this->logger->info('Successfully bulk-upserted batch to vector store', [
                    'resource_type' => $resourceTypeName,
                    'points_count' => count($points),
                    'collection' => $this->collectionName,
                ]);
            } catch (RuntimeException $e) {
                $failureCount += count($points);
                $this->logger->err('Vector-store bulk upsert failed for batch', [
                    'resource_type' => $resourceTypeName,
                    'points_count' => count($points),
                    'collection' => $this->collectionName,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Exception $e) {
                $failureCount += count($points);
                $this->logger->err('Unexpected error during bulk upsert', [
                    'resource_type' => $resourceTypeName,
                    'points_count' => count($points),
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);
            }
        }
    }

    /**
     * @param array<int, mixed> $batchResources
     */
    private function logBatchCompletion(
        string $resourceTypeName,
        int $batchNumber,
        array $batchResources,
        float $batchStartTime,
        int $batchStartMemory,
        int $successCount,
        int $failureCount,
        int $batchStartSuccessCount,
        int $batchStartFailureCount,
        int $totalCount,
        int $batchEmbeddingApiCalls,
        int $batchVectorStoreApiCalls
    ): void {
        $batchDuration = microtime(true) - $batchStartTime;
        $batchMemoryDelta = memory_get_usage(true) - $batchStartMemory;
        $processedSoFar = $successCount + $failureCount;
        $percentComplete = $totalCount > 0 ? round(($processedSoFar / $totalCount) * 100, 1) : 0.0;

        $this->logger->info('Batch completed', [
            'resource_type' => $resourceTypeName,
            'batch_number' => $batchNumber,
            'batch_processed' => count($batchResources),
            'batch_success' => $successCount - $batchStartSuccessCount,
            'batch_failures' => $failureCount - $batchStartFailureCount,
            'total_processed' => $processedSoFar,
            'total_count' => $totalCount,
            'percent_complete' => $percentComplete,
            'batch_duration_seconds' => round($batchDuration, 2),
            'items_per_second' => $batchDuration > 0 ? round(count($batchResources) / $batchDuration, 2) : 0,
            'timestamp' => date('c'),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_delta_mb' => round($batchMemoryDelta / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'cache_hit_rate' => $this->getCacheHitRateFromService(),
            'embedding_api_calls' => $batchEmbeddingApiCalls,
            'vector_store_api_calls' => $batchVectorStoreApiCalls,
        ]);
    }

    private function getCacheHitRateFromService(): string
    {
        if (!method_exists($this->embeddingService, 'getCacheStats')) {
            return 'N/A';
        }

        try {
            $stats = $this->embeddingService->getCacheStats();

            if (isset($stats['hit_rate'])) {
                return $stats['hit_rate'];
            }

            if (isset($stats['hits'], $stats['misses'])) {
                $hits = (int) $stats['hits'];
                $misses = (int) $stats['misses'];
                $total = $hits + $misses;
                if ($total > 0) {
                    return number_format(($hits / $total) * 100, 1) . '%';
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug('Failed to get cache statistics from embedding service', [
                'error' => $e->getMessage(),
            ]);
        }

        return 'N/A';
    }

    /**
     * @param mixed $item
     */
    private function isItemInIndexedCollections($item): bool
    {
        if (empty($this->indexedCollectionIds)) {
            return true;
        }

        $itemCollectionIds = [];

        if (method_exists($item, 'itemSets')) {
            foreach ($item->itemSets() as $itemSetRepresentation) {
                $itemCollectionIds[] = $itemSetRepresentation->id();
            }
        } elseif (method_exists($item, 'getItemSets')) {
            foreach ($item->getItemSets() as $itemSetEntity) {
                $itemCollectionIds[] = $itemSetEntity->getId();
            }
        }

        return !empty(array_intersect($itemCollectionIds, $this->indexedCollectionIds));
    }
}
