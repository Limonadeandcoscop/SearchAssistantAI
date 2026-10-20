<?php
declare(strict_types=1);
namespace SearchAssistantAI\Service\VectorStore;

use SearchAssistantAI\Service\FileLogger;

use RuntimeException;
use InvalidArgumentException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

/**
 * Service implementation for interacting with Qdrant vector database REST API.
 *
 * Provides methods for collection management, vector operations,
 * and similarity search capabilities.
 */
class QdrantService implements VectorStoreInterface
{
    /**
     * @var Client Guzzle HTTP client for API requests
     */
    private $client;

    /**
     * @var string Base URL for Qdrant API
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

    /** @var QdrantFilterBuilder Lazily instantiated translator for SearchFilter → Qdrant DSL */
    private ?QdrantFilterBuilder $filterBuilderInstance = null;

    public function __construct(
        string $baseUrl = 'http://localhost:6333',
        float $timeout = 5.0,
        ?QdrantFilterBuilder $filterBuilder = null
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]);
        $this->filterBuilderInstance = $filterBuilder;
    }

    private function filterBuilder(): QdrantFilterBuilder
    {
        if ($this->filterBuilderInstance === null) {
            $this->filterBuilderInstance = new QdrantFilterBuilder();
        }
        return $this->filterBuilderInstance;
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
            "Failed to connect to Qdrant after {$this->maxRetries} attempts: " . $lastException->getMessage(),
            0,
            $lastException
        );
    }

    /**
     * Extract meaningful error message from ClientException (4xx errors).
     *
     * Parses the response body to get Qdrant's error message, or provides
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

            // Qdrant typically returns errors in this format: {"status": {"error": "message"}}
            if (isset($data['status']['error'])) {
                return "Client error (HTTP {$statusCode}): {$data['status']['error']}";
            }

            // Fallback to raw response if format is different
            if (isset($data['error'])) {
                return "Client error (HTTP {$statusCode}): {$data['error']}";
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
     * Logs server errors (5xx) without exposing internal Qdrant error details.
     *
     * @param ServerException $exception The server exception to log
     * @param string $operation The operation that failed (e.g., 'createCollection')
     * @return void
     */
    private function logServerError(ServerException $exception, string $operation): void
    {
        $statusCode = $exception->getResponse()->getStatusCode();
        FileLogger::getInstance()->error("Qdrant {$operation} failed: Qdrant server error (HTTP {$statusCode})");
    }

    /**
     * Validate collection name is non-empty.
     *
     * @param string $collection Collection name to validate
     * @return void
     * @throws InvalidArgumentException If collection name is empty
     */
    private function validateCollectionName(string $collection): void
    {
        if (empty(trim($collection))) {
            throw new InvalidArgumentException('Collection name cannot be empty');
        }
    }

    /**
     * Validate vector contains only numeric values.
     *
     * @param list<float|int> $vector Vector array to validate
     * @return void
     * @throws InvalidArgumentException If vector contains non-numeric values
     */
    private function validateVectorValues(array $vector): void
    {
        foreach ($vector as $index => $value) {
            if (!is_numeric($value)) {
                throw new InvalidArgumentException(
                    "Vector contains non-numeric value at index {$index}: " . var_export($value, true)
                );
            }
        }
    }

    /**
     * Validate vector dimensions match collection configuration.
     *
     * @param string $collection Collection name
     * @param list<float|int> $vector Vector to validate
     * @return void
     * @throws InvalidArgumentException If vector dimension doesn't match collection config
     * @throws RuntimeException If collection info cannot be retrieved
     */
    private function validateVectorDimensions(string $collection, array $vector): void
    {
        $collectionInfo = $this->getCollectionInfo($collection);

        if ($collectionInfo === null) {
            throw new RuntimeException("Cannot validate vector dimensions: collection '{$collection}' not found");
        }

        // Extract expected dimension from collection config
        $expectedDimension = $collectionInfo['result']['config']['params']['vectors']['size'] ?? null;

        if ($expectedDimension === null) {
            throw new RuntimeException("Cannot determine vector dimension for collection '{$collection}'");
        }

        $actualDimension = count($vector);

        if ($actualDimension !== $expectedDimension) {
            throw new InvalidArgumentException(
                "Vector dimension mismatch: expected {$expectedDimension}, got {$actualDimension}"
            );
        }
    }

    /**
     * Validate limit parameter is a positive integer.
     *
     * @param int $limit Limit value to validate
     * @return void
     * @throws InvalidArgumentException If limit is not positive
     */
    private function validateLimit(int $limit): void
    {
        if ($limit <= 0) {
            throw new InvalidArgumentException("Limit must be a positive integer, got {$limit}");
        }
    }

    /**
     * Create a new collection in Qdrant with specified vector dimensions.
     *
     * @param string $name Name of the collection to create
     * @param int $dimension Vector dimension size (size must match the embedding model in use)
     * @param string $distance Distance metric: Cosine, Dot, Euclid, or Manhattan (default: Cosine)
     * @return bool True if collection created successfully, false otherwise
     * @throws InvalidArgumentException If collection name is empty or dimension is not positive
     * @throws RuntimeException If connection fails after all retry attempts
     */
    public function createCollection(string $name, int $dimension, string $distance = 'Cosine'): bool
    {
        $this->validateCollectionName($name);

        if ($dimension <= 0) {
            throw new InvalidArgumentException("Vector dimension must be positive, got {$dimension}");
        }

        try {
            $response = $this->executeWithRetry(function () use ($name, $dimension, $distance) {
                return $this->client->request('PUT', "/collections/{$name}", [
                    'json' => [
                        'vectors' => [
                            'size' => $dimension,
                            'distance' => $distance
                        ]
                    ]
                ]);
            });

            $statusCode = $response->getStatusCode();
            return $statusCode === 200 || $statusCode === 201;
        } catch (ClientException $e) {
            // 4xx errors - collection already exists or invalid parameters
            $errorMessage = $this->extractClientErrorMessage($e);
            FileLogger::getInstance()->error("Qdrant createCollection failed: {$errorMessage}");
            return false;
        } catch (ServerException $e) {
            // 5xx errors - Qdrant server issues
            $this->logServerError($e, 'createCollection');
            return false;
        }
    }

    /**
     * Delete a collection from Qdrant.
     *
     * @param string $collection Name of the collection to delete
     * @return bool True if collection deleted successfully, false if collection not found
     * @throws InvalidArgumentException If collection name is empty
     * @throws RuntimeException If connection fails after all retry attempts
     */
    public function deleteCollection(string $collection): bool
    {
        $this->validateCollectionName($collection);

        try {
            $response = $this->executeWithRetry(function () use ($collection) {
                return $this->client->request('DELETE', "/collections/{$collection}");
            });

            $statusCode = $response->getStatusCode();
            return $statusCode === 200;
        } catch (ClientException $e) {
            // 4xx errors - collection not found
            $errorMessage = $this->extractClientErrorMessage($e);
            FileLogger::getInstance()->error("Qdrant deleteCollection failed: {$errorMessage}");
            return false;
        } catch (ServerException $e) {
            // 5xx errors - Qdrant server issues
            $this->logServerError($e, 'deleteCollection');
            return false;
        }
    }

    /**
     * Retrieve collection metadata and status information.
     *
     * @param string $collection Name of the collection
     * @return array<string, mixed>|null Collection info array with status and metadata, or null if not found
     * @throws InvalidArgumentException If collection name is empty
     * @throws RuntimeException If connection fails after all retry attempts
     */
    public function getCollectionInfo(string $collection): ?array
    {
        $this->validateCollectionName($collection);

        try {
            $response = $this->executeWithRetry(function () use ($collection) {
                return $this->client->request('GET', "/collections/{$collection}");
            });

            $statusCode = $response->getStatusCode();
            if ($statusCode === 200) {
                $body = $response->getBody()->getContents();
                return json_decode($body, true);
            }

            return null;
        } catch (ClientException $e) {
            // 4xx errors - collection not found
            $errorMessage = $this->extractClientErrorMessage($e);
            FileLogger::getInstance()->error("Qdrant getCollectionInfo failed: {$errorMessage}");
            return null;
        } catch (ServerException $e) {
            // 5xx errors - Qdrant server issues
            $this->logServerError($e, 'getCollectionInfo');
            return null;
        }
    }

    /**
     * Insert or update a vector point with associated payload metadata.
     *
     * @param string $collection Name of the collection
     * @param array<string, mixed> $point Point data with keys: id, vector (array), payload (array)
     * @return bool True if point upserted successfully, false otherwise
     * @throws InvalidArgumentException If collection name is empty, vector is invalid, or dimensions don't match
     * @throws RuntimeException If connection fails after all retry attempts
     */
    public function upsertPoint(string $collection, array $point): bool
    {
        $this->validateCollectionName($collection);

        // Validate point structure
        if (!isset($point['vector']) || !is_array($point['vector'])) {
            throw new InvalidArgumentException('Point must contain a "vector" array');
        }

        // Validate vector values are numeric
        $this->validateVectorValues($point['vector']);

        // Validate vector dimensions match collection config
        $this->validateVectorDimensions($collection, $point['vector']);

        try {
            $response = $this->executeWithRetry(function () use ($collection, $point) {
                return $this->client->request('PUT', "/collections/{$collection}/points", [
                    'json' => [
                        'points' => [$point]
                    ]
                ]);
            });

            $statusCode = $response->getStatusCode();
            return $statusCode === 200;
        } catch (ClientException $e) {
            // 4xx errors - collection not found or invalid parameters
            $errorMessage = $this->extractClientErrorMessage($e);
            FileLogger::getInstance()->error("Qdrant upsertPoint failed: {$errorMessage}");
            return false;
        } catch (ServerException $e) {
            // 5xx errors - Qdrant server issues
            $this->logServerError($e, 'upsertPoint');
            return false;
        }
    }

    /**
     * Insert or update multiple vector points in a single batch operation.
     *
     * Processes up to 100 points per batch. Uses wait=false for asynchronous indexing.
     *
     * @param string $collection Name of the collection
     * @param list<array<string, mixed>> $points Array of point data, each with keys: id, vector (array), payload (array)
     * @return bool True if points upserted successfully, false otherwise
     * @throws InvalidArgumentException If collection name is empty, points array is invalid, or batch exceeds 100 points
     * @throws RuntimeException If connection fails after all retry attempts
     */
    public function bulkUpsert(string $collection, array $points): bool
    {
        $this->validateCollectionName($collection);

        // Validate points array is not empty
        if (empty($points)) {
            throw new InvalidArgumentException('Points array cannot be empty');
        }

        // Validate batch size does not exceed 100 points
        $pointCount = count($points);
        if ($pointCount > 100) {
            throw new InvalidArgumentException("Batch size cannot exceed 100 points, got {$pointCount}");
        }

        // Validate each point's structure and vector data
        foreach ($points as $index => $point) {
            // Validate point structure
            if (!isset($point['vector']) || !is_array($point['vector'])) {
                throw new InvalidArgumentException("Point at index {$index} must contain a \"vector\" array");
            }

            // Validate vector values are numeric
            $this->validateVectorValues($point['vector']);

            // Validate vector dimensions match collection config
            $this->validateVectorDimensions($collection, $point['vector']);
        }

        try {
            $response = $this->executeWithRetry(function () use ($collection, $points) {
                return $this->client->request('PUT', "/collections/{$collection}/points", [
                    'json' => [
                        'points' => $points
                    ],
                    'query' => [
                        'wait' => 'false'
                    ]
                ]);
            });

            $statusCode = $response->getStatusCode();
            return $statusCode === 200;
        } catch (ClientException $e) {
            // 4xx errors - collection not found or invalid parameters
            $errorMessage = $this->extractClientErrorMessage($e);
            FileLogger::getInstance()->error("Qdrant bulkUpsert failed: {$errorMessage}");
            return false;
        } catch (ServerException $e) {
            // 5xx errors - Qdrant server issues
            $this->logServerError($e, 'bulkUpsert');
            return false;
        }
    }

    /**
     * Execute vector-based similarity search.
     *
     * @param string $collection Name of the collection to search
     * @param list<float> $vector Query vector for similarity matching
     * @param int $limit Maximum number of results to return (default: 10)
     * @param float|null $scoreThreshold Minimum similarity score threshold (optional)
     * @return list<array<string, mixed>> Array of search results with id, score, and payload fields, sorted by score descending
     * @throws InvalidArgumentException If collection name is empty, vector is invalid, dimensions don't match, or limit is not positive
     * @throws RuntimeException If connection fails after all retry attempts
     */
    public function search(string $collection, array $vector, int $limit = 10, float $scoreThreshold = null, ?SearchFilter $filter = null): array
    {
        $this->validateCollectionName($collection);
        $this->validateVectorValues($vector);
        $this->validateVectorDimensions($collection, $vector);
        $this->validateLimit($limit);

        try {
            $body = [
                'query' => $vector,
                'limit' => $limit,
                'with_payload' => true,
                'with_vectors' => false
            ];

            if ($scoreThreshold !== null) {
                $body['score_threshold'] = $scoreThreshold;
            }

            if ($filter !== null) {
                $qdrantFilter = $this->filterBuilder()->fromSearchFilter($filter);
                if ($qdrantFilter !== null) {
                    $body['filter'] = $qdrantFilter;
                }
            }

            $response = $this->executeWithRetry(function () use ($collection, $body) {
                return $this->client->request('POST', "/collections/{$collection}/points/query", [
                    'json' => $body
                ]);
            });

            $statusCode = $response->getStatusCode();
            if ($statusCode === 200) {
                $responseBody = $response->getBody()->getContents();
                $data = json_decode($responseBody, true);

                // Return the points array from the result, or empty array if no results
                return $data['result']['points'] ?? [];
            }

            return [];
        } catch (ClientException $e) {
            // 4xx errors - collection not found or invalid parameters
            $errorMessage = $this->extractClientErrorMessage($e);
            FileLogger::getInstance()->error("Qdrant search failed: {$errorMessage}");
            return [];
        } catch (ServerException $e) {
            // 5xx errors - Qdrant server issues
            $this->logServerError($e, 'search');
            return [];
        }
    }

    /**
     * Optimize collection index for improved search performance.
     *
     * Rebuilds the HNSW index structure. This should be called after bulk insertions
     * to ensure optimal search performance.
     *
     * @param string $collection Name of the collection to optimize
     * @return bool True if optimization started successfully, false otherwise
     * @throws InvalidArgumentException If collection name is empty
     * @throws RuntimeException If connection fails after all retry attempts
     */
    public function optimizeCollection(string $collection): bool
    {
        $this->validateCollectionName($collection);

        try {
            $response = $this->executeWithRetry(function () use ($collection) {
                return $this->client->request('POST', "/collections/{$collection}/optimize");
            });

            $statusCode = $response->getStatusCode();
            return $statusCode === 200;
        } catch (ClientException $e) {
            // 4xx errors - collection not found or invalid parameters
            $errorMessage = $this->extractClientErrorMessage($e);
            FileLogger::getInstance()->error("Qdrant optimizeCollection failed: {$errorMessage}");
            return false;
        } catch (ServerException $e) {
            // 5xx errors - Qdrant server issues
            $this->logServerError($e, 'optimizeCollection');
            return false;
        }
    }

    /**
     * Delete a single vector point from a collection.
     *
     * Idempotent: returns true if point was deleted or did not exist (404).
     *
     * @param string $collection Name of the collection
     * @param int $pointId Point ID to delete
     * @return bool True if deletion succeeded or point didn't exist, false on error
     * @throws InvalidArgumentException If collection name is empty
     * @throws RuntimeException If connection fails after all retry attempts
     */
    public function deletePoint(string $collection, int $pointId): bool
    {
        $this->validateCollectionName($collection);

        try {
            $response = $this->executeWithRetry(function () use ($collection, $pointId) {
                return $this->client->request('POST', "/collections/{$collection}/points/delete", [
                    'json' => [
                        'points' => [$pointId]
                    ]
                ]);
            });

            $statusCode = $response->getStatusCode();
            return $statusCode === 200;
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            // 404 = point or collection not found - treat as success (idempotent)
            if ($statusCode === 404) {
                return true;
            }
            $errorMessage = $this->extractClientErrorMessage($e);
            FileLogger::getInstance()->error("Qdrant deletePoint failed: {$errorMessage}");
            return false;
        } catch (ServerException $e) {
            $this->logServerError($e, 'deletePoint');
            return false;
        }
    }

    /**
     * Count points in a collection, optionally filtered by payload field values.
     *
     * Uses Qdrant's POST /collections/{collection}/points/count endpoint.
     *
     * @param string $collection Name of the collection
     * @param array<string, mixed> $filter Optional payload filter (e.g., ['type' => 'item'])
     * @return int Number of matching points, or 0 on error
     */
    public function countPoints(string $collection, array $filter = []): int
    {
        $this->validateCollectionName($collection);

        try {
            $body = ['exact' => true];

            if (!empty($filter)) {
                $must = [];
                foreach ($filter as $key => $value) {
                    $must[] = [
                        'key' => $key,
                        'match' => ['value' => $value],
                    ];
                }
                $body['filter'] = ['must' => $must];
            }

            $response = $this->executeWithRetry(function () use ($collection, $body) {
                return $this->client->request('POST', "/collections/{$collection}/points/count", [
                    'json' => $body
                ]);
            });

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                return $data['result']['count'] ?? 0;
            }

            return 0;
        } catch (ClientException $e) {
            $errorMessage = $this->extractClientErrorMessage($e);
            FileLogger::getInstance()->error("Qdrant countPoints failed: {$errorMessage}");
            return 0;
        } catch (ServerException $e) {
            $this->logServerError($e, 'countPoints');
            return 0;
        }
    }
}
