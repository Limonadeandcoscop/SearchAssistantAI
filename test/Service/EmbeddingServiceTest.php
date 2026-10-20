<?php
namespace SearchAssistantAITest\Service;

use PHPUnit\Framework\TestCase;
use SearchAssistantAI\Service\Embedding\EmbeddingService;
use SearchAssistantAI\Service\Embedding\EmbeddingInterface;
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
 * Unit tests for EmbeddingService.
 *
 * Tests cover text embedding operations, batch processing, caching,
 * error handling, and retry logic using Guzzle MockHandler to simulate HTTP responses.
 *
 * API Response Format:
 * The embeddings API returns JSON with structure:
 * {
 *   "embeddings": [[0.1, 0.2, ...], [0.3, 0.4, ...]],
 *   "dimensions": 384,
 *   "model": "sentence-transformers/all-MiniLM-L6-v2"
 * }
 */
class EmbeddingServiceTest extends TestCase
{
    /**
     * @var MockHandler
     */
    private $mockHandler;

    /**
     * @var EmbeddingService
     */
    private $service;

    /**
     * @var string
     */
    private $testCacheDir;

    /**
     * Set up test fixtures before each test.
     *
     * Creates an EmbeddingService instance with a mocked Guzzle HTTP client.
     * The mock handler allows us to queue predetermined responses for testing.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary cache directory for tests
        $this->testCacheDir = sys_get_temp_dir() . '/embedding_test_cache_' . uniqid();

        // Create a mock handler to queue responses
        $this->mockHandler = new MockHandler();

        // Create a handler stack with the mock
        $handlerStack = HandlerStack::create($this->mockHandler);

        // Create a Guzzle client with the mock handler
        $mockClient = new Client(['handler' => $handlerStack]);

        // Create service instance
        // Fixtures below build 384-dim mock embeddings; pin the service to
        // that dimension explicitly since the constructor default is now 1024.
        $this->service = new EmbeddingService('http://localhost:8000', 30.0, $this->testCacheDir, 384);

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
        // Clean up test cache directory
        if (is_dir($this->testCacheDir)) {
            $files = glob($this->testCacheDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($this->testCacheDir);
        }

        $this->mockHandler = null;
        $this->service = null;
        parent::tearDown();
    }

    /**
     * Helper method to create a valid API response with embeddings array structure.
     *
     * @param array $embeddings Array of embedding vectors
     * @return array Response structure matching embeddings API format
     */
    private function createEmbeddingsResponse(array $embeddings): array
    {
        return [
            'embeddings' => $embeddings,
            'dimensions' => 384,
            'model' => 'sentence-transformers/all-MiniLM-L6-v2'
        ];
    }

    /**
     * Helper method to create a mock 384-dimension embedding vector.
     *
     * @param float $fillValue Value to fill the vector with
     * @return array 384-element array of floats
     */
    private function createMockEmbedding(float $fillValue = 0.5): array
    {
        return array_fill(0, 384, $fillValue);
    }

