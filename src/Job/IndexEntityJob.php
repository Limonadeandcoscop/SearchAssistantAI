<?php
declare(strict_types=1);
namespace SearchAssistantAI\Job;

use Omeka\Job\AbstractJob;

/**
 * Background job for asynchronous vector indexing of a single Omeka S entity.
 *
 * This job handles individual entity indexing operations in the background,
 * preventing PHP timeouts for complex entities with many properties or media
 * attachments. Dispatched by SyncService when entity complexity exceeds
 * synchronous processing thresholds.
 *
 * Job Arguments:
 * - entity_id (int, required): ID of the entity to index
 * - entity_type (string, required): Resource type ('items', 'media', 'item_sets')
 *
 * Usage:
 * <code>
 * $dispatcher = $serviceLocator->get('Omeka\Job\Dispatcher');
 * $dispatcher->dispatch(\SearchAssistantAI\Job\IndexEntityJob::class, [
 *     'entity_id' => 123,
 *     'entity_type' => 'items',
 * ]);
 * </code>
 */
class IndexEntityJob extends AbstractJob
{
    /**
     * Perform the entity indexing job.
     *
     * This method is called by Omeka S job runner. It retrieves the entity
     * from the database using EntityManager, validates it exists, and delegates
     * vector indexing to VectorIndexService based on entity type.
     *
     * The job gracefully handles missing entities (deleted before job execution)
     * by logging a warning and completing successfully. This prevents job failures
     * for race conditions where entities are deleted immediately after creation.
     *
     * @throws \Exception If indexing fails or service dependencies are missing
     */
    public function perform(): void
    {
        // Get service locator
        $services = $this->getServiceLocator();

        // Get required services
        $vectorIndexService = $services->get('SearchAssistantAI\Service\Indexing\VectorIndexService');
        $logger = $services->get('Omeka\Logger');
        $entityManager = $services->get('Omeka\EntityManager');

        // Get job arguments
        $entityId = $this->getArg('entity_id');
        $entityType = $this->getArg('entity_type');

        // Validate arguments
        if (!$entityId || !$entityType) {
            $errorMsg = sprintf(
                '[IndexEntityJob] Missing required arguments - Entity ID: %s, Entity Type: %s',
                $entityId ?? 'null',
                $entityType ?? 'null'
            );
            $logger->err($errorMsg);
            throw new \InvalidArgumentException($errorMsg);
        }

        // Log job start
        $logger->info(sprintf(
            '[IndexEntityJob] Starting entity indexing - Entity Type: %s, Entity ID: %d, Timestamp: %s',
            $entityType,
            $entityId,
            date('c')
        ));

        try {
            // Check if job should stop before starting (user cancellation)
            if ($this->shouldStop()) {
                $logger->info('[IndexEntityJob] Job cancelled before starting');
                return;
            }

            // Load entity from database
            $entity = $this->loadEntity($entityManager, $entityId, $entityType);

            // Handle entity not found (may have been deleted before job execution)
            if (!$entity) {
                $logger->warn(sprintf(
                    '[IndexEntityJob] Entity not found (may have been deleted) - Entity Type: %s, Entity ID: %d',
                    $entityType,
                    $entityId
                ));
                // Mark job as complete - this is not an error condition
                return;
            }

            // Index entity based on type
            $this->indexEntity($vectorIndexService, $logger, $entity, $entityType);

            // Log job completion
            $logger->info(sprintf(
                '[IndexEntityJob] Entity indexing completed successfully - Entity Type: %s, Entity ID: %d, Timestamp: %s',
                $entityType,
                $entityId,
                date('c')
            ));

        } catch (\Exception $e) {
            // Log error and re-throw to mark job as failed
            $logger->err(sprintf(
                '[IndexEntityJob] Entity indexing failed - Entity Type: %s, Entity ID: %d, Error: %s, Timestamp: %s',
                $entityType,
                $entityId,
                $e->getMessage(),
                date('c')
            ));
            throw $e;
        }
    }

    /**
     * Load entity from database by type and ID.
     *
     * @param \Doctrine\ORM\EntityManager $entityManager Doctrine entity manager
     * @param int $entityId Entity ID
     * @param string $entityType Resource type ('items', 'media', 'item_sets')
     * @return object|null Entity object or null if not found
     * @throws \InvalidArgumentException If entity type is invalid
     */
    private function loadEntity($entityManager, $entityId, $entityType)
    {
        $entityClass = $this->getEntityClass($entityType);

        if (!$entityClass) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid entity type: %s. Expected one of: items, media, item_sets',
                $entityType
            ));
        }

        // @phpstan-ignore-next-line Doctrine 2.x find() template can't infer dynamic class-string
        return $entityManager->find($entityClass, $entityId);
    }

    /**
     * Index entity using VectorIndexService.
     *
     * Delegates to appropriate VectorIndexService method based on entity type.
     * Each entity type has a dedicated indexing method that handles entity-specific
     * metadata extraction and vector generation.
     *
     * @param \SearchAssistantAI\Service\Indexing\VectorIndexInterface $vectorIndexService Vector index service
     * @param \Laminas\Log\Logger $logger Logger instance
     * @param object $entity Entity object
     * @param string $entityType Resource type
     * @throws \RuntimeException If entity type is unsupported or indexing fails
     */
    private function indexEntity($vectorIndexService, $logger, $entity, $entityType): void
    {
        $logger->debug(sprintf(
            '[IndexEntityJob] Indexing entity - Type: %s, ID: %d',
            $entityType,
            $entity->getId()
        ));

        switch ($entityType) {
            case 'items':
                $vectorIndexService->indexItem($entity);
                break;

            case 'media':
                $vectorIndexService->indexMedia($entity);
                break;

            case 'item_sets':
                $vectorIndexService->indexCollection($entity);
                break;

            default:
                throw new \RuntimeException(sprintf(
                    'Unsupported entity type for indexing: %s',
                    $entityType
                ));
        }

        $logger->debug(sprintf(
            '[IndexEntityJob] Entity indexed successfully - Type: %s, ID: %d',
            $entityType,
            $entity->getId()
        ));
    }

    /**
     * Get entity class name from resource type string.
     *
     * Maps Omeka S resource type strings to Doctrine entity class names.
     * Supports standard Omeka S resource types: items, media, and item_sets.
     *
     * @param string $entityType Resource type string
     * @return string|null Entity class name or null if invalid
     */
    private function getEntityClass($entityType)
    {
        $typeMap = [
            'items' => \Omeka\Entity\Item::class,
            'media' => \Omeka\Entity\Media::class,
            'item_sets' => \Omeka\Entity\ItemSet::class,
        ];

        return $typeMap[strtolower($entityType)] ?? null;
    }
}
