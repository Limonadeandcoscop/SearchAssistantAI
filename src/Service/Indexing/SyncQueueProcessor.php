<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Indexing;

use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use Laminas\Log\LoggerInterface;
use RuntimeException;

/**
 * Consumer-side of the sync queue: pulls pending rows out of
 * SyncQueueRepository and dispatches them to the VectorIndexService.
 *
 * Two public entry points mirror the two callers we have today:
 *
 *   - processBatch(\$batchSize) — cron / manual trigger; fetches N
 *     pending rows, processes each, stamps completed/failed on the
 *     queue row by id.
 *   - processImmediate(\$resourceId, \$resourceType, \$action) — called
 *     straight after enqueue when `search_assistant_auto_process_queue` is on;
 *     stamps the matching pending row by (resourceId, resourceType).
 *
 * Also exposes fetchResourceById() and indexEntityByType() so the
 * force-sync path on SyncService can share the same entity lookup and
 * indexer dispatch without duplicating the switch statements.
 *
 * Extracted from SyncService during the v1.0 → v1.1 refactor so the
 * "how does a queue item become a vector" pipeline lives in one place
 * and SyncService stays focused on event handling + routing policy.
 */
class SyncQueueProcessor
{
    private EntityManager $entityManager;
    private LoggerInterface $logger;
    private VectorIndexInterface $vectorIndexService;
    private SyncQueueRepository $queue;

    public function __construct(
        EntityManager $entityManager,
        LoggerInterface $logger,
        VectorIndexInterface $vectorIndexService,
        SyncQueueRepository $queue
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->vectorIndexService = $vectorIndexService;
        $this->queue = $queue;
    }

