<?php
declare(strict_types=1);
namespace SearchAssistantAI\Service\Analytics;

use SearchAssistantAI\Service\FileLogger;
use RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;
/**
 * Service implementation for aggregating statistics from multiple sources.
 *
 * Provides methods for collecting health status and metrics from the vector store,
 * embeddings service, configured LLM, and database for admin dashboard display.
 */
class StatsService
{
    /**
     * @var VectorStoreInterface|null Vector store service for backend-agnostic stats
     */
    private $vectorStore;

    /**
     * @var string Active vector store backend ('qdrant' or 'elasticsearch')
     */
    private $vectorStoreBackend = 'qdrant';

    /**
     * @var string Collection/index name for the vector store
     */
    private $collectionName = 'omeka_resources';

    /**
     * @var Client Guzzle HTTP client for Qdrant API requests (legacy, used only for the Qdrant backend)
     */
    private $qdrantClient;

    /**
     * @var string Base URL for Qdrant API
     */
    private $qdrantBaseUrl;

    /**
     * @var string Base URL for embeddings service
     */
    private $embeddingsBaseUrl;

    /**
     * @var int Maximum number of retry attempts for network failures
     */
    private $maxRetries = 3;

    /**
     * @var list<int> Retry delays in seconds for exponential backoff (1s, 2s, 4s)
     */
    private $retryDelays = [1, 2, 4];

    /** @var HealthCheckerInterface External-service probes (embeddings, configured LLM) */
    private HealthCheckerInterface $healthChecker;

