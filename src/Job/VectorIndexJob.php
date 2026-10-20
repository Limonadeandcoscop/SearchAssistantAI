<?php
declare(strict_types=1);
namespace SearchAssistantAI\Job;

use Omeka\Job\AbstractJob;

/**
 * Background job for asynchronous vector indexing of Omeka S resources.
 *
 * This job handles large-scale indexing operations by running them in the
 * background, preventing PHP timeouts and allowing progress tracking through
 * the Omeka S admin interface. Supports indexing specific resource types
 * or all resources with configurable batch sizes.
 *
 * Job Arguments:
 * - resource_type (string, optional): Resource type to index ('item', 'media', 'collection', or null for all)
 * - batch_size (int, optional): Number of resources to process per batch (default: 100)
 * - resource_ids (array, optional): Specific resource IDs to index (if not provided, indexes all)
 * - reindex (bool, optional): Whether to reindex existing resources (default: true)
 *
 * Usage:
 * <code>
 * $dispatcher = $serviceLocator->get('Omeka\Job\Dispatcher');
 * $dispatcher->dispatch(\SearchAssistantAI\Job\VectorIndexJob::class, [
 *     'resource_type' => 'item',
 *     'batch_size' => 100,
 * ]);
 * </code>
 */
class VectorIndexJob extends AbstractJob
{
    /**
     * Perform the indexing job.
     *
     * This method is called by Omeka S job runner. It retrieves the VectorIndexService
     * from the service locator and delegates indexing operations based on job arguments.
     *
     * The job supports three modes:
     * 1. Index specific resources by ID
     * 2. Index all resources of a specific type
     * 3. Reindex all resources (all types)
     *
     * Progress is logged via Omeka's logger and the job can be monitored through
     * the admin interface.
     *
     * @throws \Exception If indexing fails or service dependencies are missing
     */
    public function perform(): void
    {
        // Get service locator
        $services = $this->getServiceLocator();

        // Get VectorIndexService from service manager
        $vectorIndexService = $services->get('SearchAssistantAI\Service\Indexing\VectorIndexService');

        // Get logger for job-specific logging
        $logger = $services->get('Omeka\Logger');

        // Get job arguments
        $resourceType = $this->getArg('resource_type');
        $batchSize = $this->getArg('batch_size', 100);
        $resourceIds = $this->getArg('resource_ids');
        $reindex = $this->getArg('reindex', true);

        // Log job start
        $logger->info(sprintf(
            '[VectorIndexJob] Starting indexing job - Resource Type: %s, Batch Size: %d, Reindex: %s, Timestamp: %s',
            $resourceType ?? 'all',
            $batchSize,
            $reindex ? 'true' : 'false',
            date('c')
        ));

        try {
            // Check if job should stop before starting (user cancellation)
            if ($this->shouldStop()) {
                $logger->info('[VectorIndexJob] Job cancelled before starting');
                return;
            }

            // Mode 1: Index specific resource IDs
            if ($resourceIds && is_array($resourceIds)) {
                $this->indexSpecificResources($vectorIndexService, $logger, $resourceIds, $resourceType);
            }
            // Mode 2: Index all resources of a specific type
            elseif ($resourceType) {
                $this->indexByType($vectorIndexService, $logger, $resourceType, $batchSize);
            }
            // Mode 3: Reindex all resources (all types)
            else {
                $this->reindexAll($vectorIndexService, $logger, $batchSize);
            }

            // Log job completion
            $logger->info(sprintf(
                '[VectorIndexJob] Indexing job completed successfully - Timestamp: %s',
                date('c')
            ));

        } catch (\Exception $e) {
            // Log error and re-throw to mark job as failed
            $logger->err(sprintf(
                '[VectorIndexJob] Indexing job failed - Error: %s, Timestamp: %s',
                $e->getMessage(),
                date('c')
            ));
            throw $e;
        }
    }

    /**
     * Index specific resources by their IDs.
     *
     * @param \SearchAssistantAI\Service\Indexing\VectorIndexInterface $vectorIndexService Vector index service
     * @param \Laminas\Log\Logger $logger Logger instance
     * @param list<int> $resourceIds Array of resource IDs to index
     * @param string|null $resourceType Resource type ('item', 'media', 'collection')
     * @throws \Exception If indexing fails
     */
    private function indexSpecificResources($vectorIndexService, $logger, array $resourceIds, $resourceType = null): void
    {
        $logger->info(sprintf(
            '[VectorIndexJob] Indexing %d specific resources - Type: %s',
            count($resourceIds),
            $resourceType ?? 'auto-detect'
        ));

        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');

        $successCount = 0;
        $failureCount = 0;

        foreach ($resourceIds as $index => $resourceId) {
            // Check if job should stop (user cancellation)
            if ($this->shouldStop()) {
                $logger->info(sprintf(
                    '[VectorIndexJob] Job cancelled - Processed %d/%d resources',
                    $index,
                    count($resourceIds)
                ));
                return;
            }

            try {
                // Determine resource type if not specified
                $type = $resourceType;
                if (!$type) {
                    // Auto-detect resource type by attempting to load entity
                    $resource = $this->loadResource($entityManager, $resourceId);
                    if (!$resource) {
                        $logger->warn(sprintf('[VectorIndexJob] Resource not found: %d', $resourceId));
                        $failureCount++;
                        continue;
                    }
                    $type = $this->detectResourceType($resource);
                }

                // Index based on type
                switch ($type) {
                    case 'item':
                        $item = $entityManager->find(\Omeka\Entity\Item::class, $resourceId);
                        if ($item) {
                            $vectorIndexService->indexItem($item);
                            $successCount++;
                        } else {
                            $failureCount++;
                        }
                        break;

                    case 'media':
                        $media = $entityManager->find(\Omeka\Entity\Media::class, $resourceId);
                        if ($media) {
                            $vectorIndexService->indexMedia($media);
                            $successCount++;
                        } else {
                            $failureCount++;
                        }
                        break;

                    case 'collection':
                        $collection = $entityManager->find(\Omeka\Entity\ItemSet::class, $resourceId);
                        if ($collection) {
                            $vectorIndexService->indexCollection($collection);
                            $successCount++;
                        } else {
                            $failureCount++;
                        }
                        break;

                    default:
                        $logger->warn(sprintf('[VectorIndexJob] Unknown resource type: %s for ID: %d', $type, $resourceId));
                        $failureCount++;
                }

            } catch (\Exception $e) {
                $logger->err(sprintf(
                    '[VectorIndexJob] Failed to index resource %d - Error: %s',
                    $resourceId,
                    $e->getMessage()
                ));
                $failureCount++;
            }

            // Log progress every 100 resources
            if (($index + 1) % 100 === 0) {
                $logger->info(sprintf(
                    '[VectorIndexJob] Progress: %d/%d resources processed - Success: %d, Failures: %d',
                    $index + 1,
                    count($resourceIds),
                    $successCount,
                    $failureCount
                ));
            }
        }

        $logger->info(sprintf(
            '[VectorIndexJob] Specific resource indexing completed - Total: %d, Success: %d, Failures: %d',
            count($resourceIds),
            $successCount,
            $failureCount
        ));
    }

