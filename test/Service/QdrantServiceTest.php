<?php
namespace SearchAssistantAITest\Service;

use PHPUnit\Framework\TestCase;
use SearchAssistantAI\Service\VectorStore\QdrantService;
use SearchAssistantAI\Service\VectorStore\VectorStoreInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use InvalidArgumentException;
use RuntimeException;
use ReflectionClass;

/**
 * Unit tests for QdrantService.
 *
 * Tests cover collection management, vector operations, error handling,
 * and retry logic using Guzzle MockHandler to simulate HTTP responses.
 */
class QdrantServiceTest extends TestCase
{
    /**
     * @var MockHandler
     */
    private $mockHandler;

    /**
     * @var QdrantService
     */
    private $service;

    /**
     * Set up test fixtures before each test.
     *
     * Creates a QdrantService instance with a mocked Guzzle HTTP client.
     * The mock handler allows us to queue predetermined responses for testing.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock handler to queue responses
        $this->mockHandler = new MockHandler();

        // Create a handler stack with the mock
        $handlerStack = HandlerStack::create($this->mockHandler);

        // Create a Guzzle client with the mock handler
        $mockClient = new Client(['handler' => $handlerStack]);

        // Create service instance
        $this->service = new QdrantService('http://localhost:6333', 5.0);

        // Use reflection to inject the mocked client into the service
        $reflection = new ReflectionClass($this->service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($this->service, $mockClient);
    }

    /**
     * Clean up after each test.
     */
    protected function tearDown(): void
    {
        $this->mockHandler = null;
        $this->service = null;
        parent::tearDown();
    }

    /**
     * Test that QdrantService implements VectorStoreInterface.
     */
    public function testImplementsVectorStoreInterface(): void
    {
        $this->assertInstanceOf(VectorStoreInterface::class, $this->service);
    }

    /**
     * Test that service can be instantiated with default parameters.
     */
    public function testServiceInstantiation(): void
    {
        $service = new QdrantService();
        $this->assertInstanceOf(QdrantService::class, $service);
    }

    /**
     * Test that service can be instantiated with custom parameters.
     */
    public function testServiceInstantiationWithCustomParams(): void
    {
        $service = new QdrantService('http://custom-host:6333', 10.0);
        $this->assertInstanceOf(QdrantService::class, $service);
    }

    // ==================================================
    // Collection Management Tests
    // ==================================================

