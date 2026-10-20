<?php
declare(strict_types=1);
namespace SearchAssistantAI\Service\Embedding;

use SearchAssistantAI\Service\FileLogger;

use RuntimeException;
use InvalidArgumentException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

/**
 * Service implementation for converting text strings into vector embeddings.
 *
 * Provides methods for single and batch text-to-vector conversion via HTTP API,
 * with intelligent caching to minimize redundant API calls and optimize performance.
 */
class EmbeddingService implements EmbeddingInterface
{
    /**
     * @var Client Guzzle HTTP client for API requests
     */
    private $client;

    /**
     * @var string Base URL for embeddings API
     */
    private $baseUrl;

    /**
     * @var int Maximum number of retry attempts for network failures
     */
    private $maxRetries = 3;

    /**
     * @var list<int> Retry delays in seconds for exponential backoff (1s, 2s, 4s)
     */
    private $retryDelays = [1, 2, 4];

    /**
     * @var float Request timeout in seconds
     */
    private $timeout;

    /**
     * @var string Cache directory path for storing embeddings
     */
    private $cacheDir;

    /**
     * @var int Maximum character length for text input
     */
    private $maxTextLength = 5000;

    /**
     * @var int Expected vector dimension for embeddings
     */
    private $expectedDimension;

    /**
     * @var int Maximum batch size for embedBatch() method
     */
    private $maxBatchSize = 100;

    /**
     * @var int Number of texts to send per API request in embedBatch()
     */
    private $apiBatchSize = 10;

    /**
     * @var int Number of cache hits
     */
    private $cacheHits = 0;

    /**
     * @var int Number of cache misses
     */
    private $cacheMisses = 0;

    /**
     * @var array<string, bool> Already-warned truncation keys to avoid duplicate log entries
     */
    private $truncationWarned = [];

    /**
     * @var string Absolute request path for embedding calls
     */
    private $endpointPath;

    /**
     * @var string 'native' (POST /embed {text}) or 'openai' (POST /v1/embeddings {model, input})
     */
    private $apiFormat;

    /**
     * @var string Model name sent to OpenAI-compatible servers
     */
    private $model;

