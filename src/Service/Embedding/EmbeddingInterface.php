<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Embedding;

/**
 * Interface for converting text strings into vector embeddings.
 *
 * Provides methods for single and batch text-to-vector conversion via HTTP API,
 * with intelligent caching to minimize redundant API calls and optimize performance.
 */
interface EmbeddingInterface
{
    /**
     * Convert a single text string to a vector embedding of the configured dimension.
     *
     * Applies text preprocessing (whitespace cleaning, UTF-8 validation, character limits)
     * before generating the embedding. Results are cached to avoid redundant API calls.
     *
     * @param string $text Text to convert to vector (non-empty, max 5000 characters)
     * @return list<float> Array of floats representing the text embedding (length == configured dimension)
     * @throws \InvalidArgumentException If text is empty or exceeds character limit
     * @throws \RuntimeException If API call fails or returns invalid dimensions
     */
    public function embed(string $text): array;

    /**
     * Convert multiple text strings to vectors in a single optimized API call.
     *
     * Checks cache for each text first, then makes a single batch API call
     * for uncached items only. Maintains original input order in output.
     *
     * @param list<string> $texts Array of text strings to convert (max 100 items)
     * @return list<list<float>> Array of embeddings, each of the configured dimension, indexed by original position
     * @throws \InvalidArgumentException If array is empty or exceeds batch size limit
     * @throws \RuntimeException If API call fails or returns invalid dimensions
     */
    public function embedBatch(array $texts): array;

    /**
     * Clear all cached embeddings.
     *
     * Forces subsequent embed calls for previously cached texts to trigger
     * new API requests. Useful for cache invalidation scenarios.
     *
     * @return void
     */
    public function clearCache(): void;
}
