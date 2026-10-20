<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Indexing;

/**
 * Interface for indexing Omeka S resources into vector store.
 *
 * Provides methods for transforming Omeka S resources (Items, Media, Collections)
 * into vector embeddings with rich metadata payloads for semantic search capabilities.
 */
interface VectorIndexInterface
{
    /**
     * Index a single Item resource into the vector store.
     *
     * Extracts metadata, generates composite text optimized for embeddings,
     * converts text to vector via embeddings API, and stores in the vector store with
     * complete payload including ID, type, title, URL, and all properties.
     *
     * @param mixed $item Omeka S Item entity to index
     * @return bool True if item indexed successfully, false otherwise
     * @throws \InvalidArgumentException If item is invalid or missing required fields
     * @throws \RuntimeException If embeddings API or vector-store storage fails
     */
    public function indexItem($item): bool;

    /**
     * Index a single Media resource into the vector store.
     *
     * Handles media-specific metadata extraction including parent item relationships,
     * generates composite text, and stores vector with media-specific payload.
     *
     * @param mixed $media Omeka S Media entity to index
     * @return bool True if media indexed successfully, false otherwise
     * @throws \InvalidArgumentException If media is invalid or missing required fields
     * @throws \RuntimeException If embeddings API or vector-store storage fails
     */
    public function indexMedia($media): bool;

    /**
     * Index a single Collection (ItemSet) resource into the vector store.
     *
     * Extracts collection metadata including items count and relationships,
     * handles empty collections gracefully, and stores with collection-specific payload.
     *
     * @param mixed $collection Omeka S ItemSet entity to index
     * @return bool True if collection indexed successfully, false otherwise
     * @throws \InvalidArgumentException If collection is invalid or missing required fields
     * @throws \RuntimeException If embeddings API or vector-store storage fails
     */
    public function indexCollection($collection): bool;

    /**
     * Batch index all resources of a specific type with progress tracking.
     *
     * Processes resources in configurable batches (default 100), implements memory
     * management with EntityManager clearing and garbage collection, logs progress
     * every batch, and continues on individual failures.
     *
     * @param string|null $resourceType Fully qualified class name (Item::class, Media::class, ItemSet::class), or null for items
     * @param int $batchSize Number of resources to process per batch (default: 100)
     * @return void
     * @throws \InvalidArgumentException If resourceType is invalid or batchSize is non-positive
     * @throws \RuntimeException If batch processing encounters unrecoverable error
     */
    public function indexAll(?string $resourceType = null, int $batchSize = 100): void;

    /**
     * Reindex all resource types sequentially (Items, Media, Collections).
     *
     * Performs complete reindexing of entire Omeka S repository with comprehensive
     * logging, memory management, and progress tracking. Uses upsert operations
     * to ensure idempotent reindexing without duplicates.
     *
     * @param int $batchSize Number of resources to process per batch (default: 100)
     * @return void
     * @throws \RuntimeException If reindexing encounters unrecoverable error
     */
    public function reindexAll(int $batchSize = 100): void;

    /**
     * Delete a vector point from the vector store collection by point ID.
     *
     * Removes the vector representation of an entity from the vector-store collection.
     * The operation is idempotent - if the point doesn't exist (404), it's treated
     * as success. This prevents errors when deleting already-deleted entities.
     *
     * @param int $pointId The vector-store point ID (entity ID from Omeka S)
     * @return bool True if deletion succeeded or point didn't exist, false otherwise
     * @throws \RuntimeException If vector-store API returns server error (5xx)
     */
    public function deleteVector(int $pointId): bool;
}
