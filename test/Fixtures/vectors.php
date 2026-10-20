<?php
/**
 * Test fixtures for embedding vectors.
 *
 * Provides reusable mock embedding vector data for unit tests.
 * All vectors are 384-dimensional, matching the output of
 * sentence-transformers/all-MiniLM-L6-v2 model.
 *
 * Usage:
 *   $fixtures = require __DIR__ . '/vectors.php';
 *   $vector = $fixtures['sample_vector'];
 */

return [
    /**
     * Sample embedding vector with uniform values.
     *
     * A 384-dimension vector filled with 0.5 values.
     * Useful for basic testing and mock responses.
     */
    'sample_vector' => array_fill(0, 384, 0.5),

    /**
     * Zero vector.
     *
     * A 384-dimension vector filled with zeros.
     * Useful for testing edge cases and null embeddings.
     */
    'zero_vector' => array_fill(0, 384, 0.0),

    /**
     * Unit vector.
     *
     * A 384-dimension vector filled with ones.
     * Useful for testing normalization and scaling operations.
     */
    'unit_vector' => array_fill(0, 384, 1.0),

    /**
     * Small value vector.
     *
     * A 384-dimension vector with very small positive values.
     * Useful for testing numerical precision and underflow handling.
     */
    'small_value_vector' => array_fill(0, 384, 0.001),

    /**
     * Large value vector.
     *
     * A 384-dimension vector with large values.
     * Useful for testing numerical limits and overflow handling.
     */
    'large_value_vector' => array_fill(0, 384, 100.0),

    /**
     * Negative value vector.
     *
     * A 384-dimension vector with negative values.
     * Useful for testing signed embeddings and distance calculations.
     */
    'negative_vector' => array_fill(0, 384, -0.5),

    /**
     * Mixed value vector.
     *
     * A 384-dimension vector with alternating positive and negative values.
     * Useful for testing complex similarity calculations.
     */
    'mixed_vector' => array_map(function($i) {
        return ($i % 2 === 0) ? 0.5 : -0.5;
    }, range(0, 383)),

    /**
     * Realistic embedding vector.
     *
     * A 384-dimension vector with pseudo-random values simulating
     * a real sentence-transformers output distribution.
     * Values range from -1.0 to 1.0 with a normal-like distribution.
     */
    'realistic_vector' => array_map(function($i) {
        // Generate pseudo-random values using sine function for reproducibility
        $value = sin($i * 0.1) * 0.8 + cos($i * 0.05) * 0.2;
        return round($value, 6);
    }, range(0, 383)),

    /**
     * Batch of sample vectors.
     *
     * Array of three 384-dimension vectors for testing batch operations.
     * Each vector has a distinct fill value for easy identification.
     */
    'batch_vectors' => [
        array_fill(0, 384, 0.3),
        array_fill(0, 384, 0.6),
        array_fill(0, 384, 0.9)
    ],

    /**
     * API response structure with embeddings.
     *
     * Complete API response format matching the embeddings service output.
     * Includes embeddings array, dimensions, and model information.
     */
    'api_response_single' => [
        'embeddings' => [array_fill(0, 384, 0.5)],
        'dimensions' => 384,
        'model' => 'sentence-transformers/all-MiniLM-L6-v2'
    ],

    /**
     * API response with batch embeddings.
     *
     * Complete API response for batch embedding requests.
     */
    'api_response_batch' => [
        'embeddings' => [
            array_fill(0, 384, 0.3),
            array_fill(0, 384, 0.6),
            array_fill(0, 384, 0.9)
        ],
        'dimensions' => 384,
        'model' => 'sentence-transformers/all-MiniLM-L6-v2'
    ]
];
