<?php
declare(strict_types=1);
namespace SearchAssistantAI\Job;

use Omeka\Job\AbstractJob;
use SearchAssistantAI\Service\FileLogger;

/**
 * Background job for manual reindexing of Omeka S resources with advanced options.
 *
 * This job provides comprehensive reindexing capabilities with granular control over
 * resource types, scope (complete/incremental/specific), and progress tracking.
 * Designed to handle large datasets (100k+ items) through batched processing with
 * regular cancellation checks and progress updates.
 *
 * Job Arguments:
 * - resource_type (string, optional): Resource type to reindex ('items', 'media', 'item_sets', or null for all)
 * - scope (string, required): Reindexing scope ('complete', 'incremental', 'specific')
 * - resource_ids (array, optional): Specific resource IDs for 'specific' scope
 * - batch_size (int, optional): Number of resources per batch (default: 100)
 * - delete_existing (bool, optional): Delete existing index entries before reindexing (default: false for incremental, true for complete)
 *
 * Usage:
 * <code>
 * $dispatcher = $serviceLocator->get('Omeka\Job\Dispatcher');
 * $dispatcher->dispatch(\SearchAssistantAI\Job\ReindexJob::class, [
 *     'resource_type' => 'items',
 *     'scope' => 'complete',
 *     'batch_size' => 100,
 * ]);
 * </code>
 */
