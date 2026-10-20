<?php
declare(strict_types=1);
namespace SearchAssistantAI\Service\Search;

use Laminas\Log\LoggerInterface;
use RuntimeException;
use InvalidArgumentException;

use SearchAssistantAI\Service\Embedding\EmbeddingInterface;
use SearchAssistantAI\Service\Embedding\EmbeddingService;
use SearchAssistantAI\Service\VectorStore\SearchFilter;
use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;
/**
 * Service implementation for semantic search operations using vector embeddings.
 *
 * Orchestrates text-to-vector conversion (via EmbeddingService), vector similarity
 * search (via the configured vector store), metadata filtering, and contextual ranking to enable
 * natural language search across museum collections, items, and media.
 *
 * Implements a 5-step search pipeline:
 * 1. Text-to-vector conversion via embeddings service
 * 2. Vector similarity search in the vector store with metadata filters
 * 3. Candidate retrieval with over-fetching (top 20 for better recall)
 * 4. Contextual scoring based on recency, completeness, popularity
 * 5. Final ranking and pagination
 */
class SemanticSearchService implements SemanticSearchInterface
{
    /**
     * @var VectorStoreInterface Vector store service for vector storage and retrieval
     */
    private $vectorStore;

    /**
     * @var EmbeddingInterface Embedding service for text-to-vector conversion
     */
    private $embeddingService;

    /**
     * @var LoggerInterface Laminas logger for search performance tracking
     */
    private $logger;

    /**
     * @var string Vector-store collection name for storing vectors
     */
    private $collectionName;

    /**
     * @var int Maximum number of retry attempts for search operations
     */
    private $maxRetries = 3;

    /**
     * @var list<int> Retry delays in seconds for exponential backoff (1s, 2s, 4s)
     */
    private $retryDelays = [1, 2, 4];

    /**
     * @var int Expected vector dimension (matches the active embeddings model)
     */
    private $expectedDimension;

    /**
     * @var int Candidate over-retrieval multiplier for better recall
     */
    private $candidateMultiplier = 2;

    /** @var ResultScorerInterface Post-retrieval re-ranker */
    private ResultScorerInterface $scoring;