    /**
     * Constructor.
     *
     * @param string $baseUrl Embeddings service base URL (e.g., http://embeddings:8000)
     * @param float $timeout Request timeout in seconds (default: 30.0)
     * @param string $cacheDir Cache directory path (default: ./cache/embeddings)
     * @param int $expectedDimension Expected vector dimension
     * @param string|null $bearerToken Optional Authorization bearer token (hosted gateway mode)
     * @param string $endpointPath Request path for native embedding calls (hosted gateway uses /v1/embed)
     * @param string $apiFormat 'native' (POST $endpointPath) or 'openai' (POST /v1/embeddings)
     * @param string $model Model name, required by the OpenAI-compatible API
     */
    public function __construct(string $baseUrl = 'http://embeddings:8000', float $timeout = 30.0, string $cacheDir = './cache/embeddings', int $expectedDimension = 1024, ?string $bearerToken = null, string $endpointPath = '/embed', string $apiFormat = 'native', string $model = '')
    {
        $this->apiFormat = $apiFormat === 'openai' ? 'openai' : 'native';
        $this->model = $model;
        $this->baseUrl = rtrim($baseUrl, '/');
        if ($this->apiFormat === 'openai') {
            // Accept both "http://host:port" and "http://host:port/v1".
            $this->baseUrl = (string) preg_replace('#/v1$#', '', $this->baseUrl);
        }
        $this->timeout = $timeout;
        $this->cacheDir = $cacheDir;
        $this->expectedDimension = $expectedDimension;
        $this->endpointPath = $endpointPath;

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ];
        if ($bearerToken !== null && $bearerToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $bearerToken;
        }

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'headers' => $headers
        ]);

        // Create cache directory if it doesn't exist
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Convert a single text string to a vector embedding of the configured dimension.
     *
     * Applies text preprocessing (whitespace cleaning, UTF-8 validation, character limits)
     * before generating the embedding. Results are cached to avoid redundant API calls.
     * Long text (>5000 chars) is automatically truncated with a warning log.
     *
     * @param string $text Text to convert to vector (non-empty, auto-truncated if >5000 characters)
     * @return list<float> Array of floats representing the text embedding (length == configured dimension)
     * @throws \InvalidArgumentException If text is empty or has invalid UTF-8 encoding
     * @throws \RuntimeException If API call fails or returns invalid dimensions
     */
    public function embed(string $text): array
    {
        // Validate and preprocess input text
        $this->validateTextInput($text);
        $cleanText = $this->preprocessText($text);

        // Check cache first
        $cacheKey = $this->getCacheKey($cleanText);
        $cached = $this->getCachedEmbedding($cacheKey);
        if ($cached !== null) {
            $this->cacheHits++;
            return $cached;
        }

        // Cache miss - make API call with retry logic
        $this->cacheMisses++;
        $embeddings = $this->executeWithRetry(function () use ($cleanText) {
            return $this->requestEmbeddings([$cleanText], 'embed');
        });

        if (empty($embeddings)) {
            throw new RuntimeException('Expected embeddings array in response from embeddings service');
        }

        // Extract the first (and only) embedding
        $embedding = $embeddings[0];

        // Validate vector dimensions
        $this->validateVectorDimensions($embedding);

        // Cache the result
        $this->cacheEmbedding($cacheKey, $embedding);

        return $embedding;
    }

    /**
     * Convert multiple text strings to vectors in optimized batch API calls.
     *
     * Checks cache for each text first, then makes batch API calls (10 texts per request)
     * for uncached items only. Maintains original input order in output.
     * Long texts (>5000 chars) are automatically truncated with warning logs.
     *
     * @param list<string> $texts Array of text strings to convert (max 100 items)
     * @return list<list<float>> Array of embeddings, each of the configured dimension, indexed by original position
     * @throws \InvalidArgumentException If array is empty, exceeds batch size limit, or contains invalid UTF-8
     * @throws \RuntimeException If API call fails or returns invalid dimensions
     */
    public function embedBatch(array $texts): array
    {
        // Validate batch input
        if (empty($texts)) {
            throw new InvalidArgumentException('Texts array cannot be empty');
        }

        if (count($texts) > $this->maxBatchSize) {
            throw new InvalidArgumentException(
                "Batch size exceeds maximum limit of {$this->maxBatchSize} (got " . count($texts) . ")"
            );
        }

        $results = [];
        $uncachedTexts = [];
        $uncachedIndices = [];
        $processedTexts = []; // Keep track of preprocessed text for cache key mapping

        // Phase 1: Validate, preprocess, and check cache for each text
        foreach ($texts as $index => $text) {
            // Validate and preprocess
            $this->validateTextInput($text);
            $cleanText = $this->preprocessText($text);
            $processedTexts[$index] = $cleanText;

            // Check cache
            $cacheKey = $this->getCacheKey($cleanText);
            $cached = $this->getCachedEmbedding($cacheKey);

            if ($cached !== null) {
                // Cache hit - use cached embedding
                $this->cacheHits++;
                $results[$index] = $cached;
            } else {
                // Cache miss - track for batch API call
                $this->cacheMisses++;
                $uncachedTexts[] = $cleanText;
                $uncachedIndices[] = $index;
            }
        }

        // Phase 2: Batch API calls for uncached items (10 texts per request)
        if (!empty($uncachedTexts)) {
            // Chunk uncached texts into groups of $apiBatchSize
            $chunks = array_chunk($uncachedTexts, $this->apiBatchSize, true);
            $chunkIndices = array_chunk($uncachedIndices, $this->apiBatchSize, true);
            $allBatchEmbeddings = [];

            // Make API call for each chunk
            foreach (array_values($chunks) as $chunkIdx => $chunk) {
                $chunkEmbeddings = $this->executeWithRetry(function () use ($chunk) {
                    return $this->requestEmbeddings(array_values($chunk), 'embedBatch');
                });

                // Verify we got the correct number of embeddings for this chunk
                if (count($chunkEmbeddings) !== count($chunk)) {
                    throw new RuntimeException(
                        "Batch API returned incorrect number of embeddings for chunk {$chunkIdx}: expected " .
                        count($chunk) . ", got " . count($chunkEmbeddings)
                    );
                }

                // Append chunk embeddings to full results
                $allBatchEmbeddings = array_merge($allBatchEmbeddings, $chunkEmbeddings);
            }

            // Verify total embeddings count
            if (count($allBatchEmbeddings) !== count($uncachedTexts)) {
                throw new RuntimeException(
                    "Total batch embeddings mismatch: expected " .
                    count($uncachedTexts) . ", got " . count($allBatchEmbeddings)
                );
            }

            // Phase 3: Validate, cache, and store results
            foreach ($allBatchEmbeddings as $i => $embedding) {
                $originalIndex = $uncachedIndices[$i];

                // Validate dimensions
                $this->validateVectorDimensions($embedding);

                // Store in results array
                $results[$originalIndex] = $embedding;

                // Cache the embedding
                $cleanText = $processedTexts[$originalIndex];
                $cacheKey = $this->getCacheKey($cleanText);
                $this->cacheEmbedding($cacheKey, $embedding);
            }
        }

        // Phase 4: Sort results by original index to maintain order
        ksort($results);

        // Log cache statistics
        $hitRate = $this->getCacheHitRate();
        FileLogger::getInstance()->debug(
            "EmbeddingService: embedBatch() completed - " .
            "batch_size=" . count($texts) . ", " .
            "cache_hits={$this->cacheHits}, " .
            "cache_misses={$this->cacheMisses}, " .
            "cache_hit_rate={$hitRate}"
        );

        return $results;
    }

    /**
     * Call the embeddings API once for the given texts, in the configured format.
     *
     * Native:  POST $endpointPath {text: [...]} -> {embeddings: [[...], ...], dimensions: N, model: "..."}
     * OpenAI:  POST /v1/embeddings {model, input: [...]} -> {data: [{index, embedding: [...]}, ...]}
     *
     * @param list<string> $texts
     * @param string $operation Operation name for error logs
     * @return list<list<float>> One embedding per input text, in input order
     * @throws RuntimeException On HTTP errors or unexpected response structure
     */
    private function requestEmbeddings(array $texts, string $operation): array
    {
        $isOpenAi = $this->apiFormat === 'openai';
        if ($isOpenAi && trim($this->model) === '') {
            throw new RuntimeException('Embeddings model is required when using the OpenAI-compatible embeddings API');
        }

        try {
            $response = $isOpenAi
                ? $this->client->post('/v1/embeddings', ['json' => ['model' => $this->model, 'input' => $texts]])
                : $this->client->post($this->endpointPath, ['json' => ['text' => $texts]]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if ($data === null) {
                throw new RuntimeException('Invalid JSON response from embeddings service');
            }

            if (!$isOpenAi) {
                if (!isset($data['embeddings']) || !is_array($data['embeddings'])) {
                    throw new RuntimeException('Expected embeddings array in response from embeddings service');
                }
                return $data['embeddings'];
            }

            if (!isset($data['data']) || !is_array($data['data'])) {
                throw new RuntimeException('Expected data array in response from embeddings service');
            }

            // The spec allows items out of order; each one carries its input index.
            $items = $data['data'];
            usort($items, static function ($a, $b): int {
                return ($a['index'] ?? 0) <=> ($b['index'] ?? 0);
            });

            $embeddings = [];
            foreach ($items as $item) {
                if (!isset($item['embedding']) || !is_array($item['embedding'])) {
                    throw new RuntimeException('Expected embedding array in each data item from embeddings service');
                }
                $embeddings[] = $item['embedding'];
            }
            return $embeddings;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 429) {
                throw $e; // let executeWithRetry back off and retry
            }
            $message = $this->extractClientErrorMessage($e);
            throw new RuntimeException($message, 0, $e);
        } catch (ServerException $e) {
            $this->logServerError($e, $operation);
            throw new RuntimeException(
                'Embeddings service error (HTTP ' . $e->getResponse()->getStatusCode() . ')',
                0,
                $e
            );
        }
    }

    /**
     * Clear all cached embeddings.
     *
     * Forces subsequent embed calls for previously cached texts to trigger
     * new API requests. Useful for cache invalidation scenarios.
     *
     * @return void
     */
    public function clearCache(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        $files = glob($this->cacheDir . '/*.json');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Calculate cache hit rate as a percentage.
     *
     * @return string Cache hit rate formatted as percentage (e.g., "85.5%") or "N/A" if no cache operations yet
     */
    private function getCacheHitRate(): string
    {
        $total = $this->cacheHits + $this->cacheMisses;

        if ($total === 0) {
            return 'N/A';
        }

        $rate = ($this->cacheHits / $total) * 100;
        return number_format($rate, 1) . '%';
    }

    /**
     * Execute a callable with retry logic for network failures.
     *
     * Implements exponential backoff strategy with delays of 1s, 2s, 4s between retries.
     * Only retries on ConnectException (network failures), not on ClientException or ServerException.
     *
     * @param callable $operation The operation to execute (Guzzle HTTP request)
     * @return mixed The result of the operation
     * @throws RuntimeException If all retry attempts fail
     */
    private function executeWithRetry(callable $operation)
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                return $operation();
            } catch (ConnectException $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < $this->maxRetries) {
                    $delay = $this->retryDelays[$attempt - 1];
                    sleep($delay);
                }
            } catch (ClientException $e) {
                // Only 429 reaches here (other 4xx are converted to
                // RuntimeException inside the operation callbacks);
                // honour the gateway's Retry-After before retrying.
                $lastException = $e;
                $attempt++;

                if ($attempt < $this->maxRetries) {
                    $retryAfter = (int) $e->getResponse()->getHeaderLine('Retry-After');
                    sleep(min(max($retryAfter, $this->retryDelays[$attempt - 1]), 30));
                }
            }
        }

        throw new RuntimeException(
            "Embeddings request failed after {$this->maxRetries} attempts: " . $lastException->getMessage(),
            0,
            $lastException
        );
    }

    /**
     * Extract meaningful error message from ClientException (4xx errors).
     *
     * Parses the response body to get embeddings service error message, or provides
     * a generic fallback message if parsing fails.
     *
     * @param ClientException $exception The client exception to extract message from
     * @return string Meaningful error message
     */
    private function extractClientErrorMessage(ClientException $exception): string
    {
        try {
            $response = $exception->getResponse();
            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            // Try to extract error message from response
            if (isset($data['error']['message']) && is_string($data['error']['message'])) {
                return "Client error (HTTP {$statusCode}): {$data['error']['message']}";
            }

            if (isset($data['error'])) {
                $error = is_array($data['error']) ? json_encode($data['error']) : $data['error'];
                return "Client error (HTTP {$statusCode}): {$error}";
            }

            if (isset($data['detail'])) {
                $detail = is_array($data['detail']) ? json_encode($data['detail']) : $data['detail'];
                return "Client error (HTTP {$statusCode}): {$detail}";
            }

            if (isset($data['message'])) {
                $message = is_array($data['message']) ? json_encode($data['message']) : $data['message'];
                return "Client error (HTTP {$statusCode}): {$message}";
            }

            // Generic message if we can't parse the error
            return "Client error (HTTP {$statusCode}): Request failed";
        } catch (\Exception $e) {
            // If we fail to parse the error, return a generic message
            return "Client error: Request failed with HTTP " . $exception->getResponse()->getStatusCode();
        }
    }

    /**
     * Log server error with meaningful context.
     *
     * Logs server errors (5xx) without exposing internal embeddings service error details.
     *
     * @param ServerException $exception The server exception to log
     * @param string $operation The operation that failed (e.g., 'embed')
     * @return void
     */
    private function logServerError(ServerException $exception, string $operation): void
    {
        $statusCode = $exception->getResponse()->getStatusCode();
        FileLogger::getInstance()->error("Embeddings {$operation} failed: Embeddings service error (HTTP {$statusCode})");
    }

    /**
     * Validate text input is non-empty.
     *
     * @param string $text Text to validate
     * @return void
     * @throws InvalidArgumentException If text is empty
     */
    private function validateTextInput(string $text): void
    {
        $trimmedText = trim($text);

        if (empty($trimmedText)) {
            throw new InvalidArgumentException('Text input cannot be empty');
        }
    }

    /**
     * Preprocess text by cleaning whitespace, ensuring UTF-8 encoding, and enforcing length limits.
     *
     * Handles edge cases:
     * - Truncates text exceeding maxTextLength with warning log
     * - Cleans or rejects invalid UTF-8 encoding
     * - Normalizes excessive whitespace
     *
     * @param string $text Text to preprocess
     * @return string Cleaned text
     * @throws InvalidArgumentException If UTF-8 encoding cannot be fixed
     */
    private function preprocessText(string $text): string
    {
        // Ensure UTF-8 encoding first (before length calculation)
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Try to fix invalid UTF-8 by converting from common encodings
            $fixed = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');

            // If that didn't work, try using iconv to strip invalid sequences
            if (!mb_check_encoding($fixed, 'UTF-8')) {
                $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            }

            // If still invalid, reject with descriptive error
            if ($fixed === false || !mb_check_encoding($fixed, 'UTF-8')) {
                throw new InvalidArgumentException(
                    'Text contains invalid UTF-8 encoding that cannot be cleaned. Please provide valid UTF-8 text.'
                );
            }

            $text = $fixed;
            FileLogger::getInstance()->warning('EmbeddingService: Fixed invalid UTF-8 encoding in input text');
        }

        // Remove excessive whitespace (multiple spaces, tabs, newlines)
        $cleaned = preg_replace('/\s+/', ' ', $text);

        // Trim leading/trailing whitespace
        $cleaned = trim($cleaned);

        // Truncate if exceeds maximum length (warn once per unique text)
        if (mb_strlen($cleaned) > $this->maxTextLength) {
            $originalLength = mb_strlen($cleaned);
            $cleaned = mb_substr($cleaned, 0, $this->maxTextLength);
            $warnKey = md5($cleaned);
            if (!isset($this->truncationWarned[$warnKey])) {
                $this->truncationWarned[$warnKey] = true;
                FileLogger::getInstance()->warning(
                    "EmbeddingService: Text truncated from {$originalLength} to {$this->maxTextLength} characters"
                );
            }
        }

        return $cleaned;
    }

    /**
     * Validate vector dimensions match the configured dimension.
     *
     * @param list<float|int> $vector Vector to validate
     * @return void
     * @throws RuntimeException If vector dimension doesn't match expected dimension
     */
    private function validateVectorDimensions(array $vector): void
    {
        $actualDimension = count($vector);

        if ($actualDimension !== $this->expectedDimension) {
            throw new RuntimeException(
                "Invalid embedding dimension: expected {$this->expectedDimension}, got {$actualDimension}"
            );
        }

        // Validate all values are numeric
        foreach ($vector as $index => $value) {
            if (!is_numeric($value)) {
                throw new RuntimeException(
                    "Vector contains non-numeric value at index {$index}: " . var_export($value, true)
                );
            }
        }
    }

    /**
     * Generate cache key from text using MD5 hash.
     *
     * The expected dimension is part of the key so entries produced by a
     * different embedding backend (e.g. after switching direct → hosted
     * gateway) can never be served: a 1024-dim vector cached under the
     * old model must not satisfy a 2560-dim lookup.
     *
     * @param string $text Text to generate cache key from
     * @return string Cache key
     */
    private function getCacheKey(string $text): string
    {
        // Scope OpenAI-format entries by model too, so switching models never
        // returns vectors computed by another one. Native keys are unchanged.
        return $this->apiFormat === 'openai'
            ? md5($this->expectedDimension . '|' . $this->model . "\0" . $text)
            : md5($this->expectedDimension . '|' . $text);
    }

    /**
     * Retrieve cached embedding for given cache key.
     *
     * @param string $cacheKey Cache key to look up
     * @return list<float>|null Cached embedding array or null if not found
     */
    private function getCachedEmbedding(string $cacheKey): ?array
    {
        $cacheFile = $this->cacheDir . '/' . $cacheKey . '.json';

        if (!file_exists($cacheFile)) {
            return null;
        }

        $content = file_get_contents($cacheFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if ($data === null || !is_array($data)) {
            return null;
        }

        // Stale-entry guard: a cached vector of the wrong dimension is
        // treated as a miss so it gets re-embedded, never upserted.
        if (count($data) !== $this->expectedDimension) {
            return null;
        }

        return $data;
    }

    /**
     * Cache embedding for given cache key.
     *
     * @param string $cacheKey Cache key
     * @param list<float> $embedding Embedding array to cache
     * @return void
     */
    private function cacheEmbedding(string $cacheKey, array $embedding): void
    {
        $cacheFile = $this->cacheDir . '/' . $cacheKey . '.json';
        file_put_contents($cacheFile, json_encode($embedding));
    }
}
