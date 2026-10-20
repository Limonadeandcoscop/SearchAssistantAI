<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Search;

/**
 * Interface for semantic search operations using vector embeddings.
 *
 * Orchestrates text-to-vector conversion, vector similarity search,
 * metadata filtering, and contextual ranking to enable natural language
 * search across museum collections, items, and media.
 */
interface SemanticSearchInterface
{
    /**
     * Execute semantic search using natural language query.
     *
     * Converts text query to vector embedding, performs similarity search
     * in the vector store, applies metadata filters, and ranks results with contextual
     * scoring (recency, completeness, popularity).
     *
     * @param string $query Natural language search query (non-empty, max 5000 chars)
     * @param array<string, mixed> $filters Metadata filters (type, date_range, visibility, author, subject)
     * @param int $limit Maximum number of results to return (default: 10, max: 100)
     * @param int $offset Result offset for pagination (default: 0)
     * @return list<array<string, mixed>> Array of search results with id, score, and metadata payload, sorted by relevance descending
     * @throws \InvalidArgumentException If query is empty, exceeds limit, or filters are invalid
     * @throws \RuntimeException If embeddings service or vector-store connection fails
     */
    public function search(string $query, array $filters = [], int $limit = 10, int $offset = 0): array;

    /**
     * Execute vector similarity search with pre-computed embedding.
     *
     * Bypasses text-to-vector conversion for direct vector search.
     * Useful when embedding is already computed or for programmatic queries.
     *
     * @param list<float> $vector Query vector for similarity matching (length must match the configured embedding dimension)
     * @param array<string, mixed> $filters Metadata filters (type, date_range, visibility, author, subject)
     * @param int $limit Maximum number of results to return (default: 10, max: 100)
     * @param int $offset Result offset for pagination (default: 0)
     * @return list<array<string, mixed>> Array of search results with id, score, and metadata payload, sorted by score descending
     * @throws \InvalidArgumentException If vector dimensions invalid (does not match the configured dimension) or filters malformed
     * @throws \RuntimeException If vector-store connection fails or search execution error
     */
    public function searchByVector(array $vector, array $filters = [], int $limit = 10, int $offset = 0): array;

    /**
     * Execute hybrid search combining semantic vector search with keyword matching.
     *
     * Performs parallel vector search and keyword-based payload filtering,
     * then fuses results using Reciprocal Rank Fusion (RRF) algorithm.
     * Provides better recall for queries with specific terminology.
     *
     * @param string $query Natural language search query (non-empty, max 5000 chars)
     * @param list<string> $keywords Array of keyword strings for exact/fuzzy matching in metadata
     * @param array<string, mixed> $filters Metadata filters applied to both search paths
     * @param int $limit Maximum number of results after fusion (default: 10, max: 100)
     * @return list<array<string, mixed>> Deduplicated array of search results ranked by combined RRF score
     * @throws \InvalidArgumentException If query empty or keywords array empty/invalid
     * @throws \RuntimeException If embeddings service or vector-store connection fails
     */
    public function hybridSearch(string $query, array $keywords, array $filters = [], int $limit = 10): array;
}
