<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\VectorStore;

/**
 * Generic interface for vector store backends.
 *
 * Provides a backend-agnostic contract for vector database operations
 * including collection management, vector CRUD, similarity search,
 * and index optimization. Implementations include QdrantService and
 * ElasticsearchService.
 */
interface VectorStoreInterface
{
    /**
     * Create a new collection/index with specified vector dimensions.
     *
     * @param string $name Name of the collection to create
     * @param int $dimension Vector dimension size (size must match the embedding model in use)
     * @param string $distance Distance metric: Cosine, Dot, or Euclid (default: Cosine)
     * @return bool True if collection created successfully, false otherwise
     */
    public function createCollection(string $name, int $dimension, string $distance = 'Cosine'): bool;

    /**
     * Delete a collection/index.
     *
     * @param string $collection Name of the collection to delete
     * @return bool True if collection deleted successfully, false if not found
     */
    public function deleteCollection(string $collection): bool;

    /**
     * Retrieve collection metadata and status information.
     *
     * @param string $collection Name of the collection
     * @return array<string, mixed>|null Collection info array with status and metadata, or null if not found
     */
    public function getCollectionInfo(string $collection): ?array;

    /**
     * Insert or update a single vector point with associated payload metadata.
     *
     * @param string $collection Name of the collection
     * @param array<string, mixed> $point Point data with keys: id, vector (array), payload (array)
     * @return bool True if point upserted successfully, false otherwise
     */
    public function upsertPoint(string $collection, array $point): bool;

    /**
     * Insert or update multiple vector points in a single batch operation.
     *
     * @param string $collection Name of the collection
     * @param list<array<string, mixed>> $points Array of point data, each with keys: id, vector (array), payload (array)
     * @return bool True if points upserted successfully, false otherwise
     */
    public function bulkUpsert(string $collection, array $points): bool;

    /**
     * Execute vector-based similarity search.
     *
     * @param string $collection Name of the collection to search
     * @param list<float> $vector Query vector for similarity matching
     * @param int $limit Maximum number of results to return (default: 10)
     * @param float|null $scoreThreshold Minimum similarity score threshold (optional)
     * @param SearchFilter|null $filter Canonical backend-agnostic filter;
     *        each implementation translates it internally via its
     *        matching *FilterBuilder. Omit or pass null for unfiltered search.
     * @return list<array<string, mixed>> Array of search results with id, score, and payload fields, sorted by score descending
     */
    public function search(string $collection, array $vector, int $limit = 10, float $scoreThreshold = null, ?SearchFilter $filter = null): array;

    /**
     * Optimize collection index for improved search performance.
     *
     * @param string $collection Name of the collection to optimize
     * @return bool True if optimization started successfully, false otherwise
     */
    public function optimizeCollection(string $collection): bool;

    /**
     * Delete a single vector point from a collection.
     *
     * Idempotent: returns true if point was deleted or did not exist.
     *
     * @param string $collection Name of the collection
     * @param int $pointId Point ID to delete
     * @return bool True if deletion succeeded or point didn't exist, false on error
     */
    public function deletePoint(string $collection, int $pointId): bool;

    /**
     * Count points in a collection, optionally filtered by payload field values.
     *
     * @param string $collection Name of the collection
     * @param array<string, mixed> $filter Optional payload filter (e.g., ['type' => 'item'])
     * @return int Number of matching points, or 0 on error
     */
    public function countPoints(string $collection, array $filter = []): int;
}