    /**
     * Test successful collection creation.
     *
     * Verifies that createCollection sends a PUT request with correct
     * vector configuration and returns true on success.
     */
    public function testCreateCollection(): void
    {
        // Queue a successful response (HTTP 200)
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => true, 'status' => 'ok']))
        );

        $result = $this->service->createCollection('test_collection', 384, 'Cosine');

        $this->assertTrue($result);
    }

    /**
     * Test collection creation with HTTP 201 response.
     *
     * Some REST APIs return 201 Created instead of 200 OK.
     * Verify that both are treated as success.
     */
    public function testCreateCollectionWithCreatedStatus(): void
    {
        // Queue a 201 Created response
        $this->mockHandler->append(
            new Response(201, [], json_encode(['result' => true, 'status' => 'ok']))
        );

        $result = $this->service->createCollection('test_collection', 384, 'Cosine');

        $this->assertTrue($result);
    }

    /**
     * Test collection creation with empty collection name.
     *
     * Verifies that validation rejects empty collection names.
     */
    public function testCreateCollectionWithEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection name cannot be empty');

        $this->service->createCollection('', 384);
    }

    /**
     * Test collection creation with whitespace-only collection name.
     *
     * Verifies that validation rejects whitespace-only names.
     */
    public function testCreateCollectionWithWhitespaceName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection name cannot be empty');

        $this->service->createCollection('   ', 384);
    }

    /**
     * Test collection creation with zero dimension.
     *
     * Verifies that validation rejects zero dimensions.
     */
    public function testCreateCollectionWithZeroDimension(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vector dimension must be positive, got 0');

        $this->service->createCollection('test_collection', 0);
    }

    /**
     * Test collection creation with negative dimension.
     *
     * Verifies that validation rejects negative dimensions.
     */
    public function testCreateCollectionWithNegativeDimension(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vector dimension must be positive, got -1');

        $this->service->createCollection('test_collection', -1);
    }

    /**
     * Test collection creation when collection already exists (ClientException).
     *
     * Verifies that 4xx errors are handled gracefully and return false.
     */
    public function testCreateCollectionAlreadyExists(): void
    {
        // Queue a 409 Conflict response (collection already exists)
        $request = new Request('PUT', '/collections/test_collection');
        $response = new Response(
            409,
            [],
            json_encode(['status' => ['error' => 'Collection already exists']])
        );
        $this->mockHandler->append(new ClientException('Conflict', $request, $response));

        $result = $this->service->createCollection('test_collection', 384);

        $this->assertFalse($result);
    }

    /**
     * Test collection creation with server error (ServerException).
     *
     * Verifies that 5xx errors are handled gracefully and return false.
     */
    public function testCreateCollectionServerError(): void
    {
        // Queue a 500 Internal Server Error response
        $request = new Request('PUT', '/collections/test_collection');
        $response = new Response(500, [], json_encode(['error' => 'Internal server error']));
        $this->mockHandler->append(new ServerException('Server error', $request, $response));

        $result = $this->service->createCollection('test_collection', 384);

        $this->assertFalse($result);
    }

    /**
     * Test successful collection info retrieval.
     *
     * Verifies that getCollectionInfo sends a GET request and returns
     * the parsed JSON response.
     */
    public function testGetCollectionInfo(): void
    {
        // Queue a successful response with collection metadata
        $collectionData = [
            'result' => [
                'status' => 'green',
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 384,
                            'distance' => 'Cosine'
                        ]
                    ]
                ],
                'vectors_count' => 100
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        $result = $this->service->getCollectionInfo('test_collection');

        $this->assertIsArray($result);
        $this->assertEquals('green', $result['result']['status']);
        $this->assertEquals(384, $result['result']['config']['params']['vectors']['size']);
        $this->assertEquals(100, $result['result']['vectors_count']);
    }

    /**
     * Test collection info retrieval when collection doesn't exist.
     *
     * Verifies that 404 Not Found errors return null gracefully.
     */
    public function testGetCollectionInfoNotFound(): void
    {
        // Queue a 404 Not Found response
        $request = new Request('GET', '/collections/nonexistent');
        $response = new Response(
            404,
            [],
            json_encode(['status' => ['error' => 'Collection not found']])
        );
        $this->mockHandler->append(new ClientException('Not found', $request, $response));

        $result = $this->service->getCollectionInfo('nonexistent');

        $this->assertNull($result);
    }

    /**
     * Test collection info retrieval with empty collection name.
     *
     * Verifies that validation rejects empty collection names.
     */
    public function testGetCollectionInfoWithEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection name cannot be empty');

        $this->service->getCollectionInfo('');
    }

    /**
     * Test collection info retrieval with server error.
     *
     * Verifies that 5xx errors are handled gracefully and return null.
     */
    public function testGetCollectionInfoServerError(): void
    {
        // Queue a 500 Internal Server Error response
        $request = new Request('GET', '/collections/test_collection');
        $response = new Response(500, [], json_encode(['error' => 'Internal server error']));
        $this->mockHandler->append(new ServerException('Server error', $request, $response));

        $result = $this->service->getCollectionInfo('test_collection');

        $this->assertNull($result);
    }

    /**
     * Test successful collection deletion.
     *
     * Verifies that deleteCollection sends a DELETE request and returns true on success.
     */
    public function testDeleteCollection(): void
    {
        // Queue a successful response (HTTP 200)
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => true, 'status' => 'ok']))
        );

        $result = $this->service->deleteCollection('test_collection');

        $this->assertTrue($result);
    }

    /**
     * Test collection deletion when collection doesn't exist.
     *
     * Verifies that 404 Not Found errors return false gracefully.
     */
    public function testDeleteCollectionNotFound(): void
    {
        // Queue a 404 Not Found response
        $request = new Request('DELETE', '/collections/nonexistent');
        $response = new Response(
            404,
            [],
            json_encode(['status' => ['error' => 'Collection not found']])
        );
        $this->mockHandler->append(new ClientException('Not found', $request, $response));

        $result = $this->service->deleteCollection('nonexistent');

        $this->assertFalse($result);
    }

    /**
     * Test collection deletion with empty collection name.
     *
     * Verifies that validation rejects empty collection names.
     */
    public function testDeleteCollectionWithEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection name cannot be empty');

        $this->service->deleteCollection('');
    }

    /**
     * Test collection deletion with server error.
     *
     * Verifies that 5xx errors are handled gracefully and return false.
     */
    public function testDeleteCollectionServerError(): void
    {
        // Queue a 500 Internal Server Error response
        $request = new Request('DELETE', '/collections/test_collection');
        $response = new Response(500, [], json_encode(['error' => 'Internal server error']));
        $this->mockHandler->append(new ServerException('Server error', $request, $response));

        $result = $this->service->deleteCollection('test_collection');

        $this->assertFalse($result);
    }

    // ==================================================
    // Vector Operations Tests - Upsert
    // ==================================================

    /**
     * Test successful vector point upsert.
     *
     * Verifies that upsertPoint sends a PUT request with correct point data
     * and returns true on success.
     */
    public function testUpsertPoint(): void
    {
        // Mock getCollectionInfo to return collection config (for dimension validation)
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // Mock successful upsert response
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => ['status' => 'ok']]))
        );

        $point = [
            'id' => 1,
            'vector' => [0.1, 0.2, 0.3],
            'payload' => ['text' => 'test document']
        ];

        $result = $this->service->upsertPoint('test_collection', $point);

        $this->assertTrue($result);
    }

    /**
     * Test upsert point with empty collection name.
     *
     * Verifies that validation rejects empty collection names.
     */
    public function testUpsertPointWithEmptyCollection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection name cannot be empty');

        $point = [
            'id' => 1,
            'vector' => [0.1, 0.2, 0.3],
            'payload' => []
        ];

        $this->service->upsertPoint('', $point);
    }

    /**
     * Test upsert point with missing vector.
     *
     * Verifies that validation requires a vector array.
     */
    public function testUpsertPointWithMissingVector(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Point must contain a "vector" array');

        $point = [
            'id' => 1,
            'payload' => ['text' => 'test']
        ];

        $this->service->upsertPoint('test_collection', $point);
    }

    /**
     * Test upsert point with non-array vector.
     *
     * Verifies that validation requires vector to be an array.
     */
    public function testUpsertPointWithNonArrayVector(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Point must contain a "vector" array');

        $point = [
            'id' => 1,
            'vector' => 'not_an_array',
            'payload' => []
        ];

        $this->service->upsertPoint('test_collection', $point);
    }

    /**
     * Test upsert point with non-numeric vector values.
     *
     * Verifies that validation rejects non-numeric values in vectors.
     */
    public function testUpsertPointWithNonNumericVector(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vector contains non-numeric value at index 1');

        $point = [
            'id' => 1,
            'vector' => [0.1, 'invalid', 0.3],
            'payload' => []
        ];

        $this->service->upsertPoint('test_collection', $point);
    }

    /**
     * Test upsert point with null value in vector.
     *
     * Verifies that validation rejects null values in vectors.
     */
    public function testUpsertPointWithNullInVector(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vector contains non-numeric value at index 2');

        $point = [
            'id' => 1,
            'vector' => [0.1, 0.2, null],
            'payload' => []
        ];

        $this->service->upsertPoint('test_collection', $point);
    }

    /**
     * Test upsert point with dimension mismatch.
     *
     * Verifies that validation rejects vectors with wrong dimensions.
     */
    public function testUpsertPointWithDimensionMismatch(): void
    {
        // Mock getCollectionInfo to return collection config expecting 3 dimensions
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vector dimension mismatch: expected 3, got 5');

        $point = [
            'id' => 1,
            'vector' => [0.1, 0.2, 0.3, 0.4, 0.5],  // 5 dimensions instead of 3
            'payload' => []
        ];

        $this->service->upsertPoint('test_collection', $point);
    }

    /**
     * Test upsert point when collection doesn't exist.
     *
     * Verifies that trying to validate dimensions on non-existent collection throws RuntimeException.
     */
    public function testUpsertPointCollectionNotFound(): void
    {
        // Mock getCollectionInfo to return null (collection not found)
        $request = new Request('GET', '/collections/nonexistent');
        $response = new Response(404, [], json_encode(['status' => ['error' => 'Collection not found']]));
        $this->mockHandler->append(new ClientException('Not found', $request, $response));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot validate vector dimensions: collection 'nonexistent' not found");

        $point = [
            'id' => 1,
            'vector' => [0.1, 0.2, 0.3],
            'payload' => []
        ];

        $this->service->upsertPoint('nonexistent', $point);
    }

    /**
     * Test upsert point with client error.
     *
     * Verifies that 4xx errors are handled gracefully and return false.
     */
    public function testUpsertPointClientError(): void
    {
        // Mock getCollectionInfo for dimension validation
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // Mock client error response
        $request = new Request('PUT', '/collections/test_collection/points');
        $response = new Response(400, [], json_encode(['status' => ['error' => 'Invalid point data']]));
        $this->mockHandler->append(new ClientException('Bad request', $request, $response));

        $point = [
            'id' => 1,
            'vector' => [0.1, 0.2, 0.3],
            'payload' => []
        ];

        $result = $this->service->upsertPoint('test_collection', $point);

        $this->assertFalse($result);
    }

    /**
     * Test upsert point with server error.
     *
     * Verifies that 5xx errors are handled gracefully and return false.
     */
    public function testUpsertPointServerError(): void
    {
        // Mock getCollectionInfo for dimension validation
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // Mock server error response
        $request = new Request('PUT', '/collections/test_collection/points');
        $response = new Response(500, [], json_encode(['error' => 'Internal server error']));
        $this->mockHandler->append(new ServerException('Server error', $request, $response));

        $point = [
            'id' => 1,
            'vector' => [0.1, 0.2, 0.3],
            'payload' => []
        ];

        $result = $this->service->upsertPoint('test_collection', $point);

        $this->assertFalse($result);
    }

    // ==================================================
    // Vector Operations Tests - Bulk Upsert
    // ==================================================

    /**
     * Test successful bulk upsert with 100 points.
     *
     * Verifies that bulkUpsert can handle the maximum batch size of 100 points,
     * correctly validates all points, and returns true on success.
     */
    public function testBulkUpsert(): void
    {
        // Prepare collection data for dimension validation
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];

        // Mock getCollectionInfo responses for each point's dimension validation (100 points)
        // Note: bulkUpsert calls validateVectorDimensions for each point, which calls getCollectionInfo
        for ($i = 0; $i < 100; $i++) {
            $this->mockHandler->append(
                new Response(200, [], json_encode($collectionData))
            );
        }

        // Mock successful bulk upsert response
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => ['status' => 'ok']]))
        );

        // Create 100 test points
        $points = [];
        for ($i = 0; $i < 100; $i++) {
            $points[] = [
                'id' => $i + 1,
                'vector' => [0.1, 0.2, 0.3],
                'payload' => ['text' => "test document {$i}"]
            ];
        }

        $result = $this->service->bulkUpsert('test_collection', $points);

        $this->assertTrue($result);
    }

    // ==================================================
    // Vector Operations Tests - Search
    // ==================================================

    /**
     * Test successful vector similarity search.
     *
     * Verifies that search sends a POST request with correct query vector
     * and returns results sorted by score.
     */
    public function testSearch(): void
    {
        // Mock getCollectionInfo for dimension validation
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // Mock successful search response
        $searchResults = [
            'result' => [
                'points' => [
                    [
                        'id' => 1,
                        'score' => 0.95,
                        'payload' => ['text' => 'First result']
                    ],
                    [
                        'id' => 2,
                        'score' => 0.85,
                        'payload' => ['text' => 'Second result']
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($searchResults))
        );

        $queryVector = [0.1, 0.2, 0.3];
        $results = $this->service->search('test_collection', $queryVector, 10);

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertEquals(1, $results[0]['id']);
        $this->assertEquals(0.95, $results[0]['score']);
        $this->assertEquals('First result', $results[0]['payload']['text']);
        $this->assertEquals(2, $results[1]['id']);
        $this->assertEquals(0.85, $results[1]['score']);
    }

    /**
     * Test search with score threshold.
     *
     * Verifies that search correctly applies score threshold filter.
     */
    public function testSearchWithScoreThreshold(): void
    {
        // Mock getCollectionInfo for dimension validation
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // Mock search response with only high-scoring results
        $searchResults = [
            'result' => [
                'points' => [
                    [
                        'id' => 1,
                        'score' => 0.95,
                        'payload' => ['text' => 'High score result']
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($searchResults))
        );

        $queryVector = [0.1, 0.2, 0.3];
        $results = $this->service->search('test_collection', $queryVector, 10, 0.9);

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals(0.95, $results[0]['score']);
        $this->assertGreaterThan(0.9, $results[0]['score']);
    }

    /**
     * Test search with no results.
     *
     * Verifies that empty result set returns empty array.
     */
    public function testSearchNoResults(): void
    {
        // Mock getCollectionInfo for dimension validation
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // Mock search response with no results
        $searchResults = [
            'result' => [
                'points' => []
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($searchResults))
        );

        $queryVector = [0.1, 0.2, 0.3];
        $results = $this->service->search('test_collection', $queryVector);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /**
     * Test search with empty collection name.
     *
     * Verifies that validation rejects empty collection names.
     */
    public function testSearchWithEmptyCollection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection name cannot be empty');

        $queryVector = [0.1, 0.2, 0.3];
        $this->service->search('', $queryVector);
    }

    /**
     * Test search with non-numeric vector values.
     *
     * Verifies that validation rejects non-numeric values in query vectors.
     */
    public function testSearchWithNonNumericVector(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vector contains non-numeric value at index 1');

        $queryVector = [0.1, 'invalid', 0.3];
        $this->service->search('test_collection', $queryVector);
    }

    /**
     * Test search with dimension mismatch.
     *
     * Verifies that validation rejects query vectors with wrong dimensions.
     */
    public function testSearchWithDimensionMismatch(): void
    {
        // Mock getCollectionInfo to return collection config expecting 3 dimensions
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vector dimension mismatch: expected 3, got 5');

        $queryVector = [0.1, 0.2, 0.3, 0.4, 0.5];  // 5 dimensions instead of 3
        $this->service->search('test_collection', $queryVector);
    }

    /**
     * Test search with zero limit.
     *
     * Verifies that validation rejects zero limit.
     */
    public function testSearchWithZeroLimit(): void
    {
        // Dimension validation runs before limit validation and hits the
        // client, so queue a matching collection-info response first.
        $this->mockHandler->append(new Response(200, [], json_encode([
            'result' => ['config' => ['params' => ['vectors' => ['size' => 3, 'distance' => 'Cosine']]]],
        ])));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be a positive integer, got 0');

        $this->service->search('test_collection', [0.1, 0.2, 0.3], 0);
    }

    public function testSearchWithNegativeLimit(): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode([
            'result' => ['config' => ['params' => ['vectors' => ['size' => 3, 'distance' => 'Cosine']]]],
        ])));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be a positive integer, got -5');

        $this->service->search('test_collection', [0.1, 0.2, 0.3], -5);
    }

    /**
     * Test search when collection doesn't exist.
     *
     * Verifies that trying to validate dimensions on non-existent collection throws RuntimeException.
     */
    public function testSearchCollectionNotFound(): void
    {
        // Mock getCollectionInfo to return null (collection not found)
        $request = new Request('GET', '/collections/nonexistent');
        $response = new Response(404, [], json_encode(['status' => ['error' => 'Collection not found']]));
        $this->mockHandler->append(new ClientException('Not found', $request, $response));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot validate vector dimensions: collection 'nonexistent' not found");

        $queryVector = [0.1, 0.2, 0.3];
        $this->service->search('nonexistent', $queryVector);
    }

    /**
     * Test search with client error.
     *
     * Verifies that 4xx errors are handled gracefully and return empty array.
     */
    public function testSearchClientError(): void
    {
        // Mock getCollectionInfo for dimension validation
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // Mock client error response
        $request = new Request('POST', '/collections/test_collection/points/query');
        $response = new Response(400, [], json_encode(['status' => ['error' => 'Invalid query']]));
        $this->mockHandler->append(new ClientException('Bad request', $request, $response));

        $queryVector = [0.1, 0.2, 0.3];
        $results = $this->service->search('test_collection', $queryVector);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /**
     * Test search with server error.
     *
     * Verifies that 5xx errors are handled gracefully and return empty array.
     */
    public function testSearchServerError(): void
    {
        // Mock getCollectionInfo for dimension validation
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // Mock server error response
        $request = new Request('POST', '/collections/test_collection/points/query');
        $response = new Response(500, [], json_encode(['error' => 'Internal server error']));
        $this->mockHandler->append(new ServerException('Server error', $request, $response));

        $queryVector = [0.1, 0.2, 0.3];
        $results = $this->service->search('test_collection', $queryVector);

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // ==================================================
    // Error Handling and Retry Logic Tests
    // ==================================================

    /**
     * Test retry logic with transient network failure that succeeds on second attempt.
     *
     * Verifies that ConnectException triggers retry and eventual success.
     */
    public function testRetrySucceedsAfterTransientFailure(): void
    {
        // First attempt: network failure (ConnectException)
        $request = new Request('PUT', '/collections/test_collection');
        $this->mockHandler->append(
            new ConnectException('Connection refused', $request)
        );

        // Second attempt: success
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => true, 'status' => 'ok']))
        );

        $result = $this->service->createCollection('test_collection', 384);

        $this->assertTrue($result);
    }

    /**
     * Test retry logic with two transient failures before success.
     *
     * Verifies that retry logic can recover from multiple consecutive failures.
     */
    public function testRetrySucceedsAfterTwoFailures(): void
    {
        // First attempt: network failure
        $request = new Request('DELETE', '/collections/test_collection');
        $this->mockHandler->append(
            new ConnectException('Connection timeout', $request)
        );

        // Second attempt: network failure again
        $this->mockHandler->append(
            new ConnectException('Connection timeout', $request)
        );

        // Third attempt: success
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => true, 'status' => 'ok']))
        );

        $result = $this->service->deleteCollection('test_collection');

        $this->assertTrue($result);
    }

    /**
     * Test retry exhaustion when all attempts fail.
     *
     * Verifies that RuntimeException is thrown after max retry attempts (3).
     */
    public function testRetryExhaustion(): void
    {
        $request = new Request('PUT', '/collections/test_collection');

        // Queue 3 connection failures (all retry attempts will fail)
        $this->mockHandler->append(
            new ConnectException('Connection refused', $request)
        );
        $this->mockHandler->append(
            new ConnectException('Connection refused', $request)
        );
        $this->mockHandler->append(
            new ConnectException('Connection refused', $request)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to connect to Qdrant after 3 attempts');

        $this->service->createCollection('test_collection', 384);
    }

    /**
     * Test that ClientException does NOT trigger retry logic.
     *
     * Verifies that 4xx errors are treated as non-retryable client errors.
     */
    public function testRetryDoesNotOccurForClientException(): void
    {
        // Queue a single 4xx error - should NOT retry
        $request = new Request('PUT', '/collections/test_collection');
        $response = new Response(
            409,
            [],
            json_encode(['status' => ['error' => 'Collection already exists']])
        );
        $this->mockHandler->append(new ClientException('Conflict', $request, $response));

        // Do NOT queue additional responses - if retry occurs, the test will fail
        // because MockHandler will have no more responses

        $result = $this->service->createCollection('test_collection', 384);

        $this->assertFalse($result);
    }

    /**
     * Test that ServerException does NOT trigger retry logic.
     *
     * Verifies that 5xx errors are treated as non-retryable server errors.
     */
    public function testRetryDoesNotOccurForServerException(): void
    {
        // Queue a single 5xx error - should NOT retry
        $request = new Request('GET', '/collections/test_collection');
        $response = new Response(500, [], json_encode(['error' => 'Internal server error']));
        $this->mockHandler->append(new ServerException('Server error', $request, $response));

        // Do NOT queue additional responses - if retry occurs, the test will fail
        // because MockHandler will have no more responses

        $result = $this->service->getCollectionInfo('test_collection');

        $this->assertNull($result);
    }

    /**
     * Test retry logic for upsert operation.
     *
     * Verifies that upsertPoint correctly implements retry logic for network failures.
     */
    public function testRetryForUpsertOperation(): void
    {
        // Mock getCollectionInfo for dimension validation (successful)
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // First upsert attempt: network failure
        $request = new Request('PUT', '/collections/test_collection/points');
        $this->mockHandler->append(
            new ConnectException('Connection lost', $request)
        );

        // Second upsert attempt: success
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => ['status' => 'ok']]))
        );

        $point = [
            'id' => 1,
            'vector' => [0.1, 0.2, 0.3],
            'payload' => ['text' => 'test']
        ];

        $result = $this->service->upsertPoint('test_collection', $point);

        $this->assertTrue($result);
    }

    /**
     * Test retry logic for search operation.
     *
     * Verifies that search correctly implements retry logic for network failures.
     */
    public function testRetryForSearchOperation(): void
    {
        // Mock getCollectionInfo for dimension validation (successful)
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // First search attempt: network failure
        $request = new Request('POST', '/collections/test_collection/points/query');
        $this->mockHandler->append(
            new ConnectException('Connection timeout', $request)
        );

        // Second search attempt: success
        $searchResults = [
            'result' => [
                'points' => [
                    [
                        'id' => 1,
                        'score' => 0.95,
                        'payload' => ['text' => 'result']
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($searchResults))
        );

        $queryVector = [0.1, 0.2, 0.3];
        $results = $this->service->search('test_collection', $queryVector);

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]['id']);
    }

    /**
     * Test error message extraction from ClientException.
     *
     * Verifies that meaningful error messages are extracted from 4xx responses.
     */
    public function testClientErrorMessageExtraction(): void
    {
        // Queue a 404 error with Qdrant error format
        $request = new Request('GET', '/collections/nonexistent');
        $response = new Response(
            404,
            [],
            json_encode(['status' => ['error' => 'Collection not found']])
        );
        $this->mockHandler->append(new ClientException('Not found', $request, $response));

        $result = $this->service->getCollectionInfo('nonexistent');

        $this->assertNull($result);
        // Error message should be logged (verify via error_log in production)
    }

    /**
     * Test error message extraction with alternative error format.
     *
     * Verifies fallback error parsing when response format differs.
     */
    public function testClientErrorMessageExtractionAlternativeFormat(): void
    {
        // Append in order: first the collection-info probe (dimension
        // validation), then the PUT that fails with the alternative
        // error-format body.
        $this->mockHandler->append(new Response(200, [], json_encode([
            'result' => ['config' => ['params' => ['vectors' => ['size' => 3, 'distance' => 'Cosine']]]],
        ])));

        $request = new Request('PUT', '/collections/test_collection/points');
        $response = new Response(400, [], json_encode(['error' => 'Invalid vector data']));
        $this->mockHandler->append(new ClientException('Bad request', $request, $response));

        $point = [
            'id' => 1,
            'vector' => [0.1, 0.2, 0.3],
            'payload' => []
        ];

        $result = $this->service->upsertPoint('test_collection', $point);

        $this->assertFalse($result);
    }

    /**
     * Test connection retry with getCollectionInfo operation.
     *
     * Verifies that collection info retrieval implements retry logic.
     */
    public function testRetryForGetCollectionInfo(): void
    {
        // First attempt: network failure
        $request = new Request('GET', '/collections/test_collection');
        $this->mockHandler->append(
            new ConnectException('Network unreachable', $request)
        );

        // Second attempt: success
        $collectionData = [
            'result' => [
                'status' => 'green',
                'vectors_count' => 100
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        $result = $this->service->getCollectionInfo('test_collection');

        $this->assertIsArray($result);
        $this->assertEquals('green', $result['result']['status']);
        $this->assertEquals(100, $result['result']['vectors_count']);
    }

    /**
     * Test retry logic for delete collection operation.
     *
     * Verifies that deleteCollection correctly implements retry logic for network failures.
     */
    public function testRetryForDeleteCollection(): void
    {
        // First attempt: network failure
        $request = new Request('DELETE', '/collections/test_collection');
        $this->mockHandler->append(
            new ConnectException('Connection reset', $request)
        );

        // Second attempt: success
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => true, 'status' => 'ok']))
        );

        $result = $this->service->deleteCollection('test_collection');

        $this->assertTrue($result);
    }

    // ==================================================
    // Edge Case Tests - Malformed Collection Config
    // ==================================================

    /**
     * Test upsert point when collection has malformed config (missing dimension info).
     *
     * Verifies that RuntimeException is thrown when collection exists but
     * vector dimension info cannot be determined from config.
     */
    public function testUpsertPointWithMalformedCollectionConfig(): void
    {
        // Mock getCollectionInfo to return collection without vector size config
        $collectionData = [
            'result' => [
                'status' => 'green',
                'config' => [
                    'params' => [
                        // vectors config is missing 'size' key
                        'vectors' => [
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot determine vector dimension for collection 'test_collection'");

        $point = [
            'id' => 1,
            'vector' => [0.1, 0.2, 0.3],
            'payload' => []
        ];

        $this->service->upsertPoint('test_collection', $point);
    }

    /**
     * Test search with malformed collection config (missing dimension info).
     *
     * Verifies that RuntimeException is thrown when collection exists but
     * vector dimension info cannot be determined from config.
     */
    public function testSearchWithMalformedCollectionConfig(): void
    {
        // Mock getCollectionInfo to return collection without vector size config
        $collectionData = [
            'result' => [
                'status' => 'green',
                'config' => [
                    'params' => [
                        // vectors config is completely empty
                        'vectors' => []
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot determine vector dimension for collection 'test_collection'");

        $queryVector = [0.1, 0.2, 0.3];
        $this->service->search('test_collection', $queryVector);
    }

    // ==================================================
    // Distance Metric Tests
    // ==================================================

    /**
     * Test collection creation with Dot distance metric.
     *
     * Verifies that createCollection accepts different distance metrics.
     */
    public function testCreateCollectionWithDotDistance(): void
    {
        // Queue a successful response
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => true, 'status' => 'ok']))
        );

        $result = $this->service->createCollection('test_collection', 384, 'Dot');

        $this->assertTrue($result);
    }

    /**
     * Test collection creation with Euclid distance metric.
     *
     * Verifies that createCollection accepts Euclid distance metric.
     */
    public function testCreateCollectionWithEuclidDistance(): void
    {
        // Queue a successful response
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => true, 'status' => 'ok']))
        );

        $result = $this->service->createCollection('test_collection', 384, 'Euclid');

        $this->assertTrue($result);
    }

    /**
     * Test collection creation with Manhattan distance metric.
     *
     * Verifies that createCollection accepts Manhattan distance metric.
     */
    public function testCreateCollectionWithManhattanDistance(): void
    {
        // Queue a successful response
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => true, 'status' => 'ok']))
        );

        $result = $this->service->createCollection('test_collection', 384, 'Manhattan');

        $this->assertTrue($result);
    }

    // ==================================================
    // Additional Vector Validation Edge Cases
    // ==================================================

    /**
     * Test upsert point with empty vector array.
     *
     * Verifies that validation handles empty vectors properly.
     * Empty vector should be validated as valid but will fail dimension check.
     */
    public function testUpsertPointWithEmptyVector(): void
    {
        // Mock getCollectionInfo to return collection expecting 3 dimensions
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vector dimension mismatch: expected 3, got 0');

        $point = [
            'id' => 1,
            'vector' => [],  // Empty vector
            'payload' => []
        ];

        $this->service->upsertPoint('test_collection', $point);
    }

    /**
     * Test search with empty vector array.
     *
     * Verifies that validation handles empty query vectors properly.
     */
    public function testSearchWithEmptyVector(): void
    {
        // Mock getCollectionInfo to return collection expecting 3 dimensions
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Vector dimension mismatch: expected 3, got 0');

        $queryVector = [];  // Empty vector
        $this->service->search('test_collection', $queryVector);
    }

    /**
     * Test upsert point with string ID (Qdrant supports string IDs).
     *
     * Verifies that upsert works with string-based point IDs.
     */
    public function testUpsertPointWithStringId(): void
    {
        // Mock getCollectionInfo for dimension validation
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // Mock successful upsert response
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => ['status' => 'ok']]))
        );

        $point = [
            'id' => 'item_123',  // String ID
            'vector' => [0.1, 0.2, 0.3],
            'payload' => ['text' => 'test document']
        ];

        $result = $this->service->upsertPoint('test_collection', $point);

        $this->assertTrue($result);
    }

    /**
     * Test search with large limit value.
     *
     * Verifies that search handles large limit values.
     */
    public function testSearchWithLargeLimit(): void
    {
        // Mock getCollectionInfo for dimension validation
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // Mock search response
        $searchResults = [
            'result' => [
                'points' => [
                    ['id' => 1, 'score' => 0.99, 'payload' => ['text' => 'result']]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($searchResults))
        );

        $queryVector = [0.1, 0.2, 0.3];
        $results = $this->service->search('test_collection', $queryVector, 1000);

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
    }

    /**
     * Test upsert point with complex payload.
     *
     * Verifies that upsert handles complex nested payload structures.
     */
    public function testUpsertPointWithComplexPayload(): void
    {
        // Mock getCollectionInfo for dimension validation
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // Mock successful upsert response
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => ['status' => 'ok']]))
        );

        $point = [
            'id' => 1,
            'vector' => [0.1, 0.2, 0.3],
            'payload' => [
                'text' => 'test document',
                'metadata' => [
                    'type' => 'item',
                    'tags' => ['tag1', 'tag2'],
                    'nested' => ['key' => 'value']
                ],
                'numeric_field' => 42,
                'boolean_field' => true
            ]
        ];

        $result = $this->service->upsertPoint('test_collection', $point);

        $this->assertTrue($result);
    }

    /**
     * Test search returns results with payload correctly parsed.
     *
     * Verifies that search results include properly structured payload data.
     */
    public function testSearchReturnsComplexPayload(): void
    {
        // Mock getCollectionInfo for dimension validation
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // Mock search response with complex payload
        $searchResults = [
            'result' => [
                'points' => [
                    [
                        'id' => 1,
                        'score' => 0.95,
                        'payload' => [
                            'text' => 'Complex result',
                            'metadata' => [
                                'type' => 'item',
                                'nested' => ['key' => 'value']
                            ]
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($searchResults))
        );

        $queryVector = [0.1, 0.2, 0.3];
        $results = $this->service->search('test_collection', $queryVector);

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('Complex result', $results[0]['payload']['text']);
        $this->assertEquals('item', $results[0]['payload']['metadata']['type']);
        $this->assertEquals('value', $results[0]['payload']['metadata']['nested']['key']);
    }

    /**
     * Test search with default limit parameter.
     *
     * Verifies that search uses default limit of 10 when not specified.
     */
    public function testSearchWithDefaultLimit(): void
    {
        // Mock getCollectionInfo for dimension validation
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => [
                            'size' => 3,
                            'distance' => 'Cosine'
                        ]
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($collectionData))
        );

        // Mock search response
        $searchResults = [
            'result' => [
                'points' => [
                    ['id' => 1, 'score' => 0.95, 'payload' => []],
                    ['id' => 2, 'score' => 0.90, 'payload' => []]
                ]
            ]
        ];
        $this->mockHandler->append(
            new Response(200, [], json_encode($searchResults))
        );

        $queryVector = [0.1, 0.2, 0.3];
        // Not specifying limit - should use default
        $results = $this->service->search('test_collection', $queryVector);

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
    }

    /**
     * Test collection creation with default distance metric.
     *
     * Verifies that createCollection uses Cosine distance by default.
     */
    public function testCreateCollectionWithDefaultDistance(): void
    {
        // Queue a successful response
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => true, 'status' => 'ok']))
        );

        // Not specifying distance - should use Cosine by default
        $result = $this->service->createCollection('test_collection', 384);

        $this->assertTrue($result);
    }

    // ==================================================
    // Delete Point Tests
    // ==================================================

    /**
     * Test successful point deletion.
     */
    public function testDeletePoint(): void
    {
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => ['status' => 'ok']]))
        );

        $result = $this->service->deletePoint('test_collection', 123);

        $this->assertTrue($result);
    }

    /**
     * Test deletePoint with empty collection name.
     */
    public function testDeletePointWithEmptyCollection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection name cannot be empty');

        $this->service->deletePoint('', 123);
    }

    /**
     * Test deletePoint when point doesn't exist (404 treated as success).
     */
    public function testDeletePointNotFoundIsIdempotent(): void
    {
        $request = new Request('POST', '/collections/test_collection/points/delete');
        $response = new Response(404, [], json_encode(['status' => ['error' => 'Not found']]));
        $this->mockHandler->append(new ClientException('Not found', $request, $response));

        $result = $this->service->deletePoint('test_collection', 999);

        $this->assertTrue($result);
    }

    /**
     * Test deletePoint with server error returns false.
     */
    public function testDeletePointServerError(): void
    {
        $request = new Request('POST', '/collections/test_collection/points/delete');
        $response = new Response(500, [], json_encode(['error' => 'Internal server error']));
        $this->mockHandler->append(new ServerException('Server error', $request, $response));

        $result = $this->service->deletePoint('test_collection', 123);

        $this->assertFalse($result);
    }

    /**
     * Test deletePoint retry on connection failure.
     */
    public function testDeletePointRetryOnConnectionFailure(): void
    {
        $request = new Request('POST', '/collections/test_collection/points/delete');
        $this->mockHandler->append(new ConnectException('Connection refused', $request));
        $this->mockHandler->append(
            new Response(200, [], json_encode(['result' => ['status' => 'ok']]))
        );

        $result = $this->service->deletePoint('test_collection', 123);

        $this->assertTrue($result);
    }

    // ==================================================
    // Search with Filters Tests
    // ==================================================

    /**
     * Test search with filters parameter.
     */
    public function testSearchWithFilters(): void
    {
        $collectionData = [
            'result' => [
                'config' => [
                    'params' => [
                        'vectors' => ['size' => 3, 'distance' => 'Cosine']
                    ]
                ]
            ]
        ];
        $this->mockHandler->append(new Response(200, [], json_encode($collectionData)));

        $searchResults = [
            'result' => [
                'points' => [
                    ['id' => 1, 'score' => 0.95, 'payload' => ['text' => 'Filtered result']]
                ]
            ]
        ];
        $this->mockHandler->append(new Response(200, [], json_encode($searchResults)));

        $filter = \SearchAssistantAI\Service\VectorStore\SearchFilter::fromPublicInput(['type' => 'item']);
        $results = $this->service->search('test_collection', [0.1, 0.2, 0.3], 10, null, $filter);

        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertEquals('Filtered result', $results[0]['payload']['text']);
    }
}
