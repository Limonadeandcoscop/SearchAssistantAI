<?php
declare(strict_types=1);
namespace SearchAssistantAI\Service\Indexing;

use Doctrine\ORM\EntityManager;
use Laminas\Log\LoggerInterface;
use Omeka\Job\Dispatcher;
use RuntimeException;
use InvalidArgumentException;

/**
 * Service orchestrating automatic synchronization between Omeka S entities and vectors in the vector store.
 *
 * Handles event-driven sync operations by extracting entities from API events,
 * determining sync strategy (synchronous vs asynchronous), and coordinating
 * between VectorIndexService for vector operations and Job system for heavy processing.
 *
 * This service acts as the orchestration layer between Omeka S event listeners
 * and the vector indexing infrastructure, ensuring data consistency between
 * Omeka S resources and their vectors in the vector store representations.
 */
class SyncService
{
    /**
     * @var LoggerInterface Laminas logger for sync operation tracking
     */
    private $logger;

    /**
     * @var VectorIndexInterface Vector indexing service for direct sync operations
     */
    private $vectorIndexService;

    /**
     * @var Dispatcher Omeka S job dispatcher for async operations
     */
    private $jobDispatcher;

    /**
     * @var \Omeka\Settings\Settings Settings service for configuration access
     */
    private $settings;

    /**
     * @var int Threshold for determining async processing (number of properties)
     */
    private $asyncThreshold = 50;

    /** @var SyncQueueRepository Persistence gateway for search_assistant_sync_queue rows */
    private SyncQueueRepository $queue;

    /** @var SyncPolicy Routing rules (sync/async, heavy-entity threshold) */
    private SyncPolicy $policy;

    /** @var SyncQueueProcessor Pulls pending rows and dispatches to the indexer */
    private SyncQueueProcessor $processor;

    /**
     * @param mixed $settings
     */
    public function __construct(
        EntityManager $entityManager,
        LoggerInterface $logger,
        VectorIndexInterface $vectorIndexService,
        Dispatcher $jobDispatcher,
        $settings,
        ?SyncQueueRepository $queue = null,
        ?SyncPolicy $policy = null,
        ?SyncQueueProcessor $processor = null
    ) {
        $this->logger = $logger;
        $this->vectorIndexService = $vectorIndexService;
        $this->jobDispatcher = $jobDispatcher;
        $this->settings = $settings;
        $this->queue = $queue ?? new SyncQueueRepository($entityManager->getConnection());
        $this->policy = $policy ?? new SyncPolicy($settings, $logger, $this->asyncThreshold);
        $this->processor = $processor ?? new SyncQueueProcessor(
            $entityManager,
            $logger,
            $vectorIndexService,
            $this->queue
        );
    }