class ReindexJob extends AbstractJob
{
    /**
     * Perform the reindexing job.
     *
     * This method is called by Omeka S job runner. It orchestrates the reindexing
     * process based on job arguments, handling different resource types and scopes.
     *
     * The job supports three scopes:
     * 1. Complete: Reindex all resources (optionally delete existing first)
     * 2. Incremental: Only index resources not yet in the vector index
     * 3. Specific: Reindex specific resource IDs provided in job arguments
     *
     * Progress is tracked via job arguments and logged for monitoring through the
     * admin interface. The job can be cancelled at any time via shouldStop() checks.
     *
     * @throws \Exception If reindexing fails or service dependencies are missing
     */
    public function perform(): void
    {
        // Set memory limit for large operations
        ini_set('memory_limit', '500M');

        // Capture job start time for timing tracking (high precision)
        $performStartTime = microtime(true);

        // Capture job start time for queue cleanup (only clear items created before this time)
        $jobStartTime = date('Y-m-d H:i:s');

        // Get service locator
        $services = $this->getServiceLocator();

        // Get required services
        $vectorIndexService = $services->get('SearchAssistantAI\Service\Indexing\VectorIndexService');
        $syncService = $services->get('SearchAssistantAI\Service\Indexing\SyncService');
        $logger = $services->get('Omeka\Logger');
        $entityManager = $services->get('Omeka\EntityManager');
        $api = $services->get('Omeka\ApiManager');

        // Stop any existing ReindexJob instances to ensure only one runs at a time
        $stoppedJobsCount = $this->stopExistingReindexJobs();
        if ($stoppedJobsCount > 0) {
            $logger->info(sprintf(
                '[ReindexJob] Stopped %d existing ReindexJob instance(s) - Timestamp: %s',
                $stoppedJobsCount,
                date('c')
            ));
        } else {
            $logger->info(sprintf(
                '[ReindexJob] No other ReindexJob instances running - Timestamp: %s',
                date('c')
            ));
        }

        // Get job arguments
        $resourceType = $this->getArg('resource_type');
        $scope = $this->getArg('scope', 'complete');
        $resourceIds = $this->getArg('resource_ids', []);
        $batchSize = $this->getArg('batch_size', 100);
        $deleteExisting = $this->getArg('delete_existing', $scope === 'complete');

        // Validate arguments
        if ($resourceType && !in_array($resourceType, ['items', 'media', 'item_sets'])) {
            $errorMsg = sprintf(
                '[ReindexJob] Invalid resource_type: %s. Expected: items, media, item_sets, or null for all',
                $resourceType
            );
            $logger->err($errorMsg);
            throw new \InvalidArgumentException($errorMsg);
        }

        if (!in_array($scope, ['complete', 'incremental', 'specific'])) {
            $errorMsg = sprintf(
                '[ReindexJob] Invalid scope: %s. Expected: complete, incremental, or specific',
                $scope
            );
            $logger->err($errorMsg);
            throw new \InvalidArgumentException($errorMsg);
        }

        // Validate specific scope has resource IDs
        if ($scope === 'specific' && empty($resourceIds)) {
            $errorMsg = '[ReindexJob] Specific scope requires resource_ids argument';
            $logger->err($errorMsg);
            throw new \InvalidArgumentException($errorMsg);
        }

        // Log job start
        $startMsg = sprintf(
            'ReindexJob: Starting - Resource Type: %s, Scope: %s, Batch Size: %d, Delete Existing: %s',
            $resourceType ?? 'all',
            $scope,
            $batchSize,
            $deleteExisting ? 'true' : 'false'
        );
        $logger->info('[ReindexJob] ' . $startMsg);
        FileLogger::getInstance()->info($startMsg);

        try {
            // Check if job should stop before starting (user cancellation)
            if ($this->shouldStop()) {
                $logger->info('[ReindexJob] Job cancelled before starting');
                return;
            }

            // Initialize progress tracking
            $this->updateProgress(0, 'Initializing reindexing job...', 0, null, 0);

            // Refresh configuration to ensure latest settings from database
            $vectorIndexService->refreshConfiguration();
            $logger->info('[ReindexJob] Refreshed VectorIndexService configuration from database');

            // Execute reindexing based on scope
            $reindexResult = ['global_processed' => 0, 'global_errors' => 0, 'global_skipped' => 0];
            switch ($scope) {
                case 'specific':
                    $reindexResult = $this->reindexSpecificResources(
                        $vectorIndexService,
                        $logger,
                        $entityManager,
                        $resourceIds,
                        $resourceType,
                        $batchSize
                    );
                    break;

                case 'incremental':
                    $reindexResult = $this->reindexIncremental(
                        $vectorIndexService,
                        $logger,
                        $api,
                        $entityManager,
                        $resourceType,
                        $batchSize
                    );
                    break;

                case 'complete':
                default:
                    $reindexResult = $this->reindexComplete(
                        $vectorIndexService,
                        $logger,
                        $api,
                        $entityManager,
                        $resourceType,
                        $batchSize,
                        $deleteExisting
                    );
                    break;
            }

            $totalProcessed = $reindexResult['global_processed'];
            $totalErrors = $reindexResult['global_errors'];
            $totalSkipped = $reindexResult['global_skipped'];
            $totalIndexed = $totalProcessed - $totalErrors - $totalSkipped;

            // Mark complete with actual error/skip counts
            $completionMessage = sprintf(
                'Reindexing completed: %d indexed, %d errors, %d skipped',
                $totalIndexed,
                $totalErrors,
                $totalSkipped
            );
            $this->updateProgress(100, $completionMessage, $totalProcessed, null, $totalErrors);

            // Calculate elapsed time
            $elapsedSeconds = microtime(true) - $performStartTime;
            $elapsedFormatted = $this->formatElapsedTime($elapsedSeconds);

            // Log job completion with timing and actual counts
            $completeMsg = sprintf(
                'ReindexJob: COMPLETED - Indexed: %d, Errors: %d, Skipped: %d, Resource Type: %s, Scope: %s, Elapsed: %s',
                $totalIndexed,
                $totalErrors,
                $totalSkipped,
                $resourceType ?? 'all',
                $scope,
                $elapsedFormatted
            );
            $logger->info('[ReindexJob] ' . $completeMsg);
            FileLogger::getInstance()->info($completeMsg);

            // Warn if nothing was indexed
            if ($totalIndexed === 0 && $totalProcessed > 0) {
                $warnMsg = sprintf(
                    'ReindexJob: WARNING - No items were successfully indexed! Processed: %d, Errors: %d, Skipped: %d. Check Elasticsearch connectivity and collection filter settings.',
                    $totalProcessed,
                    $totalErrors,
                    $totalSkipped
                );
                $logger->err('[ReindexJob] ' . $warnMsg);
                FileLogger::getInstance()->error($warnMsg);
            }

            // Clear pending queue items that were superseded by this reindex
            try {
                $clearedCount = $syncService->clearPendingItemsAfterReindex($resourceType, $jobStartTime);
                $logger->info(sprintf(
                    '[ReindexJob] Cleared %d pending queue items after reindex - Resource Type: %s',
                    $clearedCount,
                    $resourceType ?? 'all'
                ));
            } catch (\Exception $cleanupException) {
                // Log cleanup failure but don't fail the job (reindexing already succeeded)
                $logger->warn(sprintf(
                    '[ReindexJob] Failed to clear pending queue items - Error: %s',
                    $cleanupException->getMessage()
                ));
            }

        } catch (\Exception $e) {
            // Calculate elapsed time
            $elapsedSeconds = microtime(true) - $performStartTime;
            $elapsedFormatted = $this->formatElapsedTime($elapsedSeconds);

            // Log error with timing and re-throw to mark job as failed
            $failMsg = sprintf(
                'ReindexJob: FAILED - Error: %s, Elapsed: %s',
                $e->getMessage(),
                $elapsedFormatted
            );
            $logger->err('[ReindexJob] ' . $failMsg);
            FileLogger::getInstance()->error($failMsg);
            throw $e;
        }
    }