    /**
     * Constructor.
     *
     * @param string $vectorStoreBaseUrl vector-store server base URL (e.g., http://localhost:6333)
     * @param string $embeddingsBaseUrl Embeddings service base URL (e.g., http://embeddings:8000)
     * @param HealthCheckerInterface|null $healthChecker Embeddings and LLM probes
     */
    public function __construct(
        string $vectorStoreBaseUrl = 'http://localhost:6333',
        string $embeddingsBaseUrl = 'http://embeddings:8000',
        ?HealthCheckerInterface $healthChecker = null
    ) {
        $this->qdrantBaseUrl = rtrim($vectorStoreBaseUrl, '/');
        $this->embeddingsBaseUrl = rtrim($embeddingsBaseUrl, '/');
        $this->healthChecker = $healthChecker ?? new HealthChecker($this->embeddingsBaseUrl);

        // Qdrant stats still use this HTTP client directly in the legacy
        // getLegacyQdrantStats() path (no VectorStoreInterface available).
        $this->qdrantClient = new Client([
            'base_uri' => $this->qdrantBaseUrl,
            'timeout' => 5.0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Set the vector store service and backend configuration for stats.
     *
     * @param VectorStoreInterface $vectorStore The configured vector store
     * @param string $backend Backend type ('qdrant' or 'elasticsearch')
     * @param string $collectionName Collection/index name
     */
    public function setVectorStore(VectorStoreInterface $vectorStore, string $backend, string $collectionName): void
    {
        $this->vectorStore = $vectorStore;
        $this->vectorStoreBackend = $backend;
        $this->collectionName = $collectionName;
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
            }
        }

        throw new RuntimeException(
            "Failed to connect after {$this->maxRetries} attempts: " . $lastException->getMessage(),
            0,
            $lastException
        );
    }

    /**
     * Extract meaningful error message from ClientException (4xx errors).
     *
     * Parses the response body to get error message, or provides
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

            if (isset($data['status']['error'])) {
                $errorMessage = is_array($data['status']['error']) ? json_encode($data['status']['error']) : $data['status']['error'];
                return "Client error (HTTP {$statusCode}): {$errorMessage}";
            }

            if (isset($data['error'])) {
                $errorMessage = is_array($data['error']) ? json_encode($data['error']) : $data['error'];
                return "Client error (HTTP {$statusCode}): {$errorMessage}";
            }

            return "Client error (HTTP {$statusCode}): Request failed";
        } catch (\Exception $e) {
            return "Client error: Request failed with HTTP " . $exception->getResponse()->getStatusCode();
        }
    }

    /**
     * Log server error with meaningful context.
     *
     * Logs server errors (5xx) without exposing internal error details.
     *
     * @param ServerException $exception The server exception to log
     * @param string $operation The operation that failed (e.g., 'getQdrantStats')
     * @return void
     */
    private function logServerError(ServerException $exception, string $operation): void
    {
        $statusCode = $exception->getResponse()->getStatusCode();
        FileLogger::getInstance()->error("StatsService {$operation} failed: Server error (HTTP {$statusCode})");
    }

    /**
     * Get vector store statistics.
     *
     * Retrieves statistics about the configured vector-store collection including total vectors,
     * distribution by type, and collection health status.
     *
     * @return array<string, mixed> Structured array with keys: status (connected/disconnected), data (statistics), error (if failed)
     */
    public function getVectorStoreStats(): array
    {
        // Use VectorStoreInterface if available (backend-agnostic)
        if ($this->vectorStore !== null) {
            return $this->getStatsViaInterface();
        }

        // Legacy: direct Qdrant HTTP client
        return $this->getLegacyQdrantStats();
    }

    /**
     * Get vector store stats using the backend-agnostic VectorStoreInterface.
     *
     * @return array<string, mixed>
     */
    private function getStatsViaInterface(): array
    {
        try {
            $collectionInfo = $this->vectorStore->getCollectionInfo($this->collectionName);

            if ($collectionInfo === null) {
                return [
                    'status' => 'connected',
                    'data' => [
                        'total_collections' => 0,
                        'collections' => [],
                    ],
                    'backend' => $this->vectorStoreBackend,
                ];
            }

            $pointsCount = $collectionInfo['result']['points_count'] ?? 0;

            return [
                'status' => 'connected',
                'data' => [
                    'total_collections' => 1,
                    'collections' => [
                        $this->collectionName => [
                            'points_count' => $pointsCount,
                            'status' => $collectionInfo['result']['status'] ?? 'unknown',
                        ],
                    ],
                ],
                'backend' => $this->vectorStoreBackend,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'disconnected',
                'error' => $e->getMessage(),
                'backend' => $this->vectorStoreBackend,
            ];
        }
    }

    /**
     * Get counts of indexed resources by type (items, media, collections).
     *
     * Uses the vector store's countPoints method with payload type filtering
     * to provide accurate per-type counts for the dashboard.
     *
     * @return array<string, int> Associative array with keys: items, media, collections
     */
    public function getIndexedResourceCounts(): array
    {
        $counts = ['items' => 0, 'media' => 0, 'collections' => 0];

        if ($this->vectorStore === null) {
            return $counts;
        }

        try {
            $counts['items'] = $this->vectorStore->countPoints(
                $this->collectionName,
                ['type' => 'item']
            );
            $counts['media'] = $this->vectorStore->countPoints(
                $this->collectionName,
                ['type' => 'media']
            );
            $counts['collections'] = $this->vectorStore->countPoints(
                $this->collectionName,
                ['type' => 'collection']
            );
        } catch (\Exception $e) {
            // Silently fail — dashboard should still render with zeros
        }

        return $counts;
    }

    /**
     * Legacy: Get Qdrant stats via direct HTTP client.
     *
     * @return array<string, mixed>
     */
    private function getLegacyQdrantStats(): array
    {
        try {
            $response = $this->executeWithRetry(function () {
                try {
                    $response = $this->qdrantClient->get('/collections');
                    return $response;
                } catch (ClientException $e) {
                    $message = $this->extractClientErrorMessage($e);
                    throw new RuntimeException($message, 0, $e);
                } catch (ServerException $e) {
                    $this->logServerError($e, 'getQdrantStats');
                    throw new RuntimeException(
                        'vector-store server error (HTTP ' . $e->getResponse()->getStatusCode() . ')',
                        0,
                        $e
                    );
                }
            });

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if ($data === null) {
                return [
                    'status' => 'disconnected',
                    'error' => 'Invalid JSON response from the vector store'
                ];
            }

            // Extract collection information
            $collections = $data['result']['collections'] ?? [];
            $stats = [
                'total_collections' => count($collections),
                'collections' => []
            ];

            // Get detailed stats for each collection
            foreach ($collections as $collection) {
                $collectionName = $collection['name'];
                $collectionStats = $this->getCollectionStats($collectionName);
                $stats['collections'][$collectionName] = $collectionStats;
            }

            return [
                'status' => 'connected',
                'data' => $stats
            ];
        } catch (RuntimeException $e) {
            return [
                'status' => 'disconnected',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get statistics for a specific vector-store collection.
     *
     * @param string $collectionName Name of the collection
     * @return array<string, mixed> Collection statistics including points count
     */
    private function getCollectionStats(string $collectionName): array
    {
        try {
            $response = $this->executeWithRetry(function () use ($collectionName) {
                try {
                    $response = $this->qdrantClient->get("/collections/{$collectionName}");
                    return $response;
                } catch (ClientException $e) {
                    $message = $this->extractClientErrorMessage($e);
                    throw new RuntimeException($message, 0, $e);
                } catch (ServerException $e) {
                    $this->logServerError($e, 'getCollectionStats');
                    throw new RuntimeException(
                        'vector-store server error (HTTP ' . $e->getResponse()->getStatusCode() . ')',
                        0,
                        $e
                    );
                }
            });

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if ($data === null) {
                return [
                    'points_count' => 0,
                    'error' => 'Invalid JSON response'
                ];
            }

            return [
                'points_count' => $data['result']['points_count'] ?? 0,
                'vectors_count' => $data['result']['vectors_count'] ?? 0,
                'indexed_vectors_count' => $data['result']['indexed_vectors_count'] ?? 0,
                'status' => $data['result']['status'] ?? 'unknown'
            ];
        } catch (RuntimeException $e) {
            return [
                'points_count' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getEmbeddingsHealth(): array
    {
        return $this->healthChecker->checkEmbeddings();
    }

    /**
     * @return array<string, mixed>
     */
    public function getLlmHealth(): array
    {
        return $this->healthChecker->checkLlm();
    }

}