    /**
     * Handle entity creation event.
     *
     * Determines whether to sync entity immediately or dispatch background job
     * based on entity complexity (property count, media attachments). Simple
     * entities are indexed synchronously to provide immediate search availability,
     * while complex entities are queued to prevent API timeout.
     *
     * @param mixed $entity Omeka S entity (Item, Media, or ItemSet)
     * @return bool True if sync initiated successfully, false otherwise
     * @throws InvalidArgumentException If entity is invalid or missing required methods
     * @throws RuntimeException If sync operation fails
     */
    public function handleCreate($entity): bool
    {
        // Validate entity
        if ($entity === null) {
            throw new InvalidArgumentException('Entity cannot be null');
        }

        $entityId = $this->getEntityId($entity);
        $entityType = $this->getEntityResourceName($entity);

        $this->logger->info('SyncService: Handling entity creation', [
            'entity_id' => $entityId,
            'entity_type' => $entityType
        ]);

        try {
            // Refresh VectorIndexService configuration to ensure latest settings are used
            // This ensures admin configuration changes take effect immediately
            $this->refreshVectorIndexServiceConfiguration();

            // Get configured sync mode
            $syncMode = $this->getSyncMode();

            $this->logger->debug('SyncService: Using configured sync mode', [
                'sync_mode' => $syncMode,
                'entity_id' => $entityId,
                'entity_type' => $entityType
            ]);

            // Route based on configured sync mode
            switch ($syncMode) {
                case 'synchronous':
                    // Immediate synchronous processing
                    $this->logger->debug('SyncService: Processing entity synchronously', [
                        'entity_id' => $entityId,
                        'entity_type' => $entityType
                    ]);
                    return $this->processor->indexEntityByType($entity, $entityType);

                case 'asynchronous':
                    // Queue for background processing with synchronous fallback
                    $this->logger->debug('SyncService: Queuing entity for async processing', [
                        'entity_id' => $entityId,
                        'entity_type' => $entityType
                    ]);
                    try {
                        return $this->queueResource($entity, 'create');
                    } catch (\Exception $e) {
                        // Fallback to synchronous processing if queueing fails
                        $this->logger->warn('SyncService: Queue processing failed, falling back to synchronous', [
                            'entity_id' => $entityId,
                            'entity_type' => $entityType,
                            'error' => $e->getMessage()
                        ]);
                        return $this->processor->indexEntityByType($entity, $entityType);
                    }

                case 'batch':
                    // Queue for batch processing with synchronous fallback
                    $this->logger->debug('SyncService: Queuing entity for batch processing', [
                        'entity_id' => $entityId,
                        'entity_type' => $entityType
                    ]);
                    try {
                        return $this->queueResource($entity, 'create');
                    } catch (\Exception $e) {
                        // Fallback to synchronous processing if queueing fails
                        $this->logger->warn('SyncService: Queue processing failed, falling back to synchronous', [
                            'entity_id' => $entityId,
                            'entity_type' => $entityType,
                            'error' => $e->getMessage()
                        ]);
                        return $this->processor->indexEntityByType($entity, $entityType);
                    }

                default:
                    // Fallback to async mode for safety
                    $this->logger->warn('SyncService: Unknown sync mode, falling back to async', [
                        'sync_mode' => $syncMode,
                        'entity_id' => $entityId,
                        'entity_type' => $entityType
                    ]);
                    try {
                        return $this->queueResource($entity, 'create');
                    } catch (\Exception $e) {
                        // Fallback to synchronous processing if queueing fails
                        $this->logger->warn('SyncService: Queue processing failed, falling back to synchronous', [
                            'entity_id' => $entityId,
                            'entity_type' => $entityType,
                            'error' => $e->getMessage()
                        ]);
                        return $this->processor->indexEntityByType($entity, $entityType);
                    }
            }

        } catch (RuntimeException $e) {
            $this->logger->err('SyncService: Failed to handle entity creation', [
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Handle entity update event.
     *
     * Updates are processed similarly to creation events - simple updates are
     * handled synchronously while complex updates are dispatched to background jobs.
     * Vector upsert operations in the vector store are idempotent, making updates safe to retry.
     *
     * @param mixed $entity Omeka S entity (Item, Media, or ItemSet)
     * @return bool True if sync initiated successfully, false otherwise
     * @throws InvalidArgumentException If entity is invalid
     * @throws RuntimeException If sync operation fails
     */
    public function handleUpdate($entity): bool
    {
        // Validate entity
        if ($entity === null) {
            throw new InvalidArgumentException('Entity cannot be null');
        }

        $entityId = $this->getEntityId($entity);
        $entityType = $this->getEntityResourceName($entity);

        $this->logger->info('SyncService: Handling entity update', [
            'entity_id' => $entityId,
            'entity_type' => $entityType
        ]);

        try {
            // Refresh VectorIndexService configuration to ensure latest settings are used
            // This ensures admin configuration changes take effect immediately
            $this->refreshVectorIndexServiceConfiguration();

            // Get configured sync mode
            $syncMode = $this->getSyncMode();

            $this->logger->debug('SyncService: Using configured sync mode', [
                'sync_mode' => $syncMode,
                'entity_id' => $entityId,
                'entity_type' => $entityType
            ]);

            // Route based on configured sync mode
            switch ($syncMode) {
                case 'synchronous':
                    // Immediate synchronous processing
                    $this->logger->debug('SyncService: Processing entity update synchronously', [
                        'entity_id' => $entityId,
                        'entity_type' => $entityType
                    ]);
                    return $this->processor->indexEntityByType($entity, $entityType);

                case 'asynchronous':
                    // Queue for background processing with synchronous fallback
                    $this->logger->debug('SyncService: Queuing entity update for async processing', [
                        'entity_id' => $entityId,
                        'entity_type' => $entityType
                    ]);
                    try {
                        return $this->queueResource($entity, 'update');
                    } catch (\Exception $e) {
                        // Fallback to synchronous processing if queueing fails
                        $this->logger->warn('SyncService: Queue processing failed, falling back to synchronous', [
                            'entity_id' => $entityId,
                            'entity_type' => $entityType,
                            'error' => $e->getMessage()
                        ]);
                        return $this->processor->indexEntityByType($entity, $entityType);
                    }

                case 'batch':
                    // Queue for batch processing with synchronous fallback
                    $this->logger->debug('SyncService: Queuing entity update for batch processing', [
                        'entity_id' => $entityId,
                        'entity_type' => $entityType
                    ]);
                    try {
                        return $this->queueResource($entity, 'update');
                    } catch (\Exception $e) {
                        // Fallback to synchronous processing if queueing fails
                        $this->logger->warn('SyncService: Queue processing failed, falling back to synchronous', [
                            'entity_id' => $entityId,
                            'entity_type' => $entityType,
                            'error' => $e->getMessage()
                        ]);
                        return $this->processor->indexEntityByType($entity, $entityType);
                    }

                default:
                    // Fallback to async mode for safety
                    $this->logger->warn('SyncService: Unknown sync mode, falling back to async', [
                        'sync_mode' => $syncMode,
                        'entity_id' => $entityId,
                        'entity_type' => $entityType
                    ]);
                    try {
                        return $this->queueResource($entity, 'update');
                    } catch (\Exception $e) {
                        // Fallback to synchronous processing if queueing fails
                        $this->logger->warn('SyncService: Queue processing failed, falling back to synchronous', [
                            'entity_id' => $entityId,
                            'entity_type' => $entityType,
                            'error' => $e->getMessage()
                        ]);
                        return $this->processor->indexEntityByType($entity, $entityType);
                    }
            }

        } catch (RuntimeException $e) {
            $this->logger->err('SyncService: Failed to handle entity update', [
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Handle entity deletion event.
     *
     * Note: Delete events only provide entity ID via request parameter, not the full
     * entity object. Deletion operations are always processed synchronously as they
     * are lightweight (single vector-store API call) and must complete immediately to
     * maintain search consistency.
     *
     * @param int $entityId Deleted entity ID
     * @param string $entityType Entity type ('items', 'media', 'item_sets')
     * @return bool True if deletion initiated successfully, false otherwise
     * @throws InvalidArgumentException If entity ID or type is invalid
     * @throws RuntimeException If deletion operation fails
     */
    public function handleDelete(int $entityId, string $entityType): bool
    {
        // Validate inputs
        if ($entityId <= 0) {
            throw new InvalidArgumentException('Entity ID must be positive integer');
        }

        if (empty($entityType)) {
            throw new InvalidArgumentException('Entity type cannot be empty');
        }

        $this->logger->info('SyncService: Handling entity deletion', [
            'entity_id' => $entityId,
            'entity_type' => $entityType
        ]);

        try {
            // Deletion is always synchronous - lightweight operation
            // Use VectorIndexService to remove vector from the vector store
            $result = $this->vectorIndexService->deleteVector($entityId);

            if (!$result) {
                $this->logger->warn('SyncService: Vector deletion returned false', [
                    'entity_id' => $entityId,
                    'entity_type' => $entityType
                ]);
            }

            $this->logger->debug('SyncService: Entity deletion completed', [
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'result' => $result
            ]);

            return $result;

        } catch (RuntimeException $e) {
            $this->logger->err('SyncService: Failed to handle entity deletion', [
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Handle batch update event.
     *
     * Batch operations are always dispatched to background jobs to prevent API
     * timeouts and server overload. Individual jobs are created for each entity
     * in the batch, allowing for independent failure handling and progress tracking.
     *
     * @param list<object|null> $entities Array of Omeka S entities
     * @return bool True if batch sync initiated successfully, false otherwise
     * @throws InvalidArgumentException If entities array is empty or contains invalid entities
     */
    public function handleBatchUpdate(array $entities): bool
    {
        // Validate entities array
        if (empty($entities)) {
            $this->logger->warn('SyncService: Empty entities array provided to handleBatchUpdate');
            return false;
        }

        $entityCount = count($entities);

        $this->logger->info('SyncService: Handling batch update', [
            'entity_count' => $entityCount
        ]);

        try {
            // Always use async processing for batch operations
            $jobsDispatched = 0;

            foreach ($entities as $entity) {
                if ($entity === null) {
                    $this->logger->warn('SyncService: Null entity encountered in batch');
                    continue;
                }

                try {
                    $entityId = $this->getEntityId($entity);
                    $entityType = $this->getEntityResourceName($entity);
                } catch (InvalidArgumentException $e) {
                    $this->logger->warn('SyncService: Invalid entity in batch: ' . $e->getMessage());
                    continue;
                }

                // Dispatch individual job for each entity
                $this->jobDispatcher->dispatch(\SearchAssistantAI\Job\IndexEntityJob::class, [
                    'entity_id' => $entityId,
                    'entity_type' => $entityType
                ]);

                $jobsDispatched++;

                $this->logger->debug('SyncService: Dispatched batch job', [
                    'entity_id' => $entityId,
                    'entity_type' => $entityType,
                    'job_number' => $jobsDispatched
                ]);
            }

            $this->logger->info('SyncService: Batch update jobs dispatched', [
                'total_entities' => $entityCount,
                'jobs_dispatched' => $jobsDispatched
            ]);

            return $jobsDispatched > 0;

        } catch (\Exception $e) {
            $this->logger->err('SyncService: Failed to handle batch update', [
                'entity_count' => $entityCount,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Batch update failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the configured synchronization mode.
     *
     * Retrieves the sync mode configuration from module settings.
     * Supported modes:
     * - 'synchronous': Immediate indexing on modification (development mode)
     * - 'asynchronous': Queue-based background processing via MySQL table (production mode)
     * - 'batch': Accumulate changes and process in groups
     *
     * Defaults to 'asynchronous' if not configured or invalid mode specified.
     *
     * @return string The configured sync mode ('synchronous', 'asynchronous', or 'batch')
     */
    private function getSyncMode(): string
    {
        return $this->policy->getSyncMode();
    }

    /**
     * Refresh VectorIndexService configuration from current Omeka Settings.
     *
     * Ensures the VectorIndexService has the latest configuration values for
     * indexedPropertyIds and indexedCollectionIds before performing indexing operations.
     * This is critical for real-time sync to respect configuration changes made by admins.
     *
     * The refresh is performed gracefully - if VectorIndexService doesn't support
     * configuration refresh (older version without the method), the operation is skipped
     * with a debug log. If the refresh fails for other reasons, an error is logged but
     * the operation continues to avoid blocking sync operations.
     *
     * @return void
     */
    private function refreshVectorIndexServiceConfiguration(): void
    {
        try {
            // Check if VectorIndexService supports configuration refresh
            if (method_exists($this->vectorIndexService, 'refreshConfiguration')) {
                $this->logger->debug('SyncService: Refreshing VectorIndexService configuration');
                $this->vectorIndexService->refreshConfiguration();
                $this->logger->debug('SyncService: VectorIndexService configuration refreshed successfully');
            } else {
                // VectorIndexService doesn't support refresh - likely older version
                // Log debug message but don't fail the operation
                $this->logger->debug('SyncService: VectorIndexService does not support refreshConfiguration()');
            }
        } catch (\Exception $e) {
            // Log error but don't fail the sync operation
            // Using stale configuration is better than failing to sync entirely
            $this->logger->warn('SyncService: Failed to refresh VectorIndexService configuration', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Process pending items from the synchronization queue.
     *
     * Fetches pending queue items from the search_assistant_sync_queue table, processes them
     * in batches to manage memory usage, and updates their status based on processing
     * results. Each item is synchronized to the vector store via VectorIndexService, with
     * comprehensive error handling to ensure queue integrity even when individual
     * items fail.
     *
     * This method is designed to be called by cron jobs or manual admin actions for
     * asynchronous synchronization mode. It processes items in FIFO order (oldest first)
     * and handles edge cases such as deleted resources gracefully.
     *
     * @param int $batchSize Maximum number of queue items to process in this execution (default: 10)
     * @return array<string, int> Statistics about processing: ['processed' => int, 'succeeded' => int, 'failed' => int]
     * @throws InvalidArgumentException If batch size is invalid (< 1 or > 1000)
     */
    public function processSyncQueue(int $batchSize = 10): array
    {
        return $this->processor->processBatch($batchSize);
    }

    /**
     * Process a queued resource immediately after it has been added to the queue.
     *
     * This method provides immediate processing of a queued resource, allowing
     * resources to transition from 'pending' to 'completed' or 'failed' status
     * without waiting for batch queue processing. It's designed to be called
     * right after queueResource() to ensure resources are indexed promptly
     * in asynchronous mode with auto-processing enabled.
     *
     * @param int $resourceId Omeka S resource ID
     * @param string $resourceType Resource type ('items', 'media', 'item_sets')
     * @param string $action Sync action ('create', 'update', 'delete')
     * @return bool True if processing succeeded, false otherwise
     * @throws InvalidArgumentException If resource ID or type is invalid
     * @throws RuntimeException If processing fails
     */
    public function processQueuedResource(int $resourceId, string $resourceType, string $action): bool
    {
        return $this->processor->processImmediate($resourceId, $resourceType, $action);
    }


    /**
     * Get synchronization queue and processing statistics.
     *
     * Queries the search_assistant_sync_queue table to provide comprehensive statistics about
     * sync operations including queue size, completion rates, and processing times.
     * This method is used by the admin dashboard to display sync status and monitor
     * queue health.
     *
     * Statistics include:
     * - Total items in queue (all statuses)
     * - Pending items awaiting processing
     * - Successfully completed items
     * - Failed items with errors
     * - Average processing time for completed items
     * - Oldest pending item age
     *
     * @return array<string, mixed> Associative array with sync statistics:
     *   - 'total' => int Total queue items (all statuses)
     *   - 'pending' => int Items awaiting processing
     *   - 'completed' => int Successfully processed items
     *   - 'failed' => int Items that failed processing
     *   - 'average_processing_time' => float|null Average processing time in seconds (null if no completed items)
     *   - 'oldest_pending_age' => int|null Age of oldest pending item in seconds (null if no pending items)
     * @throws RuntimeException If database query fails
     */
    public function getSyncStats(): array
    {
        $this->logger->debug('SyncService: Fetching sync statistics');
        try {
            $stats = $this->queue->getStats();
            $this->logger->debug('SyncService: Sync statistics retrieved', $stats);
            return $stats;
        } catch (\Exception $e) {
            $this->logger->err('SyncService: Failed to fetch sync statistics', [
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Failed to fetch sync statistics: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Force synchronization of a resource bypassing async checks.
     *
     * This method allows manual synchronization of resources directly to the vector store,
     * bypassing the normal async processing checks. Useful for administrative
     * actions, debugging, or ensuring immediate synchronization of critical resources.
     * The resource is always processed synchronously regardless of complexity.
     *
     * @param int $resourceId Omeka S resource ID to synchronize
     * @param string $resourceType Resource type ('items', 'media', 'item_sets')
     * @return bool True if synchronization succeeded, false otherwise
     * @throws InvalidArgumentException If resource ID is invalid or resource type is unsupported
     * @throws RuntimeException If resource not found or synchronization fails
     */
    public function forceSyncResource(int $resourceId, string $resourceType): bool
    {
        // Validate inputs
        if ($resourceId <= 0) {
            throw new InvalidArgumentException('Resource ID must be positive integer');
        }

        if (empty($resourceType)) {
            throw new InvalidArgumentException('Resource type cannot be empty');
        }

        $this->logger->info('SyncService: Forcing resource synchronization', [
            'resource_id' => $resourceId,
            'resource_type' => $resourceType
        ]);

        try {
            // Fetch resource from database
            $entity = $this->processor->fetchResourceById($resourceId, $resourceType);

            if ($entity === null) {
                throw new RuntimeException("Resource not found: {$resourceType} #{$resourceId}");
            }

            // Force synchronous indexing (bypass async checks)
            $this->logger->debug('SyncService: Processing forced sync synchronously', [
                'resource_id' => $resourceId,
                'resource_type' => $resourceType
            ]);

            $result = $this->processor->indexEntityByType($entity, $resourceType);

            if ($result) {
                $this->logger->info('SyncService: Forced synchronization succeeded', [
                    'resource_id' => $resourceId,
                    'resource_type' => $resourceType
                ]);
            } else {
                $this->logger->warn('SyncService: Forced synchronization returned false', [
                    'resource_id' => $resourceId,
                    'resource_type' => $resourceType
                ]);
            }

            return $result;

        } catch (RuntimeException $e) {
            $this->logger->err('SyncService: Failed to force synchronize resource', [
                'resource_id' => $resourceId,
                'resource_type' => $resourceType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->err('SyncService: Unexpected error during forced synchronization', [
                'resource_id' => $resourceId,
                'resource_type' => $resourceType,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to force synchronize resource: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Add a resource to the MySQL synchronization queue.
     *
     * Inserts a new pending synchronization task into the search_assistant_sync_queue table
     * for asynchronous processing. This method is called when the sync mode is set
     * to asynchronous, allowing resources to be queued for background processing
     * instead of being indexed immediately.
     *
     * When auto_process is enabled (search_assistant_auto_process_queue setting), the queued
     * resource will be processed immediately after insertion. Otherwise, it will be
     * processed later by processSyncQueue() method, typically invoked by a cron job
     * or manual admin action.
     *
     * @param mixed $entity Omeka S entity (Item, Media, or ItemSet)
     * @param string $action Sync action ('create', 'update', or 'delete')
     * @return bool True if item was successfully added to queue, false otherwise
     * @throws InvalidArgumentException If entity or action is invalid
     * @throws RuntimeException If queue insertion fails
     */
    private function queueResource($entity, string $action): bool
    {
        // This method queues resources and optionally auto_process them if enabled

        // Validate entity
        if ($entity === null) {
            throw new InvalidArgumentException('Entity cannot be null');
        }

        // Validate action
        if (!in_array($action, ['create', 'update', 'delete'])) {
            throw new InvalidArgumentException("Invalid action: {$action}. Must be 'create', 'update', or 'delete'");
        }

        $resourceId = $this->getEntityId($entity);
        $resourceType = $this->getEntityResourceName($entity);

        $this->logger->debug('SyncService: Adding resource to queue', [
            'resource_id' => $resourceId,
            'resource_type' => $resourceType,
            'action' => $action
        ]);

        try {
            $queueId = $this->queue->enqueue($resourceId, $resourceType, $action);

            if ($queueId !== null) {
                $this->logger->info('SyncService: Resource added to queue successfully', [
                    'resource_id' => $resourceId,
                    'resource_type' => $resourceType,
                    'action' => $action,
                    'queue_id' => $queueId,
                ]);

                // Check if auto-process is enabled
                $autoProcess = $this->settings->get('search_assistant_auto_process_queue', true);

                if ($autoProcess) {
                    $this->logger->debug('SyncService: Auto-process enabled, processing queued resource immediately', [
                        'resource_id' => $resourceId,
                        'resource_type' => $resourceType,
                        'action' => $action
                    ]);

                    try {
                        // Process the queued resource immediately
                        $this->processQueuedResource($resourceId, $resourceType, $action);
                    } catch (\Exception $e) {
                        // Log error but don't fail the queue operation
                        // The item is still queued and can be processed later via batch processing
                        $this->logger->err('SyncService: Auto-process failed, item remains queued for batch processing', [
                            'resource_id' => $resourceId,
                            'resource_type' => $resourceType,
                            'action' => $action,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                return true;
            } else {
                $this->logger->warn('SyncService: Failed to add resource to queue (no rows affected)', [
                    'resource_id' => $resourceId,
                    'resource_type' => $resourceType,
                    'action' => $action
                ]);
                return false;
            }

        } catch (\Exception $e) {
            $this->logger->err('SyncService: Failed to add resource to queue', [
                'resource_id' => $resourceId,
                'resource_type' => $resourceType,
                'action' => $action,
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException('Failed to add resource to queue: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Clear pending queue items after a successful reindex operation.
     *
     * Marks pending items in search_assistant_sync_queue as 'completed' after a reindex operation
     * has successfully processed them. This prevents stale "pending" statuses from appearing
     * on the dashboard after a full or partial reindex that bypasses the normal queue processing.
     *
     * The method supports filtering by:
     * - Resource type: Only clear pending items for specific resource types (items, media, item_sets)
     * - Timestamp: Only clear pending items created before the specified timestamp
     *
     * Important: This method does NOT affect 'failed' items - they remain for manual review.
     *
     * @param string|null $resourceType Optional resource type filter ('items', 'media', 'item_sets').
     *                                   If null, clears pending items for all resource types.
     * @param string|null $beforeTimestamp Optional timestamp filter (Y-m-d H:i:s format).
     *                                      If provided, only clears items created before this time.
     *                                      This prevents clearing items added during the reindex.
     * @return int Number of queue items marked as completed
     * @throws RuntimeException If database operation fails
     */
    public function clearPendingItemsAfterReindex(?string $resourceType = null, ?string $beforeTimestamp = null): int
    {
        $this->logger->info('SyncService: Clearing pending queue items after reindex', [
            'resource_type' => $resourceType ?? 'all',
            'before_timestamp' => $beforeTimestamp ?? 'none',
        ]);

        try {
            $affected = $this->queue->clearPendingBefore($resourceType, $beforeTimestamp);
            $this->logger->info('SyncService: Pending queue items cleared after reindex', [
                'affected_rows' => $affected,
                'resource_type' => $resourceType ?? 'all',
                'before_timestamp' => $beforeTimestamp ?? 'none',
            ]);
            return $affected;
        } catch (\Exception $e) {
            $this->logger->err('SyncService: Failed to clear pending queue items after reindex', [
                'resource_type' => $resourceType ?? 'all',
                'before_timestamp' => $beforeTimestamp ?? 'none',
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Failed to clear pending queue items: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get entity ID supporting both API representations and Doctrine entities.
     *
     * API representations use id() method while Doctrine entities use getId().
     * This helper method provides compatibility for both types.
     *
     * @param mixed $entity Omeka S entity (API representation or Doctrine entity)
     * @return int Entity ID
     * @throws InvalidArgumentException If entity doesn't have a valid ID method
     */
    private function getEntityId($entity): int
    {
        // Check for API representation (has id() method)
        if (method_exists($entity, 'id')) {
            return $entity->id();
        }

        // Check for Doctrine entity (has getId() method)
        if (method_exists($entity, 'getId')) {
            return $entity->getId();
        }

        throw new InvalidArgumentException('Entity must have id() or getId() method');
    }

    /**
     * Get entity resource name supporting both API representations and Doctrine entities.
     *
     * API representations have getResourceName() method, while Doctrine entities
     * must be identified by their class type using instanceof checks.
     *
     * @param mixed $entity Omeka S entity (API representation or Doctrine entity)
     * @return string Resource name ('items', 'media', or 'item_sets')
     * @throws InvalidArgumentException If entity type cannot be determined
     */
    private function getEntityResourceName($entity): string
    {
        // Check for API representation (has getResourceName() method)
        if (method_exists($entity, 'getResourceName')) {
            return $entity->getResourceName();
        }

        // Check for Doctrine entities using instanceof
        if ($entity instanceof \Omeka\Entity\Item) {
            return 'items';
        }

        if ($entity instanceof \Omeka\Entity\Media) {
            return 'media';
        }

        if ($entity instanceof \Omeka\Entity\ItemSet) {
            return 'item_sets';
        }

        throw new InvalidArgumentException('Unable to determine resource name for entity type: ' . get_class($entity));
    }
}