    /**
     * Reindex specific resources by their IDs.
     *
     * @param \SearchAssistantAI\Service\Indexing\VectorIndexInterface $vectorIndexService Vector index service
     * @param \Laminas\Log\Logger $logger Logger instance
     * @param \Doctrine\ORM\EntityManager $entityManager Doctrine entity manager
     * @param list<int> $resourceIds Array of resource IDs to reindex
     * @param string|null $resourceType Resource type ('items', 'media', 'item_sets')
     * @param int $batchSize Number of resources per batch
     * @return array{global_processed: int, global_errors: int, global_skipped: int}
     * @throws \Exception If reindexing fails
     */
    private function reindexSpecificResources($vectorIndexService, $logger, $entityManager, array $resourceIds, $resourceType = null, $batchSize = 100): array
    {
        $logger->info(sprintf(
            '[ReindexJob] Reindexing %d specific resources - Type: %s',
            count($resourceIds),
            $resourceType ?? 'auto-detect'
        ));

        $total = count($resourceIds);
        $processed = 0;
        $errorsCount = 0;
        $skippedCount = 0;

        foreach ($resourceIds as $resourceId) {
            // Check if job should stop (user cancellation)
            if ($this->shouldStop()) {
                $logger->info(sprintf(
                    '[ReindexJob] Job cancelled - Processed %d/%d resources',
                    $processed,
                    $total
                ));
                return ['global_processed' => $processed, 'global_errors' => $errorsCount, 'global_skipped' => $skippedCount];
            }

            try {
                // Load resource
                $resource = $this->loadResource($entityManager, $resourceId, $resourceType);

                if (!$resource) {
                    $logger->warn(sprintf('[ReindexJob] Resource not found: %d', $resourceId));
                    continue;
                }

                // Determine resource type if not specified
                $type = $resourceType ?? $this->detectResourceType($resource);

                // Index resource — returns false if skipped (e.g. collection filter)
                $result = $this->indexResource($vectorIndexService, $logger, $resource, $type);

                $processed++;

                if ($result === false) {
                    $skippedCount++;
                }

                // Update progress
                $percentage = ($processed / $total) * 100;
                $this->updateProgress($percentage, sprintf('Processed %d/%d resources', $processed, $total), $processed, $total, $errorsCount);

            } catch (\Exception $e) {
                $errorsCount++;
                $processed++;
                $errorMsg = sprintf(
                    'ReindexJob: Failed to reindex resource %d - %s',
                    $resourceId,
                    $e->getMessage()
                );
                $logger->err('[ReindexJob] ' . $errorMsg);
                FileLogger::getInstance()->error($errorMsg);
                // Continue processing other resources
            }
        }

        $specificCompleteMsg = sprintf(
            'ReindexJob: Specific reindexing complete - Processed: %d/%d, Indexed: %d, Errors: %d, Skipped: %d',
            $processed,
            $total,
            $processed - $errorsCount - $skippedCount,
            $errorsCount,
            $skippedCount
        );
        $logger->info('[ReindexJob] ' . $specificCompleteMsg);
        FileLogger::getInstance()->info($specificCompleteMsg);

        return ['global_processed' => $processed, 'global_errors' => $errorsCount, 'global_skipped' => $skippedCount];
    }

