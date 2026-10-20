<?php
declare(strict_types=1);
namespace SearchAssistantAI\Job;

use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;
use Omeka\Job\AbstractJob;

/**
 * Background job for asynchronous vector deletion of Omeka S entities from the vector store.
 *
 * This job handles individual entity vector deletions in the background,
 * ensuring that when entities are deleted from Omeka S, their corresponding
 * vectors are removed from the Qdrant collection. Dispatched by SyncService
 * during delete operations to maintain consistency between Omeka S and Qdrant.
 *
 * Job Arguments:
 * - entity_id (int, required): ID of the entity whose vector should be deleted
 * - entity_type (string, required): Resource type ('items', 'media', 'item_sets')
 * - collection_name (string, optional): Qdrant collection name (default: 'omeka_resources')
 *
 * Usage:
 * <code>
 * $dispatcher = $serviceLocator->get('Omeka\Job\Dispatcher');
 * $dispatcher->dispatch(\SearchAssistantAI\Job\DeleteEntityJob::class, [
 *     'entity_id' => 123,
 *     'entity_type' => 'items',
 *     'collection_name' => 'omeka_resources',
 * ]);
 * </code>
 */
class DeleteEntityJob extends AbstractJob
{
    /**
     * Perform the vector deletion job.
     *
     * This method is called by Omeka S job runner. It retrieves the QdrantService
     * from the service locator and deletes the vector point corresponding to the
     * entity ID from the specified Qdrant collection.
     *
     * The job is idempotent - if the vector point doesn't exist in Qdrant (already
     * deleted or never indexed), the job completes successfully without error.
     * This prevents job failures for race conditions or entities that were never
     * successfully indexed.
     *
     * @throws \Exception If deletion fails or service dependencies are missing
     */
    public function perform(): void
    {
        // Get service locator
        $services = $this->getServiceLocator();

        // Get required services
        $vectorStore = $services->get(VectorStoreInterface::class);
        $logger = $services->get('Omeka\Logger');

        // Get job arguments
        $entityId = $this->getArg('entity_id');
        $entityType = $this->getArg('entity_type');
        $collectionName = $this->getArg('collection_name', 'omeka_resources');

        // Validate arguments
        if (!$entityId || !$entityType) {
            $errorMsg = sprintf(
                '[DeleteEntityJob] Missing required arguments - Entity ID: %s, Entity Type: %s',
                $entityId ?? 'null',
                $entityType ?? 'null'
            );
            $logger->err($errorMsg);
            throw new \InvalidArgumentException($errorMsg);
        }

        // Log job start
        $logger->info(sprintf(
            '[DeleteEntityJob] Starting vector deletion - Entity Type: %s, Entity ID: %d, Collection: %s, Timestamp: %s',
            $entityType,
            $entityId,
            $collectionName,
            date('c')
        ));

        try {
            // Check if job should stop before starting (user cancellation)
            if ($this->shouldStop()) {
                $logger->info('[DeleteEntityJob] Job cancelled before starting');
                return;
            }

            // Delete vector point from Qdrant collection
            $this->deleteVectorPoint($vectorStore, $logger, $entityId, $entityType, $collectionName);

            // Log job completion
            $logger->info(sprintf(
                '[DeleteEntityJob] Vector deletion completed successfully - Entity Type: %s, Entity ID: %d, Collection: %s, Timestamp: %s',
                $entityType,
                $entityId,
                $collectionName,
                date('c')
            ));

        } catch (\Exception $e) {
            // Log error and re-throw to mark job as failed
            $logger->err(sprintf(
                '[DeleteEntityJob] Vector deletion failed - Entity Type: %s, Entity ID: %d, Collection: %s, Error: %s, Timestamp: %s',
                $entityType,
                $entityId,
                $collectionName,
                $e->getMessage(),
                date('c')
            ));
            throw $e;
        }
    }

    /**
     * Delete vector point from the vector store collection.
     *
     * Uses VectorStoreInterface::deletePoint() to remove the vector point.
     * The deletion is idempotent - if the point doesn't exist, the operation
     * completes successfully without error.
     *
     * @param VectorStoreInterface $vectorStore Vector store service instance
     * @param \Laminas\Log\Logger $logger Logger instance
     * @param int $entityId Entity ID (used as vector point ID)
     * @param string $entityType Resource type (for logging purposes)
     * @param string $collectionName Collection/index name
     * @throws \RuntimeException If deletion fails
     */
    private function deleteVectorPoint($vectorStore, $logger, $entityId, $entityType, $collectionName): void
    {
        $logger->debug(sprintf(
            '[DeleteEntityJob] Deleting vector point - Type: %s, ID: %d, Collection: %s',
            $entityType,
            $entityId,
            $collectionName
        ));

        try {
            $result = $vectorStore->deletePoint($collectionName, $entityId);

            if (!$result) {
                $logger->warn(sprintf(
                    '[DeleteEntityJob] Vector point may not exist or deletion failed - Type: %s, ID: %d (treating as success for idempotency)',
                    $entityType,
                    $entityId
                ));
            }

            $logger->debug(sprintf(
                '[DeleteEntityJob] Vector point deleted successfully - Type: %s, ID: %d, Collection: %s',
                $entityType,
                $entityId,
                $collectionName
            ));

        } catch (\Exception $e) {
            // Check if error is "point not found" (404) - treat as success for idempotency
            if (strpos($e->getMessage(), '404') !== false || strpos($e->getMessage(), 'not found') !== false) {
                $logger->info(sprintf(
                    '[DeleteEntityJob] Vector point not found (already deleted or never indexed) - Type: %s, ID: %d',
                    $entityType,
                    $entityId
                ));
                return;
            }

            throw new \RuntimeException(sprintf(
                'Failed to delete vector point: %s',
                $e->getMessage()
            ), 0, $e);
        }
    }
}
