<?php
declare(strict_types=1);
namespace SearchAssistantAI\Service\VectorStore;

use SearchAssistantAI\Service\FileLogger;

use RuntimeException;
use InvalidArgumentException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Psr\Http\Message\ResponseInterface;

/**
 * Elasticsearch 8.x implementation of VectorStoreInterface over its REST
 * API (dense_vector field, kNN search), with Guzzle like QdrantService.
 *
 * Each document holds the embedding in `vector` and the module's payload
 * under `payload`; the document id is the Omeka resource id. Results are
 * normalised to the Qdrant shape (`id`, `score`, `payload`) so the search
 * pipeline does not know which backend answered.
 */
class ElasticsearchService implements VectorStoreInterface
{
    /** @var ClientInterface Guzzle HTTP client for API requests */
    private $client;

    /** @var string Base URL of the Elasticsearch server */
    private $baseUrl;

    /** @var int Maximum number of retry attempts for network failures */
    private $maxRetries = 3;

    /** @var list<int> Retry delays in seconds for exponential backoff (1s, 2s, 4s) */
    private $retryDelays = [1, 2, 4];

    /** @var array<string, string> Generic distance names to Elasticsearch similarity */
    private static $distanceMap = [
        'Cosine' => 'cosine',
        'cosine' => 'cosine',
        'Dot' => 'dot_product',
        'dot' => 'dot_product',
        'Euclid' => 'l2_norm',
        'euclid' => 'l2_norm',
    ];

    /** @var ElasticsearchFilterBuilder Translates SearchFilter into the ES query DSL */
    private ElasticsearchFilterBuilder $filterBuilder;