    /**
     * Perform incremental reindexing (new resources only).
     *
     * @param \SearchAssistantAI\Service\Indexing\VectorIndexInterface $vectorIndexService Vector index service
     * @param \Laminas\Log\Logger $logger Logger instance
     * @param \Omeka\Api\Manager $api API manager
     * @param \Doctrine\ORM\EntityManager $entityManager Doctrine entity manager
     * @param string|null $resourceType Resource type filter
     * @param int $batchSize Batch size for processing
     * @return array{global_processed: int, global_errors: int, global_skipped: int}
     * @throws \Exception If reindexing fails
     */
    private function reindexIncremental($vectorIndexService, $logger, $api, $entityManager, $resourceType = null, $batchSize = 100): array
    {
        $logger->info('[ReindexJob] Starting incremental reindexing');

        // Determine which resource types to process
        $types = $resourceType ? [$resourceType] : ['items', 'media', 'item_sets'];

        // Count total resources across all types for global progress
        $globalTotal = 0;
        $typeCounts = [];
        foreach ($types as $type) {
            $apiName = $this->getApiResourceName($type);
            $response = $api->search($apiName, [], ['returnScalar' => 'id']);
            $count = count($response->getContent());
            $typeCounts[$type] = $count;
            $globalTotal += $count;
        }

        $logger->info(sprintf('[ReindexJob] Total resources to process: %d', $globalTotal));

        $globalProcessed = 0;
        $globalErrors = 0;
        $globalSkipped = 0;

        foreach ($types as $type) {
            if ($this->shouldStop()) {
                $logger->info('[ReindexJob] Job cancelled during incremental reindexing');
                return ['global_processed' => $globalProcessed, 'global_errors' => $globalErrors, 'global_skipped' => $globalSkipped];
            }

            $result = $this->reindexResourceType($vectorIndexService, $logger, $api, $entityManager, $type, $batchSize, false, $globalProcessed, $globalTotal, $globalErrors, $globalSkipped);
            $globalProcessed = $result['global_processed'];
            $globalErrors = $result['global_errors'];
            $globalSkipped = $result['global_skipped'];
        }

        return ['global_processed' => $globalProcessed, 'global_errors' => $globalErrors, 'global_skipped' => $globalSkipped];
    }