    /**
     * Index all resources of a specific type.
     *
     * @param \SearchAssistantAI\Service\Indexing\VectorIndexInterface $vectorIndexService Vector index service
     * @param \Laminas\Log\Logger $logger Logger instance
     * @param string $resourceType Resource type ('item', 'media', 'collection')
     * @param int $batchSize Number of resources to process per batch
     * @throws \Exception If indexing fails
     */
    private function indexByType($vectorIndexService, $logger, $resourceType, $batchSize): void
    {
        $logger->info(sprintf(
            '[VectorIndexJob] Indexing all resources of type: %s with batch size: %d',
            $resourceType,
            $batchSize
        ));

        // Map resource type to entity class
        $entityClass = $this->getEntityClass($resourceType);

        if (!$entityClass) {
            throw new \InvalidArgumentException(sprintf('Invalid resource type: %s', $resourceType));
        }

        // Delegate to VectorIndexService->indexAll()
        $vectorIndexService->indexAll($entityClass, $batchSize);

        $logger->info(sprintf('[VectorIndexJob] Completed indexing for type: %s', $resourceType));
    }

    /**
     * Reindex all resources (all types).
     *
     * @param \SearchAssistantAI\Service\Indexing\VectorIndexInterface $vectorIndexService Vector index service
     * @param \Laminas\Log\Logger $logger Logger instance
     * @param int $batchSize Number of resources to process per batch
     * @throws \Exception If indexing fails
     */
    private function reindexAll($vectorIndexService, $logger, $batchSize): void
    {
        $logger->info(sprintf(
            '[VectorIndexJob] Starting full reindex of all resource types with batch size: %d',
            $batchSize
        ));

        // Delegate to VectorIndexService->reindexAll()
        $vectorIndexService->reindexAll($batchSize);

        $logger->info('[VectorIndexJob] Full reindex completed for all resource types');
    }

    /**
     * Load a resource entity by ID (tries Item, Media, ItemSet).
     *
     * @param \Doctrine\ORM\EntityManager $entityManager Entity manager
     * @param int $resourceId Resource ID
     * @return object|null Resource entity or null if not found
     */
    private function loadResource($entityManager, $resourceId)
    {
        // Try Item first
        $resource = $entityManager->find(\Omeka\Entity\Item::class, $resourceId);
        if ($resource) {
            return $resource;
        }

        // Try Media
        $resource = $entityManager->find(\Omeka\Entity\Media::class, $resourceId);
        if ($resource) {
            return $resource;
        }

        // Try ItemSet
        $resource = $entityManager->find(\Omeka\Entity\ItemSet::class, $resourceId);
        if ($resource) {
            return $resource;
        }

        return null;
    }

    /**
     * Detect the resource type from an entity instance.
     *
     * @param object $resource Resource entity
     * @return string Resource type ('item', 'media', 'collection')
     * @throws \InvalidArgumentException If resource type cannot be detected
     */
    private function detectResourceType($resource)
    {
        if ($resource instanceof \Omeka\Entity\Item) {
            return 'item';
        }
        if ($resource instanceof \Omeka\Entity\Media) {
            return 'media';
        }
        if ($resource instanceof \Omeka\Entity\ItemSet) {
            return 'collection';
        }

        throw new \InvalidArgumentException('Unable to detect resource type');
    }

    /**
     * Get entity class name from resource type string.
     *
     * @param string $resourceType Resource type ('item', 'media', 'collection')
     * @return string|null Entity class name or null if invalid
     */
    private function getEntityClass($resourceType)
    {
        $typeMap = [
            'item' => \Omeka\Entity\Item::class,
            'media' => \Omeka\Entity\Media::class,
            'collection' => \Omeka\Entity\ItemSet::class,
            'itemset' => \Omeka\Entity\ItemSet::class,
        ];

        return $typeMap[strtolower($resourceType)] ?? null;
    }
}