    /**
     * @param string $baseUrl Elasticsearch base URL (e.g. http://elasticsearch:9200)
     * @param string|null $apiKey API key (sent as `Authorization: ApiKey ...`), null when security is off
     * @param float $timeout Request timeout in seconds
     * @param bool $verifySsl Whether to verify TLS certificates (off for self-signed ones)
     * @param ClientInterface|null $client Preconfigured HTTP client (tests)
     */
    public function __construct(
        string $baseUrl = 'http://elasticsearch:9200',
        ?string $apiKey = null,
        float $timeout = 5.0,
        bool $verifySsl = true,
        ?ElasticsearchFilterBuilder $filterBuilder = null,
        ?ClientInterface $client = null
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($apiKey !== null && $apiKey !== '') {
            $headers['Authorization'] = 'ApiKey ' . $apiKey;
        }
        $this->client = $client ?? new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'verify' => $verifySsl,
            'headers' => $headers,
        ]);
        $this->filterBuilder = $filterBuilder ?? new ElasticsearchFilterBuilder();
    }

    // ────────────────────────────────────────────────────────────────
    // internals
    // ────────────────────────────────────────────────────────────────

    /**
     * Execute a Guzzle request with retries on network failures only
     * (ConnectException); HTTP 4xx/5xx are handled by the callers.
     *
     * @return ResponseInterface
     * @throws RuntimeException If all retry attempts fail
     */
    private function executeWithRetry(callable $operation): ResponseInterface
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
                    sleep($this->retryDelays[$attempt - 1]);
                }
            }
        }

        throw new RuntimeException(
            "Failed to connect to Elasticsearch after {$this->maxRetries} attempts: " . $lastException->getMessage(),
            0,
            $lastException
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        return is_array($data) ? $data : [];
    }

    /** Elasticsearch error messages come as {"error": {"type", "reason"}}. */
    private function clientErrorMessage(ClientException $exception): string
    {
        $response = $exception->getResponse();
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        $reason = is_array($data) ? ($data['error']['reason'] ?? $data['error'] ?? null) : null;
        if (is_string($reason) && $reason !== '') {
            return "Client error (HTTP {$status}): {$reason}";
        }
        return "Client error (HTTP {$status}): Request failed";
    }

    private function logError(string $message): void
    {
        FileLogger::getInstance()->error('[Elasticsearch] ' . $message);
    }

    private function logServerError(ServerException $exception, string $operation): void
    {
        $this->logError(sprintf(
            '%s server error (HTTP %d): %s',
            $operation,
            $exception->getResponse()->getStatusCode(),
            $exception->getMessage()
        ));
    }

    private function validateCollectionName(string $collection): void
    {
        if (trim($collection) === '') {
            throw new InvalidArgumentException('Collection name cannot be empty');
        }
    }

    /**
     * @param array<string, mixed> $point
     */
    private function validatePoint(array $point): void
    {
        if (!isset($point['vector']) || !is_array($point['vector'])) {
            throw new InvalidArgumentException('Point must contain a "vector" array');
        }
        if (!isset($point['id'])) {
            throw new InvalidArgumentException('Point must contain an "id"');
        }
    }

    /**
     * @param array<string, mixed> $point
     * @return array<string, mixed> The indexed document
     */
    private function document(array $point): array
    {
        return [
            'vector' => $point['vector'],
            'payload' => $point['payload'] ?? [],
        ];
    }

    // ────────────────────────────────────────────────────────────────
    // VectorStoreInterface
    // ────────────────────────────────────────────────────────────────

    public function createCollection(string $name, int $dimension, string $distance = 'Cosine'): bool
    {
        $this->validateCollectionName($name);
        if ($dimension <= 0) {
            throw new InvalidArgumentException("Vector dimension must be positive, got {$dimension}");
        }
        $similarity = self::$distanceMap[$distance] ?? 'cosine';

        try {
            $this->executeWithRetry(function () use ($name, $dimension, $similarity) {
                return $this->client->request('PUT', $name, ['json' => [
                    'mappings' => [
                        'properties' => [
                            'vector' => [
                                'type' => 'dense_vector',
                                'dims' => $dimension,
                                'index' => true,
                                'similarity' => $similarity,
                            ],
                            'payload' => [
                                'type' => 'object',
                                'enabled' => true,
                            ],
                        ],
                    ],
                    'settings' => [
                        'number_of_shards' => 1,
                        'number_of_replicas' => 0,
                    ],
                ]]);
            });
            return true;
        } catch (ClientException $e) {
            $this->logError('createCollection failed: ' . $this->clientErrorMessage($e));
            return false;
        } catch (ServerException $e) {
            $this->logServerError($e, 'createCollection');
            return false;
        }
    }

    public function deleteCollection(string $collection): bool
    {
        $this->validateCollectionName($collection);

        try {
            $this->executeWithRetry(function () use ($collection) {
                return $this->client->request('DELETE', $collection);
            });
            return true;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                $this->logError('deleteCollection failed: ' . $this->clientErrorMessage($e));
            }
            return false;
        } catch (ServerException $e) {
            $this->logServerError($e, 'deleteCollection');
            return false;
        }
    }

    /**
     * Index mapping and document count, in the Qdrant-shaped structure the
     * callers read (`result.config.params.vectors.size`, `result.points_count`).
     *
     * @return array<string, mixed>|null null when the index does not exist
     */
    public function getCollectionInfo(string $collection): ?array
    {
        $this->validateCollectionName($collection);

        try {
            $mapping = $this->decode($this->executeWithRetry(function () use ($collection) {
                return $this->client->request('GET', $collection . '/_mapping');
            }));
            $count = $this->decode($this->executeWithRetry(function () use ($collection) {
                return $this->client->request('GET', $collection . '/_count');
            }));

            $vector = $mapping[$collection]['mappings']['properties']['vector'] ?? [];

            return [
                'result' => [
                    'status' => 'green',
                    'config' => [
                        'params' => [
                            'vectors' => [
                                'size' => $vector['dims'] ?? null,
                                'distance' => $vector['similarity'] ?? null,
                            ],
                        ],
                    ],
                    'points_count' => (int) ($count['count'] ?? 0),
                    'segments_count' => 1,
                ],
            ];
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                $this->logError('getCollectionInfo failed: ' . $this->clientErrorMessage($e));
            }
            return null;
        } catch (ServerException $e) {
            $this->logServerError($e, 'getCollectionInfo');
            return null;
        }
    }

    /**
     * @param array<string, mixed> $point
     */
    public function upsertPoint(string $collection, array $point): bool
    {
        $this->validateCollectionName($collection);
        $this->validatePoint($point);

        try {
            $this->executeWithRetry(function () use ($collection, $point) {
                return $this->client->request('PUT', $collection . '/_doc/' . rawurlencode((string) $point['id']), [
                    'json' => $this->document($point),
                ]);
            });
            return true;
        } catch (ClientException $e) {
            $this->logError('upsertPoint failed: ' . $this->clientErrorMessage($e));
            return false;
        } catch (ServerException $e) {
            $this->logServerError($e, 'upsertPoint');
            return false;
        }
    }

    /**
     * @param list<array<string, mixed>> $points
     */
    public function bulkUpsert(string $collection, array $points): bool
    {
        $this->validateCollectionName($collection);
        if ($points === []) {
            throw new InvalidArgumentException('Points array cannot be empty');
        }

        $lines = [];
        foreach ($points as $point) {
            $this->validatePoint($point);
            $lines[] = json_encode(['index' => ['_index' => $collection, '_id' => (string) $point['id']]]);
            $lines[] = json_encode($this->document($point));
        }
        $body = implode("\n", $lines) . "\n";

        try {
            $response = $this->decode($this->executeWithRetry(function () use ($body) {
                return $this->client->request('POST', '_bulk', [
                    'headers' => ['Content-Type' => 'application/x-ndjson'],
                    'body' => $body,
                ]);
            }));

            if (!empty($response['errors'])) {
                $failed = 0;
                foreach ($response['items'] ?? [] as $item) {
                    if (isset($item['index']['error'])) {
                        $failed++;
                    }
                }
                $this->logError(sprintf('bulkUpsert: %d of %d documents failed', $failed, count($points)));
                return $failed === 0;
            }
            return true;
        } catch (ClientException $e) {
            $this->logError('bulkUpsert failed: ' . $this->clientErrorMessage($e));
            return false;
        } catch (ServerException $e) {
            $this->logServerError($e, 'bulkUpsert');
            return false;
        }
    }

    /**
     * @param list<float> $vector
     * @return list<array<string, mixed>> [{id, score, payload}], best match first
     */
    public function search(string $collection, array $vector, int $limit = 10, float $scoreThreshold = null, ?SearchFilter $filter = null): array
    {
        $this->validateCollectionName($collection);
        if ($limit <= 0) {
            throw new InvalidArgumentException("Limit must be a positive integer, got {$limit}");
        }

        $body = [
            'knn' => [
                'field' => 'vector',
                'query_vector' => $vector,
                'k' => $limit,
                'num_candidates' => max($limit * 2, 100),
            ],
            'size' => $limit,
            '_source' => ['payload'],
        ];
        if ($filter !== null) {
            $esFilter = $this->filterBuilder->fromSearchFilter($filter);
            if ($esFilter !== null) {
                $body['knn']['filter'] = $esFilter;
            }
        }
        if ($scoreThreshold !== null) {
            $body['min_score'] = $scoreThreshold;
        }

        try {
            $response = $this->decode($this->executeWithRetry(function () use ($collection, $body) {
                return $this->client->request('POST', $collection . '/_search', ['json' => $body]);
            }));

            $results = [];
            foreach ($response['hits']['hits'] ?? [] as $hit) {
                $results[] = [
                    'id' => (int) $hit['_id'],
                    'score' => (float) ($hit['_score'] ?? 0.0),
                    'payload' => $hit['_source']['payload'] ?? [],
                ];
            }
            return $results;
        } catch (ClientException $e) {
            $this->logError('search failed: ' . $this->clientErrorMessage($e));
            return [];
        } catch (ServerException $e) {
            $this->logServerError($e, 'search');
            return [];
        }
    }

    public function optimizeCollection(string $collection): bool
    {
        $this->validateCollectionName($collection);

        try {
            $this->executeWithRetry(function () use ($collection) {
                return $this->client->request('POST', $collection . '/_forcemerge', [
                    'query' => ['max_num_segments' => 1],
                ]);
            });
            return true;
        } catch (ClientException $e) {
            $this->logError('optimizeCollection failed: ' . $this->clientErrorMessage($e));
            return false;
        } catch (ServerException $e) {
            $this->logServerError($e, 'optimizeCollection');
            return false;
        }
    }

    public function deletePoint(string $collection, int $pointId): bool
    {
        $this->validateCollectionName($collection);

        try {
            $this->executeWithRetry(function () use ($collection, $pointId) {
                return $this->client->request('DELETE', $collection . '/_doc/' . $pointId);
            });
            return true;
        } catch (ClientException $e) {
            // Already gone: deleting is idempotent.
            if ($e->getResponse()->getStatusCode() === 404) {
                return true;
            }
            $this->logError('deletePoint failed: ' . $this->clientErrorMessage($e));
            return false;
        } catch (ServerException $e) {
            $this->logServerError($e, 'deletePoint');
            return false;
        }
    }

    /**
     * @param array<string, mixed> $filter Exact-match conditions on payload fields (e.g. ['type' => 'item'])
     */
    public function countPoints(string $collection, array $filter = []): int
    {
        $this->validateCollectionName($collection);

        $options = [];
        if ($filter !== []) {
            $must = [];
            foreach ($filter as $key => $value) {
                $must[] = ['match' => ['payload.' . $key => $value]];
            }
            $options['json'] = ['query' => ['bool' => ['must' => $must]]];
        }

        try {
            $response = $this->decode($this->executeWithRetry(function () use ($collection, $options) {
                return $this->client->request('POST', $collection . '/_count', $options);
            }));
            return (int) ($response['count'] ?? 0);
        } catch (ClientException $e) {
            $this->logError('countPoints failed: ' . $this->clientErrorMessage($e));
            return 0;
        } catch (ServerException $e) {
            $this->logServerError($e, 'countPoints');
            return 0;
        }
    }

    /**
     * Whether the server answers `GET /` with its cluster and version info.
     */
    public function isHealthy(): bool
    {
        try {
            $info = $this->decode($this->executeWithRetry(function () {
                return $this->client->request('GET', '');
            }));
            return isset($info['cluster_name'], $info['version']);
        } catch (RuntimeException $e) {
            $this->logError('health check failed: ' . $e->getMessage());
            return false;
        }
    }
}