    /**
     * Perform complete reindexing of all resources.
     *
     * @param \SearchAssistantAI\Service\Indexing\VectorIndexInterface $vectorIndexService Vector index service
     * @param \Laminas\Log\Logger $logger Logger instance
     * @param \Omeka\Api\Manager $api API manager
     * @param \Doctrine\ORM\EntityManager $entityManager Doctrine entity manager
     * @param string|null $resourceType Resource type filter
     * @param int $batchSize Batch size for processing
     * @param bool $deleteExisting Delete existing index entries first
     * @return array{global_processed: int, global_errors: int, global_skipped: int}
     * @throws \Exception If reindexing fails
     */
    private function reindexComplete($vectorIndexService, $logger, $api, $entityManager, $resourceType = null, $batchSize = 100, $deleteExisting = true): array
    {
        $logger->info(sprintf(
            '[ReindexJob] Starting complete reindexing - Delete Existing: %s',
            $deleteExisting ? 'true' : 'false'
        ));

        // Determine which resource types to process
        $types = $resourceType ? [$resourceType] : ['items', 'media', 'item_sets'];

        // Count total resources across all types for global progress
        $globalTotal = 0;
        $typeCounts = [];
        foreach ($types as $type) {
            $apiName = $this->getApiResourceName($type);
            $response = $api->search($apiName, [], ['returnScalar' => 'id']);
            $count = count($response->getContent());
            $typeCounts[$type] = $count;
            $globalTotal += $count;
        }

        $logger->info(sprintf('[ReindexJob] Total resources to process: %d', $globalTotal));

        $globalProcessed = 0;
        $globalErrors = 0;
        $globalSkipped = 0;

        foreach ($types as $type) {
            if ($this->shouldStop()) {
                $logger->info('[ReindexJob] Job cancelled during complete reindexing');
                return ['global_processed' => $globalProcessed, 'global_errors' => $globalErrors, 'global_skipped' => $globalSkipped];
            }

            $result = $this->reindexResourceType($vectorIndexService, $logger, $api, $entityManager, $type, $batchSize, $deleteExisting, $globalProcessed, $globalTotal, $globalErrors, $globalSkipped);
            $globalProcessed = $result['global_processed'];
            $globalErrors = $result['global_errors'];
            $globalSkipped = $result['global_skipped'];
        }

        return ['global_processed' => $globalProcessed, 'global_errors' => $globalErrors, 'global_skipped' => $globalSkipped];
    }