    /**
     * Fetch up to N pending queue rows (oldest first) and process each.
     * Returns {processed, succeeded, failed} counters.
     *
     * @return array{processed:int, succeeded:int, failed:int}
     */
    public function processBatch(int $batchSize = 10): array
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new InvalidArgumentException('Batch size must be between 1 and 1000');
        }

        $this->logger->info('SyncQueueProcessor: Starting queue processing', [
            'batch_size' => $batchSize,
        ]);

        $stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];

        try {
            $queueItems = $this->queue->fetchPending($batchSize);

            if (empty($queueItems)) {
                $this->logger->debug('SyncQueueProcessor: No pending queue items found');
                return $stats;
            }

            $this->logger->info('SyncQueueProcessor: Processing queue items', [
                'items_found' => count($queueItems),
            ]);

            foreach ($queueItems as $item) {
                $queueId = $item['id'];
                $resourceId = $item['resource_id'];
                $resourceType = $item['resource_type'];
                $action = $item['action'];

                $stats['processed']++;

                $this->logger->debug('SyncQueueProcessor: Processing queue item', [
                    'queue_id' => $queueId,
                    'resource_id' => $resourceId,
                    'resource_type' => $resourceType,
                    'action' => $action,
                ]);

                try {
                    $success = $this->dispatch($resourceId, $resourceType, $action);
                    if ($success) {
                        $this->queue->markCompletedById($queueId);
                        $stats['succeeded']++;
                    } else {
                        $this->queue->markFailedById($queueId, 'Processing returned false');
                        $stats['failed']++;
                    }
                } catch (\Exception $e) {
                    $this->logger->err('SyncQueueProcessor: Queue item processing failed', [
                        'queue_id' => $queueId,
                        'resource_id' => $resourceId,
                        'resource_type' => $resourceType,
                        'error' => $e->getMessage(),
                    ]);
                    $this->queue->markFailedById($queueId, $e->getMessage());
                    $stats['failed']++;
                    // Continue — a single bad row shouldn't abort the batch.
                }
            }

            $this->logger->info('SyncQueueProcessor: Queue processing completed', $stats);
            return $stats;
        } catch (\Exception $e) {
            $this->logger->err('SyncQueueProcessor: Queue processing failed catastrophically', [
                'error' => $e->getMessage(),
                'stats' => $stats,
            ]);
            throw new RuntimeException('Failed to process sync queue: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Auto-process path: the caller just enqueued (resourceId, resourceType,
     * action) and now wants it indexed synchronously. Flips the queue row
     * from pending → completed/failed by (resourceId, resourceType).
     */
    public function processImmediate(int $resourceId, string $resourceType, string $action): bool
    {
        $this->validate($resourceId, $resourceType, $action);

        $this->logger->info('SyncQueueProcessor: Processing queued resource immediately', [
            'resource_id' => $resourceId,
            'resource_type' => $resourceType,
            'action' => $action,
        ]);

        try {
            $result = $this->dispatch($resourceId, $resourceType, $action);

            if ($result) {
                $this->queue->markPendingForResourceCompleted($resourceId, $resourceType);
            } else {
                $this->queue->markPendingForResourceFailed($resourceId, $resourceType, 'Processing returned false');
            }
            return $result;
        } catch (RuntimeException $e) {
            try {
                $this->queue->markPendingForResourceFailed($resourceId, $resourceType, $e->getMessage());
            } catch (\Exception $updateException) {
                $this->logger->err('SyncQueueProcessor: Failed to update queue status after error', [
                    'resource_id' => $resourceId,
                    'resource_type' => $resourceType,
                    'update_error' => $updateException->getMessage(),
                ]);
            }

            $this->logger->err('SyncQueueProcessor: Failed to process queued resource', [
                'resource_id' => $resourceId,
                'resource_type' => $resourceType,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Load an Omeka entity by (id, resourceType). Returns null when the
     * row no longer exists — callers treat that as "nothing to do".
     *
     * @return object|null
     */
    public function fetchResourceById(int $resourceId, string $resourceType)
    {
        $entityClassByType = [
            'items' => \Omeka\Entity\Item::class,
            'media' => \Omeka\Entity\Media::class,
            'item_sets' => \Omeka\Entity\ItemSet::class,
        ];
        if (!isset($entityClassByType[$resourceType])) {
            throw new InvalidArgumentException("Unsupported resource type: {$resourceType}");
        }
        $entityClass = $entityClassByType[$resourceType];

        return $this->entityManager->find($entityClass, $resourceId);
    }

    /**
     * Route a loaded entity to the correct indexer method.
     *
     * @param mixed $entity
     */
    public function indexEntityByType($entity, string $entityType): bool
    {
        switch ($entityType) {
            case 'items':
                return $this->vectorIndexService->indexItem($entity);
            case 'media':
                return $this->vectorIndexService->indexMedia($entity);
            case 'item_sets':
                return $this->vectorIndexService->indexCollection($entity);
            default:
                throw new RuntimeException("Unsupported entity type: {$entityType}");
        }
    }

    /**
     * Inner dispatch: converts a (resourceId, resourceType, action) triple
     * into an indexer call. Delete actions don't need a DB lookup; for
     * create/update we load the entity and route it. A missing resource
     * (create/update with no row) counts as success — the queue item is
     * just stale and can be cleared.
     */
    private function dispatch(int $resourceId, string $resourceType, string $action): bool
    {
        if ($action === 'delete') {
            $this->logger->debug('SyncQueueProcessor: Processing delete action from queue', [
                'resource_id' => $resourceId,
                'resource_type' => $resourceType,
            ]);
            return $this->vectorIndexService->deleteVector($resourceId);
        }

        // Pick up the latest indexing-scope settings before we index.
        if (method_exists($this->vectorIndexService, 'refreshConfiguration')) {
            try {
                $this->vectorIndexService->refreshConfiguration();
            } catch (\Exception $e) {
                $this->logger->warn('SyncQueueProcessor: Failed to refresh VectorIndexService configuration', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $entity = $this->fetchResourceById($resourceId, $resourceType);
            if ($entity === null) {
                $this->logger->warn('SyncQueueProcessor: Resource no longer exists, skipping queue item', [
                    'resource_id' => $resourceId,
                    'resource_type' => $resourceType,
                ]);
                return true;
            }
            return $this->indexEntityByType($entity, $resourceType);
        } catch (\Exception $e) {
            $this->logger->err('SyncQueueProcessor: Failed to process queue item', [
                'resource_id' => $resourceId,
                'resource_type' => $resourceType,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Failed to process queue item: ' . $e->getMessage(), 0, $e);
        }
    }

    private function validate(int $resourceId, string $resourceType, string $action): void
    {
        if ($resourceId <= 0) {
            throw new InvalidArgumentException('Resource ID must be positive integer');
        }
        if ($resourceType === '') {
            throw new InvalidArgumentException('Resource type cannot be empty');
        }
        if (!in_array($action, ['create', 'update', 'delete'], true)) {
            throw new InvalidArgumentException("Invalid action: {$action}. Must be 'create', 'update', or 'delete'");
        }
    }
}