    public function __construct(
        VectorStoreInterface $vectorStore,
        EmbeddingInterface $embeddingService,
        LoggerInterface $logger,
        string $collectionName = 'omeka_resources',
        int $expectedDimension = 1024,
        ?ResultScorerInterface $scoring = null
    ) {
        $this->vectorStore = $vectorStore;
        $this->embeddingService = $embeddingService;
        $this->logger = $logger;
        $this->collectionName = $collectionName;
        $this->expectedDimension = $expectedDimension;
        $this->scoring = $scoring ?? new IdentityScorer();
    }

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
    public function search(string $query, array $filters = [], int $limit = 10, int $offset = 0): array
    {
        $searchStartTime = microtime(true);

        // Validate query
        $this->validateQuery($query);

        // Validate filters
        $this->validateFilters($filters);

        // Validate limit and offset
        $this->validatePaginationParams($limit, $offset);

        $this->logger->info('Semantic search query received', [
            'query' => $query,
            'query_length' => strlen($query),
            'filters' => $filters,
            'limit' => $limit,
            'offset' => $offset
        ]);

        // Convert query to vector embedding with retry logic
        $embeddingStartTime = microtime(true);
        $vector = $this->executeWithRetry(function () use ($query, $embeddingStartTime) {
            try {
                $vector = $this->embeddingService->embed($query);
                $embeddingDuration = round((microtime(true) - $embeddingStartTime) * 1000, 2);

                $this->logger->info('Query embedding generated', [
                    'query_length' => strlen($query),
                    'vector_dimension' => count($vector),
                    'embedding_time_ms' => $embeddingDuration
                ]);

                return $vector;
            } catch (RuntimeException $e) {
                $embeddingDuration = round((microtime(true) - $embeddingStartTime) * 1000, 2);
                $this->logger->err('Embedding generation failed', [
                    'query' => $query,
                    'embedding_time_ms' => $embeddingDuration,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }, [
            'operation' => 'text-to-vector',
            'query_preview' => substr($query, 0, 100)
        ]);

        // Execute vector search with the generated embedding
        $results = $this->searchByVector($vector, $filters, $limit, $offset);

        $totalDuration = round((microtime(true) - $searchStartTime) * 1000, 2);
        $this->logger->info('Semantic search completed', [
            'query' => $query,
            'total_time_ms' => $totalDuration,
            'results_returned' => count($results)
        ]);

        return $results;
    }

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
    public function searchByVector(array $vector, array $filters = [], int $limit = 10, int $offset = 0): array
    {
        $searchStartTime = microtime(true);

        // Validate vector
        $this->validateVector($vector);

        // Validate filters
        $this->validateFilters($filters);

        // Validate limit and offset
        $this->validatePaginationParams($limit, $offset);

        $this->logger->info('Vector search initiated', [
            'vector_dimension' => count($vector),
            'filters' => $filters,
            'limit' => $limit,
            'offset' => $offset
        ]);

        // Calculate candidate limit for over-fetching (to support pagination with good recall)
        // We need to fetch (offset + limit) to have enough results after slicing
        $candidateLimit = ($offset + $limit) * $this->candidateMultiplier;

        $this->logger->debug('Candidate retrieval parameters', [
            'requested_limit' => $limit,
            'offset' => $offset,
            'candidate_limit' => $candidateLimit,
            'multiplier' => $this->candidateMultiplier
        ]);

        // Build the canonical filter from the public input; the backend
        // translates it into its own DSL internally.
        $searchFilter = SearchFilter::fromPublicInput($filters);

        // Execute vector search with retry logic.
        $backendStartTime = microtime(true);
        $results = $this->executeWithRetry(function () use ($vector, $candidateLimit, $searchFilter, $backendStartTime) {
            try {
                $searchResults = $this->vectorStore->search(
                    $this->collectionName,
                    $vector,
                    $candidateLimit,
                    null,
                    $searchFilter->isEmpty() ? null : $searchFilter
                );
                $backendDuration = round((microtime(true) - $backendStartTime) * 1000, 2);

                $this->logger->info('Vector search completed', [
                    'collection' => $this->collectionName,
                    'candidate_limit' => $candidateLimit,
                    'results_count' => count($searchResults),
                    'search_time_ms' => $backendDuration
                ]);

                return $searchResults;
            } catch (RuntimeException $e) {
                $backendDuration = round((microtime(true) - $backendStartTime) * 1000, 2);
                $this->logger->err('Vector search failed', [
                    'collection' => $this->collectionName,
                    'search_time_ms' => $backendDuration,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }, [
            'operation' => 'vector-search',
            'collection' => $this->collectionName
        ]);

        // Apply contextual scoring to re-rank results
        $scoredResults = $this->scoring->rank($results);

        $this->logger->debug('Contextual scoring applied', [
            'results_before_scoring' => count($results),
            'results_after_scoring' => count($scoredResults)
        ]);

        // Apply pagination: skip offset items and take limit items
        $paginatedResults = array_slice($scoredResults, $offset, $limit);

        $totalDuration = round((microtime(true) - $searchStartTime) * 1000, 2);
        $this->logger->info('Vector search completed', [
            'total_candidates' => count($results),
            'returned_results' => count($paginatedResults),
            'offset' => $offset,
            'limit' => $limit,
            'total_time_ms' => $totalDuration
        ]);

        return $paginatedResults;
    }

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
    public function hybridSearch(string $query, array $keywords, array $filters = [], int $limit = 10): array
    {
        $hybridStartTime = microtime(true);

        // Validate query
        $this->validateQuery($query);

        // Validate keywords
        $this->validateKeywords($keywords);

        // Validate filters
        $this->validateFilters($filters);

        // Validate limit
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Limit must be between 1 and 100');
        }

        $this->logger->info('Hybrid search query received', [
            'query' => $query,
            'query_length' => strlen($query),
            'keywords' => $keywords,
            'keywords_count' => count($keywords),
            'filters' => $filters,
            'limit' => $limit
        ]);

        // Step 1: Execute semantic vector search
        // Over-fetch to ensure good coverage (fetch more candidates for fusion)
        $candidateLimit = $limit * 3; // Fetch 3x limit for each search path

        $this->logger->debug('Executing vector search for hybrid', [
            'candidate_limit' => $candidateLimit
        ]);

        $vectorSearchStartTime = microtime(true);
        $vectorResults = $this->search($query, $filters, $candidateLimit, 0);
        $vectorSearchDuration = round((microtime(true) - $vectorSearchStartTime) * 1000, 2);

        $this->logger->info('Hybrid vector search phase completed', [
            'results_count' => count($vectorResults),
            'search_time_ms' => $vectorSearchDuration
        ]);

        // Step 2: Execute keyword-based search
        $this->logger->debug('Executing keyword search for hybrid', [
            'keywords' => $keywords,
            'candidate_limit' => $candidateLimit
        ]);

        $keywordSearchStartTime = microtime(true);
        $keywordResults = $this->searchByKeywords($keywords, $filters, $candidateLimit);
        $keywordSearchDuration = round((microtime(true) - $keywordSearchStartTime) * 1000, 2);

        $this->logger->info('Hybrid keyword search phase completed', [
            'results_count' => count($keywordResults),
            'search_time_ms' => $keywordSearchDuration
        ]);

        // Step 3: Fuse results using Reciprocal Rank Fusion (RRF)
        $this->logger->debug('Fusing results with RRF', [
            'vector_results_count' => count($vectorResults),
            'keyword_results_count' => count($keywordResults)
        ]);

        $fusionStartTime = microtime(true);
        $fusedResults = $this->fuseResultsWithRRF($vectorResults, $keywordResults);
        $fusionDuration = round((microtime(true) - $fusionStartTime) * 1000, 2);

        $this->logger->debug('RRF fusion completed', [
            'fused_results_count' => count($fusedResults),
            'fusion_time_ms' => $fusionDuration
        ]);

        // Step 4: Apply final limit
        $finalResults = array_slice($fusedResults, 0, $limit);

        $totalDuration = round((microtime(true) - $hybridStartTime) * 1000, 2);
        $this->logger->info('Hybrid search completed', [
            'vector_candidates' => count($vectorResults),
            'keyword_candidates' => count($keywordResults),
            'fused_results' => count($fusedResults),
            'final_results' => count($finalResults),
            'total_time_ms' => $totalDuration,
            'breakdown' => [
                'vector_search_ms' => $vectorSearchDuration,
                'keyword_search_ms' => $keywordSearchDuration,
                'fusion_ms' => $fusionDuration
            ]
        ]);

        return $finalResults;
    }

    /**
     * Execute keyword-based search using vector-store payload filtering.
     *
     * Searches for documents where any of the keywords match in metadata fields
     * (title, description, author, subject, tags). Uses OR logic (should conditions).
     *
     * @param list<string> $keywords Array of keyword strings to match
     * @param array<string, mixed> $filters Additional metadata filters to apply
     * @param int $limit Maximum number of results to return
     * @return list<array<string, mixed>> Array of search results from keyword matching
     * @throws \RuntimeException If vector-store connection fails
     */
    private function searchByKeywords(array $keywords, array $filters = [], int $limit = 10): array
    {
        $keywordSearchStartTime = microtime(true);

        $this->logger->debug('Keyword search initiated', [
            'keywords' => $keywords,
            'keywords_count' => count($keywords),
            'filters' => $filters,
            'limit' => $limit
        ]);

        // Zero-vector is fine here: we're filtering on the payload, not
        // searching by similarity — the backend still needs a vector of
        // the right dimension to satisfy its query contract.
        $dummyVector = array_fill(0, $this->expectedDimension, 0.0);

        // Compose the canonical filter: user-supplied must-match/range
        // conditions plus an OR-group over the text fields we want to
        // match each keyword against.
        $searchFilter = SearchFilter::fromPublicInput($filters)
            ->withAnyText(['title', 'description', 'author', 'subject'], $keywords);

        $this->logger->debug('Built keyword filter', [
            'keywords_count' => count($keywords),
            'has_additional_filters' => !empty($filters),
        ]);

        $backendStartTime = microtime(true);
        $results = $this->executeWithRetry(function () use ($dummyVector, $limit, $searchFilter, $backendStartTime) {
            try {
                $searchResults = $this->vectorStore->search(
                    $this->collectionName,
                    $dummyVector,
                    $limit,
                    null,
                    $searchFilter->isEmpty() ? null : $searchFilter
                );
                $backendDuration = round((microtime(true) - $backendStartTime) * 1000, 2);

                $this->logger->info('Keyword search completed', [
                    'collection' => $this->collectionName,
                    'results_count' => count($searchResults),
                    'search_time_ms' => $backendDuration
                ]);

                return $searchResults;
            } catch (RuntimeException $e) {
                $backendDuration = round((microtime(true) - $backendStartTime) * 1000, 2);
                $this->logger->err('Keyword search failed', [
                    'collection' => $this->collectionName,
                    'search_time_ms' => $backendDuration,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }, [
            'operation' => 'keyword-search',
            'keywords' => implode(', ', array_slice($keywords, 0, 3))
        ]);

        $totalDuration = round((microtime(true) - $keywordSearchStartTime) * 1000, 2);
        $this->logger->debug('Keyword search operation completed', [
            'results_count' => count($results),
            'total_time_ms' => $totalDuration
        ]);

        return $results;
    }

    /**
     * Fuse vector and keyword search results using Reciprocal Rank Fusion (RRF).
     *
     * RRF algorithm combines rankings from multiple sources using the formula:
     * RRF_score(d) = sum over all rankings R where d appears: 1 / (k + rank_R(d))
     *
     * Where:
     * - k is a constant (60 by default, following standard RRF practice)
     * - rank_R(d) is the rank of document d in ranking R (starting from 1)
     *
     * Documents appearing in multiple rankings get higher scores, and top-ranked
     * documents contribute more to the final score.
     *
     * @param list<array<string, mixed>> $vectorResults Results from vector similarity search
     * @param list<array<string, mixed>> $keywordResults Results from keyword-based search
     * @return list<array<string, mixed>> Fused and deduplicated results sorted by RRF score (descending)
     */
    private function fuseResultsWithRRF(array $vectorResults, array $keywordResults): array
    {
        $k = 60; // Standard RRF constant
        $rrfScores = []; // ID => RRF score
        $documents = []; // ID => document data

        // Process vector search results
        foreach ($vectorResults as $rank => $result) {
            $id = $result['id'];
            $rankPosition = $rank + 1; // Ranks start from 1

            // Calculate RRF contribution: 1 / (k + rank)
            $rrfContribution = 1.0 / ($k + $rankPosition);

            // Initialize or add to existing score
            if (!isset($rrfScores[$id])) {
                $rrfScores[$id] = 0.0;
                $documents[$id] = $result;
            }
            $rrfScores[$id] += $rrfContribution;

            // Store source information for debugging
            if (!isset($documents[$id]['rrf_sources'])) {
                $documents[$id]['rrf_sources'] = [];
            }
            $documents[$id]['rrf_sources']['vector'] = [
                'rank' => $rankPosition,
                'contribution' => $rrfContribution,
                'original_score' => $result['score']
            ];
        }

        // Process keyword search results
        foreach ($keywordResults as $rank => $result) {
            $id = $result['id'];
            $rankPosition = $rank + 1; // Ranks start from 1

            // Calculate RRF contribution: 1 / (k + rank)
            $rrfContribution = 1.0 / ($k + $rankPosition);

            // Initialize or add to existing score
            if (!isset($rrfScores[$id])) {
                $rrfScores[$id] = 0.0;
                $documents[$id] = $result;
            }
            $rrfScores[$id] += $rrfContribution;

            // Store source information for debugging
            if (!isset($documents[$id]['rrf_sources'])) {
                $documents[$id]['rrf_sources'] = [];
            }
            $documents[$id]['rrf_sources']['keyword'] = [
                'rank' => $rankPosition,
                'contribution' => $rrfContribution,
                'original_score' => $result['score']
            ];
        }

        // Build final result set with RRF scores
        $fusedResults = [];
        foreach ($rrfScores as $id => $rrfScore) {
            $document = $documents[$id];
            $document['rrf_score'] = $rrfScore;
            $fusedResults[] = $document;
        }

        // Sort by RRF score (descending)
        usort($fusedResults, function ($a, $b) {
            return $b['rrf_score'] <=> $a['rrf_score'];
        });

        $this->logger->debug('RRF fusion completed', [
            'total_unique_documents' => count($fusedResults),
            'top_rrf_score' => $fusedResults[0]['rrf_score'] ?? null,
            'documents_in_both' => count(array_filter($fusedResults, function ($doc) {
                return isset($doc['rrf_sources']['vector']) && isset($doc['rrf_sources']['keyword']);
            }))
        ]);

        return $fusedResults;
    }

    /**
     * Execute a callable with retry logic for transient failures.
     *
     * Implements exponential backoff strategy with delays of 1s, 2s, 4s between retries.
     * Retries on RuntimeException (service failures), but not on InvalidArgumentException
     * (validation errors that won't be fixed by retrying).
     *
     * @param callable $operation The operation to execute
     * @param array<string, mixed> $context Context for error logging (e.g., query, operation type)
     * @return mixed The result of the operation
     * @throws RuntimeException If all retry attempts fail
     * @throws InvalidArgumentException If validation fails (not retried)
     */
    private function executeWithRetry(callable $operation, array $context = [])
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                return $operation();
            } catch (InvalidArgumentException $e) {
                // Don't retry validation errors - they won't be fixed by retrying
                throw $e;
            } catch (RuntimeException $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < $this->maxRetries) {
                    $delay = $this->retryDelays[$attempt - 1];
                    $this->logger->warn('Search operation failed, retrying', array_merge($context, [
                        'attempt' => $attempt,
                        'max_retries' => $this->maxRetries,
                        'retry_delay' => $delay,
                        'error' => $e->getMessage()
                    ]));
                    sleep($delay);
                }
            } catch (\Exception $e) {
                // Unexpected errors - log and wrap in RuntimeException
                $this->logger->err('Unexpected error during search operation', array_merge($context, [
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e)
                ]));
                throw new RuntimeException('Unexpected error during search: ' . $e->getMessage(), 0, $e);
            }
        }

        // All retries exhausted
        $this->logger->err('Search operation failed after all retry attempts', array_merge($context, [
            'max_retries' => $this->maxRetries,
            'final_error' => $lastException->getMessage()
        ]));

        throw new RuntimeException(
            "Failed to complete search operation after {$this->maxRetries} attempts: " . $lastException->getMessage(),
            0,
            $lastException
        );
    }

    /**
     * Validate search query is non-empty and within length limits.
     *
     * @param string $query Query string to validate
     * @return void
     * @throws InvalidArgumentException If query is empty or exceeds maximum length
     */
    private function validateQuery(string $query): void
    {
        if (empty(trim($query))) {
            throw new InvalidArgumentException('Search query cannot be empty');
        }

        if (strlen($query) > 5000) {
            throw new InvalidArgumentException('Search query exceeds maximum length of 5000 characters');
        }
    }

    /**
     * Validate vector contains the configured number of numeric values.
     *
     * @param list<float|int> $vector Vector array to validate
     * @return void
     * @throws InvalidArgumentException If vector dimension does not match the configured dimension or contains non-numeric values
     */
    private function validateVector(array $vector): void
    {
        $dimension = count($vector);

        if ($dimension !== $this->expectedDimension) {
            throw new InvalidArgumentException(
                "Vector dimension must be {$this->expectedDimension}, got {$dimension}"
            );
        }

        foreach ($vector as $index => $value) {
            if (!is_numeric($value)) {
                throw new InvalidArgumentException(
                    "Vector contains non-numeric value at index {$index}: " . var_export($value, true)
                );
            }
        }
    }

    /**
     * Validate metadata filters array structure.
     *
     * @param array<string, mixed> $filters Filters array to validate
     * @return void
     * @throws InvalidArgumentException If filters contain invalid keys or values
     */
    private function validateFilters(array $filters): void
    {
        $allowedFilters = ['type', 'date_range', 'visibility', 'author', 'subject', 'resource_type'];

        foreach ($filters as $key => $value) {
            if (!in_array($key, $allowedFilters)) {
                throw new InvalidArgumentException(
                    "Invalid filter key '{$key}'. Allowed filters: " . implode(', ', $allowedFilters)
                );
            }

            // Date range validation
            if ($key === 'date_range' && !is_array($value)) {
                throw new InvalidArgumentException('date_range filter must be an array with gte/lte keys');
            }
        }
    }

    /**
     * Validate pagination parameters are within acceptable ranges.
     *
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return void
     * @throws InvalidArgumentException If limit or offset are invalid
     */
    private function validatePaginationParams(int $limit, int $offset): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Limit must be between 1 and 100');
        }

        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must be non-negative');
        }
    }

    /**
     * Validate keywords array is non-empty and contains valid strings.
     *
     * @param list<string> $keywords Keywords array to validate
     * @return void
     * @throws InvalidArgumentException If keywords array is empty or contains invalid values
     */
    private function validateKeywords(array $keywords): void
    {
        if (empty($keywords)) {
            throw new InvalidArgumentException('Keywords array cannot be empty');
        }

        foreach ($keywords as $index => $keyword) {
            if (!is_string($keyword) || empty(trim($keyword))) {
                throw new InvalidArgumentException(
                    "Invalid keyword at index {$index}: must be non-empty string"
                );
            }
        }
    }

}