    /**
     * Reindex all resources of a specific type.
     *
     * @param \SearchAssistantAI\Service\Indexing\VectorIndexInterface $vectorIndexService Vector index service
     * @param \Laminas\Log\Logger $logger Logger instance
     * @param \Omeka\Api\Manager $api API manager
     * @param \Doctrine\ORM\EntityManager $entityManager Doctrine entity manager
     * @param string $resourceType Resource type ('items', 'media', 'item_sets')
     * @param int $batchSize Batch size for processing
     * @param bool $deleteExisting Delete existing index entries first
     * @param int $globalProcessed Number of resources already processed across previous types
     * @param int $globalTotal Total resources across all types
     * @param int $globalErrors Number of errors across previous types
     * @param int $globalSkipped Number of skipped resources across previous types
     * @return array{global_processed: int, global_errors: int, global_skipped: int} Updated global counters
     */
    private function reindexResourceType($vectorIndexService, $logger, $api, $entityManager, $resourceType, $batchSize = 100, $deleteExisting = false, $globalProcessed = 0, $globalTotal = 0, $globalErrors = 0, $globalSkipped = 0)
    {
        $logger->info(sprintf(
            '[ReindexJob] Reindexing resource type: %s (batch size: %d)',
            $resourceType,
            $batchSize
        ));

        // Get API resource name
        $apiResourceName = $this->getApiResourceName($resourceType);

        // Count total resources
        $response = $api->search($apiResourceName, [], ['returnScalar' => 'id']);
        $resourceIds = $response->getContent();
        $total = count($resourceIds);

        $logger->info(sprintf('[ReindexJob] Found %d resources of type %s', $total, $resourceType));

        if ($total === 0) {
            return ['global_processed' => $globalProcessed, 'global_errors' => $globalErrors, 'global_skipped' => $globalSkipped];
        }

        $processed = 0;
        $errorsCount = 0;
        $skippedCount = 0;
        $batchNumber = 0;
        $totalBatches = (int) ceil($total / $batchSize);

        // Process in batches
        for ($offset = 0; $offset < $total; $offset += $batchSize) {
            // Track batch timing
            $batchNumber++;
            $batchStartTime = microtime(true);
            $batchProcessed = 0;
            $batchErrors = 0;
            $batchSkipped = 0;

            // Check for cancellation
            if ($this->shouldStop()) {
                $logger->info(sprintf(
                    '[ReindexJob] Job cancelled - Processed %d/%d %s',
                    $processed,
                    $total,
                    $resourceType
                ));
                return ['global_processed' => $globalProcessed, 'global_errors' => $globalErrors, 'global_skipped' => $globalSkipped];
            }

            // Get batch of resource IDs
            $batchIds = array_slice($resourceIds, $offset, $batchSize);

            // Process batch
            foreach ($batchIds as $resourceId) {
                try {
                    // Load resource entity
                    $resource = $this->loadResource($entityManager, $resourceId, $resourceType);

                    if (!$resource) {
                        $logger->warn(sprintf('[ReindexJob] Resource not found: %d', $resourceId));
                        continue;
                    }

                    // Index resource — returns false if skipped (e.g. collection filter)
                    $result = $this->indexResource($vectorIndexService, $logger, $resource, $resourceType);

                    $processed++;
                    $batchProcessed++;
                    $globalProcessed++;

                    if ($result === false) {
                        $skippedCount++;
                        $batchSkipped++;
                        $globalSkipped++;
                    }

                } catch (\Exception $e) {
                    $processed++;
                    $errorsCount++;
                    $batchErrors++;
                    $globalProcessed++;
                    $globalErrors++;
                    $errorMsg = sprintf(
                        'ReindexJob: Failed to index %s %d - %s',
                        $resourceType,
                        $resourceId,
                        $e->getMessage()
                    );
                    $logger->err('[ReindexJob] ' . $errorMsg);
                    FileLogger::getInstance()->error($errorMsg);
                    // Continue processing
                }
            }

            // Calculate batch duration
            $batchDuration = microtime(true) - $batchStartTime;
            $batchDurationFormatted = $this->formatElapsedTime($batchDuration);

            // Log batch progress with overall percentage
            $globalPercentage = $globalTotal > 0 ? round(($globalProcessed / $globalTotal) * 100, 1) : 0;
            $batchMsg = sprintf(
                'ReindexJob: %s %d/%d (batch %d/%d in %s, %d errors, %d skipped) — Overall: %d/%d (%s%%)',
                $resourceType,
                $processed,
                $total,
                $batchNumber,
                $totalBatches,
                $batchDurationFormatted,
                $batchErrors,
                $batchSkipped,
                $globalProcessed,
                $globalTotal,
                $globalPercentage
            );
            $logger->info('[ReindexJob] ' . $batchMsg);
            FileLogger::getInstance()->info($batchMsg);

            // Update progress
            $this->updateProgress(
                $globalPercentage,
                sprintf('Processing %s: %d/%d — Overall: %d/%d (%s%%), %d errors, %d skipped', $resourceType, $processed, $total, $globalProcessed, $globalTotal, $globalPercentage, $globalErrors, $globalSkipped),
                $globalProcessed,
                $globalTotal,
                $globalErrors
            );

            // Clear entity manager to prevent memory issues
            // Save job ID before clear, then re-fetch after to keep it managed
            $jobId = $this->job->getId();
            $entityManager->clear();
            $this->job = $entityManager->find(\Omeka\Entity\Job::class, $jobId);
        }

        $typeCompleteMsg = sprintf(
            'ReindexJob: Completed %s - Processed: %d/%d, Indexed: %d, Errors: %d, Skipped: %d',
            $resourceType,
            $processed,
            $total,
            $processed - $errorsCount - $skippedCount,
            $errorsCount,
            $skippedCount
        );
        $logger->info('[ReindexJob] ' . $typeCompleteMsg);
        FileLogger::getInstance()->info($typeCompleteMsg);

        return ['global_processed' => $globalProcessed, 'global_errors' => $globalErrors, 'global_skipped' => $globalSkipped];
    }