    /**
     * Test that EmbeddingService implements EmbeddingInterface.
     */
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(EmbeddingInterface::class, $this->service);
    }

    /**
     * Test that service can be instantiated with default parameters.
     */
    public function testServiceInstantiation(): void
    {
        $service = new EmbeddingService();
        $this->assertInstanceOf(EmbeddingService::class, $service);
    }

    /**
     * Test that service can be instantiated with custom parameters.
     */
    public function testServiceInstantiationWithCustomParams(): void
    {
        $customCacheDir = sys_get_temp_dir() . '/custom_cache_' . uniqid();
        $service = new EmbeddingService('http://custom-host:8000', 60.0, $customCacheDir);
        $this->assertInstanceOf(EmbeddingService::class, $service);

        // Clean up
        if (is_dir($customCacheDir)) {
            rmdir($customCacheDir);
        }
    }

    // ==================================================
    // Single Text Embedding Tests
    // ==================================================

    /**
     * Test successful text embedding.
     *
     * Verifies that embed sends a POST request with correct payload
     * and returns a valid 384-dimension vector.
     */
    public function testEmbed(): void
    {
        // Create a mock 384-dimension embedding
        $mockEmbedding = $this->createMockEmbedding(0.5);

        // Queue a successful response with correct API structure
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $result = $this->service->embed('test text');

        $this->assertIsArray($result);
        $this->assertCount(384, $result);
        $this->assertEquals($mockEmbedding, $result);
    }

    /**
     * Test successful embedding with different valid text.
     *
     * Verifies that various valid text inputs are processed correctly.
     */
    public function testEmbedWithDifferentText(): void
    {
        // Create a mock 384-dimension embedding
        $mockEmbedding = $this->createMockEmbedding(0.75);

        // Queue a successful response
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $result = $this->service->embed('This is a longer piece of text to embed.');

        $this->assertIsArray($result);
        $this->assertCount(384, $result);
        $this->assertEquals($mockEmbedding, $result);
    }

    /**
     * Test successful embedding with special characters.
     *
     * Verifies that text with special characters is handled properly.
     */
    public function testEmbedWithSpecialCharacters(): void
    {
        // Create a mock 384-dimension embedding
        $mockEmbedding = $this->createMockEmbedding(0.3);

        // Queue a successful response
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $result = $this->service->embed('Text with special chars: @#$%^&*()');

        $this->assertIsArray($result);
        $this->assertCount(384, $result);
    }

    /**
     * Test embedding with Unicode characters.
     *
     * Verifies that UTF-8 text is handled correctly.
     */
    public function testEmbedWithUnicodeCharacters(): void
    {
        // Create a mock 384-dimension embedding
        $mockEmbedding = $this->createMockEmbedding(0.6);

        // Queue a successful response
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $result = $this->service->embed('Text with emoji and accents: cafe, naive');

        $this->assertIsArray($result);
        $this->assertCount(384, $result);
    }

    /**
     * Test embedding with various Unicode scripts.
     *
     * Verifies that multi-language text with different scripts is handled.
     */
    public function testEmbedWithMultiLanguageText(): void
    {
        $mockEmbedding = $this->createMockEmbedding(0.45);

        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        // Text with Chinese, Arabic, Hebrew characters
        $result = $this->service->embed('Hello World');

        $this->assertIsArray($result);
        $this->assertCount(384, $result);
    }

    /**
     * Test embedding caches result on first call.
     *
     * Verifies that successful embeddings are cached to the filesystem.
     */
    public function testEmbedCachesResult(): void
    {
        // Create a mock 384-dimension embedding
        $mockEmbedding = $this->createMockEmbedding(0.5);

        // Queue a successful response (only one call expected)
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        // First call - should make API request
        $result1 = $this->service->embed('test text');

        // Second call - should use cache (no API request)
        $result2 = $this->service->embed('test text');

        // Both results should be identical
        $this->assertEquals($result1, $result2);
        $this->assertCount(384, $result1);
        $this->assertCount(384, $result2);

        // Verify cache file was created
        $cacheFiles = glob($this->testCacheDir . '/*.json');
        $this->assertNotEmpty($cacheFiles);
        $this->assertCount(1, $cacheFiles);
    }

    /**
     * Test embedding returns cached result on subsequent calls.
     *
     * Verifies that cache is used correctly to avoid redundant API calls.
     */
    public function testEmbedReturnsCachedResult(): void
    {
        // Create a mock 384-dimension embedding
        $mockEmbedding = $this->createMockEmbedding(0.8);

        // Queue only one response - second call should use cache
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        // First call
        $result1 = $this->service->embed('cached text');
        $this->assertCount(384, $result1);

        // Second call - should NOT trigger API request (would fail if it did, as no response is queued)
        $result2 = $this->service->embed('cached text');
        $this->assertEquals($result1, $result2);
    }

    /**
     * Test embedding with empty text.
     *
     * Verifies that validation rejects empty text.
     */
    public function testEmbedWithEmptyText(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text input cannot be empty');

        $this->service->embed('');
    }

    /**
     * Test embedding with whitespace-only text.
     *
     * Verifies that validation rejects whitespace-only text.
     */
    public function testEmbedWithWhitespaceOnlyText(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text input cannot be empty');

        $this->service->embed('   ');
    }

    /**
     * Test embedding with whitespace-only text (tabs and newlines).
     *
     * Verifies that validation rejects various whitespace characters.
     */
    public function testEmbedWithWhitespaceOnlyTextVariations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text input cannot be empty');

        $this->service->embed(" \t \n ");
    }

    /**
     * Test embedding with invalid JSON response.
     *
     * Verifies that invalid JSON from API throws RuntimeException.
     */
    public function testEmbedWithInvalidJsonResponse(): void
    {
        // Queue a response with invalid JSON
        $this->mockHandler->append(
            new Response(200, [], 'not valid json')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON response from embeddings service');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with missing embeddings key in response.
     *
     * Verifies that responses without 'embeddings' array are rejected.
     */
    public function testEmbedWithMissingEmbeddingsKey(): void
    {
        // Queue a response without 'embeddings' key
        $this->mockHandler->append(
            new Response(200, [], json_encode(['dimensions' => 384, 'model' => 'test']))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected embeddings array in response from embeddings service');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with null embeddings in response.
     *
     * Verifies that null embeddings are rejected.
     */
    public function testEmbedWithNullEmbeddingsKey(): void
    {
        // Queue a response with null embeddings
        $this->mockHandler->append(
            new Response(200, [], json_encode(['embeddings' => null, 'dimensions' => 384]))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected embeddings array in response from embeddings service');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with empty embeddings array in response.
     *
     * Verifies that empty embeddings arrays are rejected.
     */
    public function testEmbedWithEmptyEmbeddingsArray(): void
    {
        // Queue a response with empty embeddings array
        $this->mockHandler->append(
            new Response(200, [], json_encode(['embeddings' => [], 'dimensions' => 384]))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected embeddings array in response from embeddings service');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with non-array embeddings value.
     *
     * Verifies that non-array embeddings values are rejected.
     */
    public function testEmbedWithNonArrayEmbeddings(): void
    {
        // Queue a response with string embeddings
        $this->mockHandler->append(
            new Response(200, [], json_encode(['embeddings' => 'not an array', 'dimensions' => 384]))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected embeddings array in response from embeddings service');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with wrong vector dimension (too small).
     *
     * Verifies that vectors with incorrect dimensions are rejected.
     */
    public function testEmbedWithWrongDimensionTooSmall(): void
    {
        // Create a vector with wrong dimension (256 instead of 384)
        $wrongDimensionEmbedding = array_fill(0, 256, 0.5);

        // Queue a response with wrong dimension
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$wrongDimensionEmbedding])))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid embedding dimension: expected 384, got 256');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with wrong vector dimension (too large).
     *
     * Verifies that vectors with incorrect dimensions are rejected.
     */
    public function testEmbedWithWrongDimensionTooLarge(): void
    {
        // Create a vector with wrong dimension (512 instead of 384)
        $wrongDimensionEmbedding = array_fill(0, 512, 0.5);

        // Queue a response with wrong dimension
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$wrongDimensionEmbedding])))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid embedding dimension: expected 384, got 512');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with non-numeric values in vector.
     *
     * Verifies that vectors with non-numeric values are rejected.
     */
    public function testEmbedWithNonNumericValues(): void
    {
        // Create a vector with a non-numeric value
        $invalidEmbedding = array_fill(0, 384, 0.5);
        $invalidEmbedding[100] = 'not a number';

        // Queue a response with invalid vector
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$invalidEmbedding])))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vector contains non-numeric value at index 100');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with null value in vector.
     *
     * Verifies that vectors with null values are rejected.
     */
    public function testEmbedWithNullValueInVector(): void
    {
        // Create a vector with a null value
        $invalidEmbedding = array_fill(0, 384, 0.5);
        $invalidEmbedding[50] = null;

        // Queue a response with invalid vector
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$invalidEmbedding])))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vector contains non-numeric value at index 50');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with ClientException (4xx error).
     *
     * Verifies that client errors are handled properly.
     */
    public function testEmbedWithClientException(): void
    {
        // Create a mock request
        $mockRequest = new Request('POST', '/embed');

        // Create a mock error response
        $mockResponse = new Response(
            400,
            [],
            json_encode(['error' => 'Invalid request format'])
        );

        // Queue a ClientException
        $this->mockHandler->append(
            new ClientException('Bad Request', $mockRequest, $mockResponse)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client error (HTTP 400): Invalid request format');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with ClientException containing detail field.
     *
     * Verifies that error messages from 'detail' field are extracted.
     */
    public function testEmbedWithClientExceptionDetailField(): void
    {
        // Create a mock request
        $mockRequest = new Request('POST', '/embed');

        // Create a mock error response with 'detail' field
        $mockResponse = new Response(
            422,
            [],
            json_encode(['detail' => 'Validation error: text too long'])
        );

        // Queue a ClientException
        $this->mockHandler->append(
            new ClientException('Unprocessable Entity', $mockRequest, $mockResponse)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client error (HTTP 422): Validation error: text too long');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with ClientException containing message field.
     *
     * Verifies that error messages from 'message' field are extracted.
     */
    public function testEmbedWithClientExceptionMessageField(): void
    {
        // Create a mock request
        $mockRequest = new Request('POST', '/embed');

        // Create a mock error response with 'message' field
        $mockResponse = new Response(
            400,
            [],
            json_encode(['message' => 'Request body is malformed'])
        );

        // Queue a ClientException
        $this->mockHandler->append(
            new ClientException('Bad Request', $mockRequest, $mockResponse)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client error (HTTP 400): Request body is malformed');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with ClientException containing array error field.
     *
     * Verifies that error arrays are JSON-encoded in the error message.
     */
    public function testEmbedWithClientExceptionArrayErrorField(): void
    {
        // Create a mock request
        $mockRequest = new Request('POST', '/embed');

        // Create a mock error response with array 'error' field
        $mockResponse = new Response(
            400,
            [],
            json_encode(['error' => ['code' => 'INVALID_TEXT', 'reason' => 'Empty text']])
        );

        // Queue a ClientException
        $this->mockHandler->append(
            new ClientException('Bad Request', $mockRequest, $mockResponse)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client error (HTTP 400):');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with ServerException (5xx error).
     *
     * Verifies that server errors are handled properly.
     */
    public function testEmbedWithServerException(): void
    {
        // Create a mock request
        $mockRequest = new Request('POST', '/embed');

        // Create a mock error response
        $mockResponse = new Response(500, [], json_encode(['error' => 'Internal server error']));

        // Queue a ServerException
        $this->mockHandler->append(
            new ServerException('Internal Server Error', $mockRequest, $mockResponse)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Embeddings service error (HTTP 500)');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with ServerException (503 Service Unavailable).
     *
     * Verifies that 503 errors are handled properly.
     */
    public function testEmbedWithServiceUnavailable(): void
    {
        // Create a mock request
        $mockRequest = new Request('POST', '/embed');

        // Create a mock error response
        $mockResponse = new Response(503, [], json_encode(['error' => 'Service unavailable']));

        // Queue a ServerException
        $this->mockHandler->append(
            new ServerException('Service Unavailable', $mockRequest, $mockResponse)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Embeddings service error (HTTP 503)');

        $this->service->embed('test text');
    }

    // ==================================================
    // Batch Embedding Tests
    // ==================================================

    /**
     * Test batch embedding with 10 texts using single API call.
     *
     * Verifies that embedBatch processes exactly 10 texts (matching apiBatchSize)
     * in a single API call and returns all embeddings in correct order.
     * This is the optimal case where batch size equals API batch size.
     */
    public function testEmbedBatch(): void
    {
        // Create 10 mock embeddings with distinct values
        $mockEmbeddings = [];
        for ($i = 0; $i < 10; $i++) {
            $mockEmbeddings[] = $this->createMockEmbedding(0.1 * ($i + 1));
        }

        // Queue a single batch response containing all 10 embeddings
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse($mockEmbeddings)))
        );

        // Create 10 texts
        $texts = [];
        for ($i = 1; $i <= 10; $i++) {
            $texts[] = "text number {$i}";
        }

        // Execute batch embedding
        $results = $this->service->embedBatch($texts);

        // Verify we got exactly 10 results
        $this->assertCount(10, $results);

        // Verify each result is a 384-dimension vector
        foreach ($results as $index => $result) {
            $this->assertCount(384, $result, "Result at index {$index} should have 384 dimensions");
        }

        // Verify results match expected embeddings in correct order
        for ($i = 0; $i < 10; $i++) {
            $this->assertEquals(
                $mockEmbeddings[$i],
                $results[$i],
                "Result at index {$i} should match expected embedding"
            );
        }

        // Verify only one API call was made (implicit: if more calls were needed,
        // mockHandler would throw an exception since we only queued one response)
    }

    /**
     * Test successful batch embedding with all uncached texts.
     *
     * Verifies that embedBatch makes a single API call for multiple uncached texts
     * and returns results in the correct order.
     */
    public function testEmbedBatchAllUncached(): void
    {
        // Create mock embeddings for 3 texts
        $mockEmbedding1 = $this->createMockEmbedding(0.1);
        $mockEmbedding2 = $this->createMockEmbedding(0.2);
        $mockEmbedding3 = $this->createMockEmbedding(0.3);

        // Queue a batch response containing all three embeddings
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([
                $mockEmbedding1,
                $mockEmbedding2,
                $mockEmbedding3
            ])))
        );

        $texts = ['first text', 'second text', 'third text'];
        $results = $this->service->embedBatch($texts);

        // Verify we got 3 results
        $this->assertCount(3, $results);

        // Verify each result is a 384-dimension vector
        $this->assertCount(384, $results[0]);
        $this->assertCount(384, $results[1]);
        $this->assertCount(384, $results[2]);

        // Verify results match expected embeddings
        $this->assertEquals($mockEmbedding1, $results[0]);
        $this->assertEquals($mockEmbedding2, $results[1]);
        $this->assertEquals($mockEmbedding3, $results[2]);
    }

    /**
     * Test batch embedding with all cached texts.
     *
     * Verifies that when all texts are already cached, no API call is made
     * and cached results are returned.
     */
    public function testEmbedBatchAllCached(): void
    {
        // Create mock embeddings
        $mockEmbedding1 = $this->createMockEmbedding(0.4);
        $mockEmbedding2 = $this->createMockEmbedding(0.5);

        // First, populate the cache by embedding each text individually
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding1])))
        );
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding2])))
        );

        $text1 = 'cached text one';
        $text2 = 'cached text two';

        $this->service->embed($text1);
        $this->service->embed($text2);

        // Now batch embed the same texts - should use cache, no API call
        // Note: We don't queue any new responses, so if API is called, test will fail
        $results = $this->service->embedBatch([$text1, $text2]);

        // Verify results match cached embeddings
        $this->assertCount(2, $results);
        $this->assertEquals($mockEmbedding1, $results[0]);
        $this->assertEquals($mockEmbedding2, $results[1]);
    }

    /**
     * Test batch embedding with mixed cached and uncached texts.
     *
     * Verifies the optimization: only uncached texts are sent to API,
     * while cached texts are retrieved from cache.
     */
    public function testEmbedBatchMixedCachedAndUncached(): void
    {
        // Create mock embeddings
        $cachedEmbedding = $this->createMockEmbedding(0.6);
        $uncachedEmbedding1 = $this->createMockEmbedding(0.7);
        $uncachedEmbedding2 = $this->createMockEmbedding(0.8);

        // First, cache one text
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$cachedEmbedding])))
        );

        $cachedText = 'already cached';
        $this->service->embed($cachedText);

        // Queue batch response for ONLY the uncached texts (2 embeddings)
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([
                $uncachedEmbedding1,
                $uncachedEmbedding2
            ])))
        );

        // Batch embed: 1 cached + 2 uncached
        $results = $this->service->embedBatch([
            $cachedText,           // Index 0 - cached
            'new text one',        // Index 1 - uncached
            'new text two'         // Index 2 - uncached
        ]);

        // Verify we got 3 results
        $this->assertCount(3, $results);

        // Verify the cached text used cache (index 0)
        $this->assertEquals($cachedEmbedding, $results[0]);

        // Verify the uncached texts got new embeddings (indices 1, 2)
        $this->assertEquals($uncachedEmbedding1, $results[1]);
        $this->assertEquals($uncachedEmbedding2, $results[2]);
    }

    /**
     * Test batch embedding maintains original order.
     *
     * Verifies that results are returned in the same order as input texts,
     * regardless of cache hits/misses.
     */
    public function testEmbedBatchMaintainsOrder(): void
    {
        // Create mock embeddings with distinct values for easy identification
        $embedding1 = $this->createMockEmbedding(0.11);
        $embedding2 = $this->createMockEmbedding(0.22);
        $embedding3 = $this->createMockEmbedding(0.33);

        // Queue batch response
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([
                $embedding1,
                $embedding2,
                $embedding3
            ])))
        );

        $texts = ['alpha', 'beta', 'gamma'];
        $results = $this->service->embedBatch($texts);

        // Verify order is maintained
        $this->assertCount(3, $results);
        $this->assertEquals($embedding1, $results[0], 'First result should match first embedding');
        $this->assertEquals($embedding2, $results[1], 'Second result should match second embedding');
        $this->assertEquals($embedding3, $results[2], 'Third result should match third embedding');

        // Verify results are indexed correctly (0, 1, 2)
        $this->assertArrayHasKey(0, $results);
        $this->assertArrayHasKey(1, $results);
        $this->assertArrayHasKey(2, $results);
    }

    /**
     * Test batch embedding with empty array.
     *
     * Verifies that validation rejects empty arrays.
     */
    public function testEmbedBatchWithEmptyArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Texts array cannot be empty');

        $this->service->embedBatch([]);
    }

    /**
     * Test batch embedding exceeding maximum batch size.
     *
     * Verifies that batch size limit (100 items) is enforced.
     */
    public function testEmbedBatchExceedsMaxBatchSize(): void
    {
        // Create an array with 101 texts (exceeds max of 100)
        $texts = array_fill(0, 101, 'test text');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch size exceeds maximum limit of 100');

        $this->service->embedBatch($texts);
    }

    /**
     * Test batch embedding with single text.
     *
     * Verifies that batch processing works correctly with a single item.
     */
    public function testEmbedBatchWithSingleText(): void
    {
        $mockEmbedding = $this->createMockEmbedding(0.9);

        // Queue a batch response with single embedding
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $results = $this->service->embedBatch(['single text']);

        $this->assertCount(1, $results);
        $this->assertEquals($mockEmbedding, $results[0]);
    }

    /**
     * Test batch embedding validates all vector dimensions.
     *
     * Verifies that dimension validation is applied to all embeddings in batch.
     */
    public function testEmbedBatchValidatesAllDimensions(): void
    {
        // Create a batch response where second embedding has wrong dimension
        $validEmbedding = $this->createMockEmbedding(0.5);
        $invalidEmbedding = array_fill(0, 256, 0.5); // Wrong dimension

        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([
                $validEmbedding,
                $invalidEmbedding
            ])))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid embedding dimension: expected 384, got 256');

        $this->service->embedBatch(['text one', 'text two']);
    }

    /**
     * Test batch embedding caches all results.
     *
     * Verifies that all embeddings from a batch call are cached for future use.
     */
    public function testEmbedBatchCachesAllResults(): void
    {
        // Create mock embeddings
        $embedding1 = $this->createMockEmbedding(0.15);
        $embedding2 = $this->createMockEmbedding(0.25);

        // Queue batch response
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([
                $embedding1,
                $embedding2
            ])))
        );

        $texts = ['cache test one', 'cache test two'];

        // First call - should make API request
        $results1 = $this->service->embedBatch($texts);

        // Second call - should use cache (no new API request queued)
        $results2 = $this->service->embedBatch($texts);

        // Both results should be identical
        $this->assertEquals($results1, $results2);

        // Verify cache files were created
        $cacheFiles = glob($this->testCacheDir . '/*.json');
        $this->assertCount(2, $cacheFiles, 'Should have 2 cache files for 2 texts');
    }

    /**
     * Test batch embedding with API returning wrong count.
     *
     * Verifies that batch processing validates the number of embeddings returned
     * matches the number of uncached texts sent.
     */
    public function testEmbedBatchWrongEmbeddingCount(): void
    {
        // Queue a batch response with wrong number of embeddings
        // We send 3 texts, but API returns only 2 embeddings
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([
                $this->createMockEmbedding(0.1),
                $this->createMockEmbedding(0.2)
                // Missing third embedding
            ])))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Batch API returned incorrect number of embeddings for chunk 0: expected 3, got 2');

        $this->service->embedBatch(['text one', 'text two', 'text three']);
    }

    /**
     * Test batch embedding with empty text in array.
     *
     * Verifies that validation is applied to each text in the batch.
     */
    public function testEmbedBatchWithEmptyTextInArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text input cannot be empty');

        $this->service->embedBatch(['valid text', '', 'another valid text']);
    }

    /**
     * Test batch embedding with whitespace-only text in array.
     *
     * Verifies that whitespace-only texts are rejected in batch processing.
     */
    public function testEmbedBatchWithWhitespaceOnlyText(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text input cannot be empty');

        $this->service->embedBatch(['valid text', '   ', 'another valid text']);
    }

    /**
     * Test batch embedding optimization reduces API calls.
     *
     * Verifies that batch processing is more efficient than individual calls
     * by making only one API request for multiple uncached texts.
     */
    public function testEmbedBatchOptimizationReducesApiCalls(): void
    {
        // Create 5 different embeddings
        $embeddings = [
            $this->createMockEmbedding(0.1),
            $this->createMockEmbedding(0.2),
            $this->createMockEmbedding(0.3),
            $this->createMockEmbedding(0.4),
            $this->createMockEmbedding(0.5)
        ];

        // Queue ONLY ONE batch response for all 5 texts
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse($embeddings)))
        );

        // Embed 5 texts in batch
        $results = $this->service->embedBatch([
            'text 1',
            'text 2',
            'text 3',
            'text 4',
            'text 5'
        ]);

        // Verify we got all 5 results from a single API call
        $this->assertCount(5, $results);

        // Verify each result has correct dimension
        foreach ($results as $index => $result) {
            $this->assertCount(384, $result, "Result at index {$index} should have 384 dimensions");
            $this->assertEquals($embeddings[$index], $result, "Result at index {$index} should match expected embedding");
        }

        // If more than one API call was made, mockHandler would throw an exception
        // because we only queued one response
    }

    /**
     * Test batch embedding with duplicate texts.
     *
     * Verifies that duplicate texts in the same batch are handled correctly,
     * with each instance getting the same embedding.
     */
    public function testEmbedBatchWithDuplicateTexts(): void
    {
        // Post-refactor the service sends every text to the API (no dedup) —
        // caching happens at the single-embed level, not the batch level.
        $embedding1 = $this->createMockEmbedding(0.31);
        $embedding2 = $this->createMockEmbedding(0.32);
        $embedding3 = $this->createMockEmbedding(0.31);

        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([
                $embedding1,
                $embedding2,
                $embedding3,
            ])))
        );

        $results = $this->service->embedBatch([
            'duplicate text',
            'unique text',
            'duplicate text',
        ]);

        $this->assertCount(3, $results);
        $this->assertEquals($results[0], $results[2], 'Duplicate texts should resolve to identical embeddings');
    }

    /**
     * Test batch embedding with ServerException.
     *
     * Verifies that server errors during batch operations are handled properly.
     */
    public function testEmbedBatchWithServerException(): void
    {
        // Create a mock request
        $mockRequest = new Request('POST', '/embed');

        // Create a mock error response
        $mockResponse = new Response(500, [], json_encode(['error' => 'Internal server error']));

        // Queue a ServerException
        $this->mockHandler->append(
            new ServerException('Internal Server Error', $mockRequest, $mockResponse)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Embeddings service error (HTTP 500)');

        $this->service->embedBatch(['text one', 'text two']);
    }

    /**
     * Test batch embedding with ClientException.
     *
     * Verifies that client errors during batch operations are handled properly.
     */
    public function testEmbedBatchWithClientException(): void
    {
        // Create a mock request
        $mockRequest = new Request('POST', '/embed');

        // Create a mock error response
        $mockResponse = new Response(
            400,
            [],
            json_encode(['error' => 'Batch size too large'])
        );

        // Queue a ClientException
        $this->mockHandler->append(
            new ClientException('Bad Request', $mockRequest, $mockResponse)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client error (HTTP 400): Batch size too large');

        $this->service->embedBatch(['text one', 'text two']);
    }

    // ==================================================
    // Cache Tests
    // ==================================================

    /**
     * Test clearCache removes all cached embeddings.
     *
     * Verifies that clearCache deletes all cache files and
     * subsequent calls require new API requests.
     */
    public function testClearCache(): void
    {
        // First, create some cached embeddings
        $embedding1 = $this->createMockEmbedding(0.5);
        $embedding2 = $this->createMockEmbedding(0.6);

        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$embedding1])))
        );
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$embedding2])))
        );

        // Embed two texts to populate cache
        $this->service->embed('first text');
        $this->service->embed('second text');

        // Verify cache files exist
        $cacheFiles = glob($this->testCacheDir . '/*.json');
        $this->assertCount(2, $cacheFiles, 'Should have 2 cache files');

        // Clear the cache
        $this->service->clearCache();

        // Verify cache files are gone
        $cacheFilesAfter = glob($this->testCacheDir . '/*.json');
        $this->assertCount(0, $cacheFilesAfter, 'Cache should be empty after clearCache');
    }

    /**
     * Test clearCache forces new API calls for previously cached texts.
     *
     * Verifies that after clearCache, embedding the same text
     * requires a new API request.
     */
    public function testClearCacheForcesNewApiCalls(): void
    {
        $text = 'cached then cleared';
        $embedding1 = $this->createMockEmbedding(0.5);
        $embedding2 = $this->createMockEmbedding(0.7);

        // First call - populates cache
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$embedding1])))
        );
        $result1 = $this->service->embed($text);
        $this->assertEquals($embedding1, $result1);

        // Clear the cache
        $this->service->clearCache();

        // Second call should require new API call
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$embedding2])))
        );
        $result2 = $this->service->embed($text);
        $this->assertEquals($embedding2, $result2);

        // Results should be different (new API response)
        $this->assertNotEquals($result1, $result2);
    }

    /**
     * Test clearCache handles non-existent cache directory gracefully.
     *
     * Verifies that clearCache doesn't throw when cache directory doesn't exist.
     */
    public function testClearCacheWithNonExistentDirectory(): void
    {
        // Create a service with a non-existent cache directory
        $nonExistentDir = sys_get_temp_dir() . '/nonexistent_cache_' . uniqid();
        $service = new EmbeddingService('http://localhost:8000', 30.0, $nonExistentDir);

        // Remove the directory that was created during construction
        if (is_dir($nonExistentDir)) {
            rmdir($nonExistentDir);
        }

        // clearCache should not throw
        $service->clearCache();

        // Test passed if no exception was thrown
        $this->assertTrue(true);
    }

    /**
     * Test clearCache handles empty cache directory gracefully.
     *
     * Verifies that clearCache works when cache directory exists but is empty.
     */
    public function testClearCacheWithEmptyDirectory(): void
    {
        // Cache directory exists but has no files
        $this->service->clearCache();

        // Should not throw, just do nothing
        $this->assertTrue(true);

        // Verify directory still exists
        $this->assertTrue(is_dir($this->testCacheDir));
    }

    /**
     * Test cache key is consistent for same text.
     *
     * Verifies that the same text always generates the same cache key.
     */
    public function testCacheKeyConsistency(): void
    {
        $mockEmbedding = $this->createMockEmbedding(0.5);

        // Only queue one response
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        // Embed same text multiple times
        $text = 'consistent cache key test';
        $result1 = $this->service->embed($text);
        $result2 = $this->service->embed($text);
        $result3 = $this->service->embed($text);

        // All results should be identical (from cache after first call)
        $this->assertEquals($result1, $result2);
        $this->assertEquals($result2, $result3);

        // Only one cache file should exist
        $cacheFiles = glob($this->testCacheDir . '/*.json');
        $this->assertCount(1, $cacheFiles);
    }

    /**
     * Test that different texts get different cache keys.
     *
     * Verifies that different texts generate different cache keys.
     */
    public function testDifferentTextsDifferentCacheKeys(): void
    {
        $embedding1 = $this->createMockEmbedding(0.1);
        $embedding2 = $this->createMockEmbedding(0.2);

        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$embedding1])))
        );
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$embedding2])))
        );

        // Embed two different texts
        $this->service->embed('text A');
        $this->service->embed('text B');

        // Two cache files should exist
        $cacheFiles = glob($this->testCacheDir . '/*.json');
        $this->assertCount(2, $cacheFiles);
    }

    // ==================================================
    // Retry Logic Tests
    // ==================================================

    /**
     * Test retry logic with transient network failure that succeeds on second attempt.
     *
     * Verifies that ConnectException triggers retry and eventual success.
     */
    public function testRetrySucceedsAfterTransientFailure(): void
    {
        // First attempt: network failure (ConnectException)
        $request = new Request('POST', '/embed');
        $this->mockHandler->append(
            new ConnectException('Connection refused', $request)
        );

        // Second attempt: success
        $mockEmbedding = $this->createMockEmbedding(0.5);
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $result = $this->service->embed('test text');

        $this->assertIsArray($result);
        $this->assertCount(384, $result);
        $this->assertEquals($mockEmbedding, $result);
    }

    /**
     * Test retry logic with two transient failures before success.
     *
     * Verifies that retry logic can recover from multiple consecutive failures.
     */
    public function testRetrySucceedsAfterTwoFailures(): void
    {
        // First attempt: network failure
        $request = new Request('POST', '/embed');
        $this->mockHandler->append(
            new ConnectException('Connection timeout', $request)
        );

        // Second attempt: network failure again
        $this->mockHandler->append(
            new ConnectException('Connection timeout', $request)
        );

        // Third attempt: success
        $mockEmbedding = $this->createMockEmbedding(0.7);
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $result = $this->service->embed('test text');

        $this->assertIsArray($result);
        $this->assertCount(384, $result);
        $this->assertEquals($mockEmbedding, $result);
    }

    /**
     * Test retry exhaustion when all attempts fail.
     *
     * Verifies that RuntimeException is thrown after max retry attempts (3).
     */
    public function testRetryExhaustion(): void
    {
        $request = new Request('POST', '/embed');

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
        $this->expectExceptionMessage('Embeddings request failed after 3 attempts');

        $this->service->embed('test text');
    }

    /**
     * Test that ClientException does NOT trigger retry logic.
     *
     * Verifies that 4xx errors are treated as non-retryable client errors.
     */
    public function testRetryDoesNotOccurForClientException(): void
    {
        // Queue a single 4xx error - should NOT retry
        $request = new Request('POST', '/embed');
        $response = new Response(
            400,
            [],
            json_encode(['error' => 'Invalid request'])
        );
        $this->mockHandler->append(new ClientException('Bad Request', $request, $response));

        // Do NOT queue additional responses - if retry occurs, the test will fail
        // because MockHandler will have no more responses

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client error (HTTP 400)');

        $this->service->embed('test text');
    }

    /**
     * Test that ServerException does NOT trigger retry logic.
     *
     * Verifies that 5xx errors are treated as non-retryable server errors.
     */
    public function testRetryDoesNotOccurForServerException(): void
    {
        // Queue a single 5xx error - should NOT retry
        $request = new Request('POST', '/embed');
        $response = new Response(500, [], json_encode(['error' => 'Internal server error']));
        $this->mockHandler->append(new ServerException('Server error', $request, $response));

        // Do NOT queue additional responses - if retry occurs, the test will fail
        // because MockHandler will have no more responses

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Embeddings service error (HTTP 500)');

        $this->service->embed('test text');
    }

    /**
     * Test retry logic for batch embedding operation.
     *
     * Verifies that embedBatch correctly implements retry logic for network failures.
     */
    public function testRetryForBatchOperation(): void
    {
        // First attempt: network failure
        $request = new Request('POST', '/embed');
        $this->mockHandler->append(
            new ConnectException('Connection lost', $request)
        );

        // Second attempt: success
        $embedding1 = $this->createMockEmbedding(0.1);
        $embedding2 = $this->createMockEmbedding(0.2);
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([
                $embedding1,
                $embedding2
            ])))
        );

        $results = $this->service->embedBatch(['text one', 'text two']);

        $this->assertCount(2, $results);
        $this->assertEquals($embedding1, $results[0]);
        $this->assertEquals($embedding2, $results[1]);
    }

    /**
     * Test retry logic with connection timeout exception.
     *
     * Verifies that connection timeout is handled as a retryable error.
     */
    public function testRetryWithConnectionTimeout(): void
    {
        // First attempt: connection timeout
        $request = new Request('POST', '/embed');
        $this->mockHandler->append(
            new ConnectException('Connection timed out', $request)
        );

        // Second attempt: success
        $mockEmbedding = $this->createMockEmbedding(0.45);
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $result = $this->service->embed('timeout test');

        $this->assertIsArray($result);
        $this->assertCount(384, $result);
    }

    /**
     * Test retry logic with network unreachable exception.
     *
     * Verifies that network unreachable errors trigger retry logic.
     */
    public function testRetryWithNetworkUnreachable(): void
    {
        // First attempt: network unreachable
        $request = new Request('POST', '/embed');
        $this->mockHandler->append(
            new ConnectException('Network unreachable', $request)
        );

        // Second attempt: success
        $mockEmbedding = $this->createMockEmbedding(0.55);
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $result = $this->service->embed('network test');

        $this->assertIsArray($result);
        $this->assertCount(384, $result);
        $this->assertEquals($mockEmbedding, $result);
    }

    /**
     * Test retry exhaustion during batch operation.
     *
     * Verifies that batch operations also respect retry limits.
     */
    public function testRetryExhaustionForBatchOperation(): void
    {
        $request = new Request('POST', '/embed');

        // Queue 3 connection failures
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
        $this->expectExceptionMessage('Embeddings request failed after 3 attempts');

        $this->service->embedBatch(['text one', 'text two']);
    }

    /**
     * Test cached embedding skips retry logic.
     *
     * Verifies that cached embeddings are returned immediately without retry logic.
     */
    public function testCachedEmbeddingSkipsRetry(): void
    {
        // First call to populate cache
        $mockEmbedding = $this->createMockEmbedding(0.88);
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $result1 = $this->service->embed('cached retry test');
        $this->assertCount(384, $result1);

        // Second call should use cache - no API call, no retry logic triggered
        // We intentionally don't queue any new responses
        // If retry logic is triggered, test will fail due to no responses in queue
        $result2 = $this->service->embed('cached retry test');

        $this->assertEquals($result1, $result2);
    }

    // ==================================================
    // Error Message Extraction Tests
    // ==================================================

    /**
     * Test error message extraction from ClientException with 'error' field.
     *
     * Verifies that meaningful error messages are extracted from 4xx responses.
     */
    public function testClientErrorMessageExtraction(): void
    {
        // Queue a 400 error with detailed error message
        $request = new Request('POST', '/embed');
        $response = new Response(
            400,
            [],
            json_encode(['error' => 'Text too long'])
        );
        $this->mockHandler->append(new ClientException('Bad Request', $request, $response));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client error (HTTP 400): Text too long');

        $this->service->embed('test text');
    }

    /**
     * Test error message extraction with detail field.
     *
     * Verifies that error messages from 'detail' field are extracted.
     */
    public function testClientErrorMessageExtractionDetailField(): void
    {
        // Queue a 422 error with 'detail' field
        $request = new Request('POST', '/embed');
        $response = new Response(
            422,
            [],
            json_encode(['detail' => 'Validation failed: invalid characters'])
        );
        $this->mockHandler->append(new ClientException('Unprocessable Entity', $request, $response));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client error (HTTP 422): Validation failed: invalid characters');

        $this->service->embed('test text');
    }

    /**
     * Test error message extraction when response body is not JSON.
     *
     * Verifies fallback error handling for non-JSON responses.
     */
    public function testClientErrorMessageExtractionNonJsonResponse(): void
    {
        // Queue a 400 error with plain text response
        $request = new Request('POST', '/embed');
        $response = new Response(400, [], 'Bad Request');
        $this->mockHandler->append(new ClientException('Bad Request', $request, $response));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client error (HTTP 400)');

        $this->service->embed('test text');
    }

    /**
     * Test error message extraction with no recognized error field.
     *
     * Verifies fallback when response has no error/detail/message field.
     */
    public function testClientErrorMessageExtractionNoRecognizedField(): void
    {
        // Queue a 400 error with unrecognized error format
        $request = new Request('POST', '/embed');
        $response = new Response(
            400,
            [],
            json_encode(['status' => 'failed', 'code' => 'ERR_001'])
        );
        $this->mockHandler->append(new ClientException('Bad Request', $request, $response));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Client error (HTTP 400): Request failed');

        $this->service->embed('test text');
    }

    // ==================================================
    // Edge Case Tests
    // ==================================================

    /**
     * Test embedding with very long text.
     *
     * Verifies that long text inputs are handled correctly (truncated if needed).
     */
    public function testEmbedWithVeryLongText(): void
    {
        // Create a very long text (10,000 characters)
        $longText = str_repeat('This is a long text. ', 500);

        $mockEmbedding = $this->createMockEmbedding(0.65);

        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $result = $this->service->embed($longText);

        $this->assertIsArray($result);
        $this->assertCount(384, $result);
    }

    /**
     * Test embedding with single character text.
     *
     * Verifies that minimal valid input is processed correctly.
     */
    public function testEmbedWithSingleCharacter(): void
    {
        $mockEmbedding = $this->createMockEmbedding(0.1);

        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $result = $this->service->embed('a');

        $this->assertIsArray($result);
        $this->assertCount(384, $result);
    }

    /**
     * Test embedding with numeric string.
     *
     * Verifies that numeric strings are handled as text.
     */
    public function testEmbedWithNumericString(): void
    {
        $mockEmbedding = $this->createMockEmbedding(0.2);

        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $result = $this->service->embed('12345');

        $this->assertIsArray($result);
        $this->assertCount(384, $result);
    }

    /**
     * Test embedding with newlines and tabs.
     *
     * Verifies that whitespace characters within text are handled
     * (normalized to single spaces by preprocessText).
     */
    public function testEmbedWithNewlinesAndTabs(): void
    {
        $mockEmbedding = $this->createMockEmbedding(0.35);

        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $result = $this->service->embed("Line 1\nLine 2\tTabbed");

        $this->assertIsArray($result);
        $this->assertCount(384, $result);
    }

    /**
     * Test batch embedding at maximum allowed size.
     *
     * Verifies that batches at the size limit (100 items) are processed correctly.
     */
    public function testEmbedBatchAtMaximumSize(): void
    {
        // The service chunks a 100-text batch into 10 API calls of 10 texts
        // each (apiBatchSize=10), so we queue ten separate responses.
        for ($chunk = 0; $chunk < 10; $chunk++) {
            $chunkEmbeddings = [];
            for ($i = 0; $i < 10; $i++) {
                $chunkEmbeddings[] = $this->createMockEmbedding(0.01 * ($chunk * 10 + $i));
            }
            $this->mockHandler->append(
                new Response(200, [], json_encode($this->createEmbeddingsResponse($chunkEmbeddings)))
            );
        }

        $texts = array_map(fn ($i) => "text number {$i}", range(1, 100));
        $results = $this->service->embedBatch($texts);

        $this->assertCount(100, $results);
        foreach ($results as $result) {
            $this->assertCount(384, $result);
        }
    }

    /**
     * Test embedding with HTTP 201 Created status code.
     *
     * Verifies that 201 status is treated as success (not just 200).
     */
    public function testEmbedWithCreatedStatus(): void
    {
        $mockEmbedding = $this->createMockEmbedding(0.4);

        // Queue a 201 Created response
        $this->mockHandler->append(
            new Response(201, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        $result = $this->service->embed('test text');

        $this->assertIsArray($result);
        $this->assertCount(384, $result);
    }

    /**
     * Test embedding with response body being empty.
     *
     * Verifies that empty response bodies are handled.
     */
    public function testEmbedWithEmptyResponseBody(): void
    {
        $this->mockHandler->append(
            new Response(200, [], '')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON response from embeddings service');

        $this->service->embed('test text');
    }

    /**
     * Test batch embedding with all texts being duplicates.
     *
     * Verifies efficient handling of batch with identical texts.
     */
    public function testEmbedBatchAllDuplicates(): void
    {
        // Post-refactor: no batch-level dedup — API sees every text.
        $mockEmbedding = $this->createMockEmbedding(0.99);

        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([
                $mockEmbedding, $mockEmbedding, $mockEmbedding,
            ])))
        );

        $results = $this->service->embedBatch(['same text', 'same text', 'same text']);

        $this->assertCount(3, $results);
        $this->assertEquals($results[0], $results[1]);
        $this->assertEquals($results[1], $results[2]);
    }

    /**
     * Test embedding with text that has leading/trailing whitespace.
     *
     * Verifies that leading/trailing whitespace is trimmed during preprocessing.
     */
    public function testEmbedWithLeadingTrailingWhitespace(): void
    {
        $mockEmbedding = $this->createMockEmbedding(0.5);

        // Only queue one response - both calls should result in same cache key
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        // First call with leading whitespace
        $result1 = $this->service->embed('   test text   ');

        // Second call without whitespace should hit cache
        $result2 = $this->service->embed('test text');

        $this->assertEquals($result1, $result2);
    }

    /**
     * Test embedding with multiple consecutive spaces.
     *
     * Verifies that multiple spaces are normalized to single space.
     */
    public function testEmbedWithMultipleConsecutiveSpaces(): void
    {
        $mockEmbedding = $this->createMockEmbedding(0.5);

        // Only queue one response - both calls should result in same cache key
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        // First call with multiple spaces
        $result1 = $this->service->embed('test    multiple    spaces');

        // Second call with single spaces should hit cache
        $result2 = $this->service->embed('test multiple spaces');

        $this->assertEquals($result1, $result2);
    }

    /**
     * Test embedding normalizes whitespace consistently.
     *
     * Verifies that different whitespace variations produce same cache key.
     */
    public function testEmbedWhitespaceNormalization(): void
    {
        $mockEmbedding = $this->createMockEmbedding(0.5);

        // Only queue one response
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$mockEmbedding])))
        );

        // Embed text with various whitespace
        $this->service->embed("  hello\n\nworld\t\ttest  ");

        // Same text with normalized whitespace should hit cache
        $result = $this->service->embed('hello world test');

        $this->assertCount(384, $result);

        // Should only be one cache file
        $cacheFiles = glob($this->testCacheDir . '/*.json');
        $this->assertCount(1, $cacheFiles);
    }

    /**
     * Test batch embedding handles response with missing 'embeddings' key.
     *
     * Verifies proper error handling when batch API response is malformed.
     */
    public function testEmbedBatchWithMissingEmbeddingsKey(): void
    {
        // Queue a response without 'embeddings' key
        $this->mockHandler->append(
            new Response(200, [], json_encode(['dimensions' => 384]))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected embeddings array in response from embeddings service');

        $this->service->embedBatch(['text one', 'text two']);
    }

    /**
     * Test embed returns first embedding from response array.
     *
     * Verifies that for single embed, only the first embedding is returned.
     */
    public function testEmbedReturnsFirstEmbedding(): void
    {
        $embedding1 = $this->createMockEmbedding(0.1);
        $embedding2 = $this->createMockEmbedding(0.9);

        // Simulate API returning multiple embeddings for single request (unusual but possible)
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$embedding1, $embedding2])))
        );

        $result = $this->service->embed('test text');

        // Should return the first embedding only
        $this->assertEquals($embedding1, $result);
    }

    /**
     * Test embedding with infinity values in response.
     *
     * Verifies that INF values are detected and rejected.
     * Note: JSON encoding converts INF to null, which then fails numeric check.
     */
    public function testEmbedWithInfinityValues(): void
    {
        // INF cannot be JSON encoded, so we test with null which would fail numeric check
        $invalidEmbedding = array_fill(0, 384, 0.5);
        $invalidEmbedding[50] = null;

        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$invalidEmbedding])))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vector contains non-numeric value at index 50');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with boolean value in vector.
     *
     * Verifies that boolean values in vectors are rejected.
     */
    public function testEmbedWithBooleanValue(): void
    {
        $invalidEmbedding = array_fill(0, 384, 0.5);
        $invalidEmbedding[75] = true;

        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$invalidEmbedding])))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vector contains non-numeric value at index 75');

        $this->service->embed('test text');
    }

    /**
     * Test embedding with array value in vector.
     *
     * Verifies that nested arrays in vectors are rejected.
     */
    public function testEmbedWithArrayValueInVector(): void
    {
        $invalidEmbedding = array_fill(0, 384, 0.5);
        $invalidEmbedding[100] = [0.1, 0.2];

        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$invalidEmbedding])))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vector contains non-numeric value at index 100');

        $this->service->embed('test text');
    }

    /**
     * Test service maintains state across multiple operations.
     *
     * Verifies that the service correctly handles a sequence of
     * embed, batch embed, cache clear, and re-embed operations.
     */
    public function testServiceStateThroughMultipleOperations(): void
    {
        // Step 1: Embed single text
        $embedding1 = $this->createMockEmbedding(0.1);
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$embedding1])))
        );
        $result1 = $this->service->embed('text one');

        // Step 2: Batch embed including cached text
        $embedding2 = $this->createMockEmbedding(0.2);
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$embedding2])))
        );
        $results = $this->service->embedBatch(['text one', 'text two']);
        $this->assertEquals($embedding1, $results[0]); // From cache
        $this->assertEquals($embedding2, $results[1]); // From API

        // Step 3: Clear cache
        $this->service->clearCache();

        // Step 4: Re-embed previously cached text (should require new API call)
        $embedding3 = $this->createMockEmbedding(0.3);
        $this->mockHandler->append(
            new Response(200, [], json_encode($this->createEmbeddingsResponse([$embedding3])))
        );
        $result3 = $this->service->embed('text one');
        $this->assertEquals($embedding3, $result3);

        // Verify different embedding was returned after cache clear
        $this->assertNotEquals($result1, $result3);
    }
}