    /**
     * Load resource from database by ID and type.
     *
     * @param \Doctrine\ORM\EntityManager $entityManager Doctrine entity manager
     * @param int $resourceId Resource ID
     * @param string|null $resourceType Resource type ('items', 'media', 'item_sets')
     * @return object|null Resource entity or null if not found
     */
    private function loadResource($entityManager, $resourceId, $resourceType = null)
    {
        if (!$resourceType) {
            // Auto-detect by trying each type
            $types = ['items', 'media', 'item_sets'];
            foreach ($types as $type) {
                $entityClass = $this->getEntityClass($type);
                // @phpstan-ignore-next-line Doctrine 2.x find() template can't infer dynamic class-string
                $resource = $entityManager->find($entityClass, $resourceId);
                if ($resource) {
                    return $resource;
                }
            }
            return null;
        }

        $entityClass = $this->getEntityClass($resourceType);
        // @phpstan-ignore-next-line Doctrine 2.x find() template can't infer dynamic class-string
        return $entityManager->find($entityClass, $resourceId);
    }

    /**
     * Index a resource using VectorIndexService.
     *
     * @param \SearchAssistantAI\Service\Indexing\VectorIndexInterface $vectorIndexService Vector index service
     * @param \Laminas\Log\Logger $logger Logger instance
     * @param object $resource Resource entity
     * @param string $resourceType Resource type
     * @throws \RuntimeException If resource type is unsupported
     */
    private function indexResource($vectorIndexService, $logger, $resource, $resourceType): bool
    {
        switch ($resourceType) {
            case 'items':
                return $vectorIndexService->indexItem($resource);

            case 'media':
                return $vectorIndexService->indexMedia($resource);

            case 'item_sets':
                return $vectorIndexService->indexCollection($resource);

            default:
                throw new \RuntimeException(sprintf(
                    'Unsupported resource type for indexing: %s',
                    $resourceType
                ));
        }
    }

    /**
     * Detect resource type from entity object.
     *
     * @param object $resource Resource entity
     * @return string Resource type ('items', 'media', 'item_sets')
     * @throws \RuntimeException If resource type cannot be detected
     */
    private function detectResourceType($resource)
    {
        $class = get_class($resource);

        if (strpos($class, 'Item') !== false && strpos($class, 'ItemSet') === false) {
            return 'items';
        } elseif (strpos($class, 'Media') !== false) {
            return 'media';
        } elseif (strpos($class, 'ItemSet') !== false) {
            return 'item_sets';
        }

        throw new \RuntimeException(sprintf(
            'Unable to detect resource type from class: %s',
            $class
        ));
    }

    /**
     * Get entity class name from resource type.
     *
     * @param string $resourceType Resource type ('items', 'media', 'item_sets')
     * @return string|null Entity class name or null if invalid
     */
    private function getEntityClass($resourceType)
    {
        switch ($resourceType) {
            case 'items':
                return \Omeka\Entity\Item::class;
            case 'media':
                return \Omeka\Entity\Media::class;
            case 'item_sets':
                return \Omeka\Entity\ItemSet::class;
            default:
                return null;
        }
    }

    /**
     * Get API resource name from resource type.
     *
     * @param string $resourceType Resource type ('items', 'media', 'item_sets')
     * @return string API resource name
     */
    private function getApiResourceName($resourceType)
    {
        // API resource names match the resource type
        return $resourceType;
    }

    /**
     * Update job progress tracking.
     *
     * Stores progress percentage and status message in job arguments for
     * retrieval by progress API endpoint. Persists changes to database
     * and unlocks session to prevent blocking.
     *
     * @param float $percentage Progress percentage (0-100)
     * @param string $message Status message
     * @param int|null $itemsProcessed Number of items processed so far
     * @param int|null $totalItems Total number of items to process
     * @param int $errorsCount Number of errors encountered
     */
    private function updateProgress($percentage, $message, $itemsProcessed = null, $totalItems = null, $errorsCount = 0): void
    {
        // Prevent job log from growing too large (cap at 1MB)
        // The log column is loaded into memory when viewing the jobs page
        $currentLog = $this->job->getLog();
        if ($currentLog !== null && strlen($currentLog) > 1048576) {
            // Keep the last 500KB of log to preserve recent context
            $this->job->setLog(
                "[Log truncated - exceeded 1MB limit]\n\n"
                . substr($currentLog, -512000)
            );
        }

        // Build progress data
        $progressData = [
            'percentage' => round($percentage, 2),
            'message' => $message,
            'timestamp' => date('c'),
        ];

        // Add item counts if provided
        if ($itemsProcessed !== null) {
            $progressData['items_processed'] = $itemsProcessed;
        }
        if ($totalItems !== null) {
            $progressData['total_items'] = $totalItems;
        }
        if ($errorsCount > 0) {
            $progressData['errors_count'] = $errorsCount;
        }

        // Update job arguments with progress data
        $this->job->setArgs([
            'progress' => $progressData
        ] + $this->job->getArgs());

        // Persist progress to database
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $entityManager->flush();

        // Unlock session to prevent blocking requests
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * Stop any existing ReindexJob instances that are currently running.
     *
     * This method ensures only one ReindexJob runs at a time by setting any
     * other active ReindexJob instances (STATUS_STARTING or STATUS_IN_PROGRESS)
     * to STATUS_STOPPING. Those jobs will gracefully exit at their next
     * shouldStop() check.
     *
     * @return int Number of jobs that were stopped
     */
    private function stopExistingReindexJobs()
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $jobRepository = $entityManager->getRepository(\Omeka\Entity\Job::class);

        // Query for active ReindexJob instances (excluding current job)
        $qb = $jobRepository->createQueryBuilder('j');
        $activeJobs = $qb
            ->where('j.class = :jobClass')
            ->andWhere('j.id != :currentJobId')
            ->andWhere($qb->expr()->in('j.status', ':activeStatuses'))
            ->setParameter('jobClass', self::class)
            ->setParameter('currentJobId', $this->job->getId())
            ->setParameter('activeStatuses', [
                \Omeka\Entity\Job::STATUS_STARTING,
                \Omeka\Entity\Job::STATUS_IN_PROGRESS,
            ])
            ->getQuery()
            ->getResult();

        // Set each active job to STOPPING status
        foreach ($activeJobs as $job) {
            $job->setStatus(\Omeka\Entity\Job::STATUS_STOPPING);
            $entityManager->persist($job);
        }

        // Flush changes to database
        if (count($activeJobs) > 0) {
            $entityManager->flush();
        }

        return count($activeJobs);
    }

    /**
     * Format elapsed time in a human-readable format.
     *
     * Converts seconds to a readable format like "1h 23m 45s" or "45.2s" for
     * short durations. This provides clear timing information in log messages.
     *
     * @param float $seconds Elapsed time in seconds (with microsecond precision)
     * @return string Formatted elapsed time string
     */
    private function formatElapsedTime($seconds)
    {
        if ($seconds < 60) {
            // Less than a minute: show seconds with one decimal
            return sprintf('%.1fs', $seconds);
        } elseif ($seconds < 3600) {
            // Less than an hour: show minutes and seconds
            $minutes = floor($seconds / 60);
            $remainingSeconds = $seconds - ($minutes * 60);
            return sprintf('%dm %ds', $minutes, (int) $remainingSeconds);
        } else {
            // An hour or more: show hours, minutes, and seconds
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            $remainingSeconds = $seconds % 60;
            return sprintf('%dh %dm %ds', $hours, $minutes, (int) $remainingSeconds);
        }
    }
}
