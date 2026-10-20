<?php
namespace SearchAssistantAITest\Service;

use PHPUnit\Framework\TestCase;
use SearchAssistantAI\Service\Llm\OllamaService;
use SearchAssistantAI\Service\Llm\LlmInterface;
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
 * Unit tests for OllamaService.
 *
 * Tests cover conversational queries, criteria extraction, response formatting,
 * model management, error handling, and retry logic using Guzzle MockHandler
 * to simulate HTTP responses from Ollama's local LLM API.
 *
 * Focus areas:
 * - Ollama not running (connection errors)
 * - Model not pulled (404 errors with actionable messages)
 * - Invalid URL scenarios
 * - Retry logic with exponential backoff
 */
class OllamaServiceTest extends TestCase
{
    /**
     * @var MockHandler
     */
    private $mockHandler;

    /**
     * @var OllamaService
     */
    private $service;

    /**
     * @var string Test Ollama URL
     */
    private $testOllamaUrl = 'http://localhost:11434';

    /**
     * @var string Test model name
     */
    private $testModel = 'llama3.2';

    /**
     * Set up test fixtures before each test.
     *
     * Creates an OllamaService instance with a mocked Guzzle HTTP client.
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

        // Create service instance with test parameters
        $this->service = new OllamaService($this->testOllamaUrl, $this->testModel, 30.0);

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

    // ==================================================
    // Service Instantiation Tests
    // ==================================================

    /**
     * Test that OllamaService implements LlmInterface.
     */
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(LlmInterface::class, $this->service);
    }

    /**
     * Test that service can be instantiated with default parameters.
     */
    public function testServiceInstantiation(): void
    {
        $service = new OllamaService();
        $this->assertInstanceOf(OllamaService::class, $service);
    }

    /**
     * Test that service can be instantiated with custom parameters.
     */
    public function testServiceInstantiationWithCustomParams(): void
    {
        $service = new OllamaService('http://custom:11434', 'mistral', 60.0);
        $this->assertInstanceOf(OllamaService::class, $service);
    }

    /**
     * Test that constructor rejects empty URL.
     */
    public function testConstructorWithEmptyUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ollama URL cannot be empty');

        new OllamaService('');
    }

    /**
     * Test that constructor rejects whitespace-only URL.
     */
    public function testConstructorWithWhitespaceUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ollama URL cannot be empty');

        new OllamaService('   ');
    }

    /**
     * Test that constructor rejects empty model name.
     */
    public function testConstructorWithEmptyModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Model name cannot be empty');

        new OllamaService('http://localhost:11434', '');
    }

    /**
     * Test that constructor rejects whitespace-only model name.
     */
    public function testConstructorWithWhitespaceModel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Model name cannot be empty');

        new OllamaService('http://localhost:11434', '   ');
    }

    // ==================================================
    // Query Method Tests - Successful API Responses
    // ==================================================

    /**
     * Test successful query with simple user message.
     *
     * Verifies that query sends a POST request to /api/chat endpoint
     * and correctly extracts text from the response.
     */
    public function testQuerySuccessful(): void
    {
        // Queue a successful response (Ollama format)
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'model' => 'llama3.2',
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Bonjour! Comment puis-je vous aider?'
                ],
                'done' => true,
                'total_duration' => 1234567890,
                'prompt_eval_count' => 10,
                'eval_count' => 20
            ]))
        );

        $result = $this->service->query('Bonjour');

        $this->assertEquals('Bonjour! Comment puis-je vous aider?', $result);
    }

    /**
     * Test successful query with long response text.
     *
     * Verifies that longer responses are correctly extracted without truncation.
     */
    public function testQuerySuccessfulWithLongResponse(): void
    {
        $longText = str_repeat('Ceci est une longue réponse. ', 100);

        // Queue a successful response with long text
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'model' => 'llama3.2',
                'message' => [
                    'role' => 'assistant',
                    'content' => $longText
                ],
                'done' => true,
                'prompt_eval_count' => 50,
                'eval_count' => 500
            ]))
        );

        $result = $this->service->query('Raconte-moi une longue histoire');

        $this->assertEquals($longText, $result);
    }

    // ==================================================
    // Error Handling Tests - Ollama Not Running
    // ==================================================

    /**
     * Test error handling when Ollama service is not running.
     *
     * Verifies that ConnectException triggers retry logic and eventually
     * throws a RuntimeException with actionable error message.
     */
    public function testQueryWhenOllamaNotRunning(): void
    {
        // Queue 3 connection failures (max retries)
        $this->mockHandler->append(
            new ConnectException('Connection refused', new Request('POST', 'api/chat')),
            new ConnectException('Connection refused', new Request('POST', 'api/chat')),
            new ConnectException('Connection refused', new Request('POST', 'api/chat'))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to connect to Ollama API at/');

        $this->service->query('Bonjour');
    }

    /**
     * Test that error message includes actionable guidance when Ollama is not running.
     *
     * Verifies that the error message tells users to verify Ollama is running
     * and provides guidance about correct URL configuration.
     */
    public function testConnectionErrorMessageIsActionable(): void
    {
        // Queue 3 connection failures
        $this->mockHandler->append(
            new ConnectException('Connection refused', new Request('POST', 'api/chat')),
            new ConnectException('Connection refused', new Request('POST', 'api/chat')),
            new ConnectException('Connection refused', new Request('POST', 'api/chat'))
        );

        try {
            $this->service->query('Bonjour');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            // Verify actionable guidance is present
            $this->assertStringContainsString('Ollama is running', $message);
            $this->assertStringContainsString('localhost:11434', $message);
            $this->assertStringContainsString('ollama:11434', $message);
        }
    }

    /**
     * Test connection timeout handling.
     *
     * Verifies that timeout errors are properly handled with retry logic.
     */
    public function testQueryConnectionTimeout(): void
    {
        // Queue 3 timeout failures
        $this->mockHandler->append(
            new ConnectException('Connection timed out', new Request('POST', 'api/chat')),
            new ConnectException('Connection timed out', new Request('POST', 'api/chat')),
            new ConnectException('Connection timed out', new Request('POST', 'api/chat'))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to connect.*after 3 attempts/');

        $this->service->query('Bonjour');
    }

    /**
     * Test retry logic succeeds after initial failures.
     *
     * Verifies that ConnectException triggers retry with eventual success.
     */
    public function testQueryRetryOnNetworkFailure(): void
    {
        // Queue 2 network failures, then a success
        $this->mockHandler->append(
            new ConnectException('Connection refused', new Request('POST', 'api/chat')),
            new ConnectException('Connection timeout', new Request('POST', 'api/chat')),
            new Response(200, [], json_encode([
                'model' => 'llama3.2',
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Success after retries'
                ],
                'done' => true,
                'prompt_eval_count' => 10,
                'eval_count' => 15
            ]))
        );

        $result = $this->service->query('Bonjour');

        $this->assertEquals('Success after retries', $result);
    }

    // ==================================================
    // Error Handling Tests - Model Not Pulled
    // ==================================================

    /**
     * Test error handling when model is not pulled/available.
     *
     * Verifies that 404 error with 'model not found' message provides
     * actionable instructions on how to pull the model.
     */
    public function testQueryWithModelNotFound(): void
    {
        // Queue a model not found error response (Ollama returns this as 404)
        $this->mockHandler->append(
            new ClientException(
                'Model not found',
                new Request('POST', 'api/chat'),
                new Response(404, [], json_encode([
                    'error' => 'model "nonexistent-model" not found, try pulling it first'
                ]))
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Model not found.*docker exec ollama ollama pull/');

        $this->service->query('Bonjour');
    }

    /**
     * Test that model not found error includes specific pull command.
     *
     * Verifies the error message includes the exact command to pull the model.
     */
    public function testModelNotFoundErrorIncludesPullCommand(): void
    {
        // Queue a model not found error response
        $this->mockHandler->append(
            new ClientException(
                'Model not found',
                new Request('POST', 'api/chat'),
                new Response(404, [], json_encode([
                    'error' => 'model "llama3.2" does not exist'
                ]))
            )
        );

        try {
            $this->service->query('Bonjour');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            // Verify the model name is mentioned in the pull command
            $this->assertStringContainsString('ollama pull', $message);
            $this->assertStringContainsString($this->testModel, $message);
        }
    }

    /**
     * Test error handling when model name doesn't exist (variation).
     *
     * Verifies that different 'not found' error formats are properly detected.
     */
    public function testQueryWithModelDoesNotExist(): void
    {
        // Queue an alternative error message format
        $this->mockHandler->append(
            new ClientException(
                'Model does not exist',
                new Request('POST', 'api/chat'),
                new Response(404, [], json_encode([
                    'error' => 'model does not exist'
                ]))
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Model not found/');

        $this->service->query('Bonjour');
    }

    // ==================================================
    // Error Handling Tests - Invalid URL Scenarios
    // ==================================================

    /**
     * Test error handling for invalid host in URL.
     *
     * Verifies that DNS resolution failures result in clear error messages.
     */
    public function testQueryWithInvalidHost(): void
    {
        // Queue a DNS resolution failure
        $this->mockHandler->append(
            new ConnectException(
                "cURL error 6: Could not resolve host: invalid-host-name",
                new Request('POST', 'api/chat')
            ),
            new ConnectException(
                "cURL error 6: Could not resolve host: invalid-host-name",
                new Request('POST', 'api/chat')
            ),
            new ConnectException(
                "cURL error 6: Could not resolve host: invalid-host-name",
                new Request('POST', 'api/chat')
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to connect to Ollama API/');

        $this->service->query('Bonjour');
    }

    /**
     * Test error handling for wrong port.
     *
     * Verifies that connection refused errors include helpful messages.
     */
    public function testQueryWithWrongPort(): void
    {
        // Queue connection refused errors (wrong port)
        $this->mockHandler->append(
            new ConnectException(
                "cURL error 7: Failed to connect to localhost port 99999",
                new Request('POST', 'api/chat')
            ),
            new ConnectException(
                "cURL error 7: Failed to connect to localhost port 99999",
                new Request('POST', 'api/chat')
            ),
            new ConnectException(
                "cURL error 7: Failed to connect to localhost port 99999",
                new Request('POST', 'api/chat')
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to connect.*admin settings/');

        $this->service->query('Bonjour');
    }

    // ==================================================
    // Error Handling Tests - Server Errors
    // ==================================================

    /**
     * Test error handling for 500 Internal Server Error.
     *
     * Verifies that Ollama server errors are properly handled.
     */
    public function testQueryWithServerError500(): void
    {
        // Queue a 500 server error response
        $this->mockHandler->append(
            new ServerException(
                'Internal Server Error',
                new Request('POST', 'api/chat'),
                new Response(500, [], json_encode([
                    'error' => 'Internal server error occurred'
                ]))
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ollama API server error (HTTP 500)');

        $this->service->query('Bonjour');
    }

    /**
     * Test error handling for 503 Service Unavailable.
     *
     * Verifies that service unavailable errors are properly handled.
     */
    public function testQueryWithServerError503(): void
    {
        // Queue a 503 service unavailable response
        $this->mockHandler->append(
            new ServerException(
                'Service Unavailable',
                new Request('POST', 'api/chat'),
                new Response(503, [], json_encode([
                    'error' => 'Service temporarily unavailable'
                ]))
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ollama API server error (HTTP 503)');

        $this->service->query('Bonjour');
    }

    // ==================================================
    // Error Handling Tests - Response Parsing Errors
    // ==================================================

    /**
     * Test error handling for invalid JSON response.
     *
     * Verifies that malformed JSON responses are properly handled.
     */
    public function testQueryWithInvalidJsonResponse(): void
    {
        // Queue an invalid JSON response
        $this->mockHandler->append(
            new Response(200, [], 'not valid json {{{')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON response from Ollama API');

        $this->service->query('Bonjour');
    }

    /**
     * Test error handling for missing message object in response.
     *
     * Verifies that responses without proper structure are handled.
     */
    public function testQueryWithMissingMessageObject(): void
    {
        // Queue a response missing the message object
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'model' => 'llama3.2',
                'done' => true
                // Missing 'message' object
            ]))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid response structure: missing message object');

        $this->service->query('Bonjour');
    }

    /**
     * Test error handling for missing content in message.
     *
     * Verifies that responses without content are handled.
     */
    public function testQueryWithMissingContent(): void
    {
        // Queue a response with message but no content
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'model' => 'llama3.2',
                'message' => [
                    'role' => 'assistant'
                    // Missing 'content'
                ],
                'done' => true
            ]))
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid response structure: missing content in message');

        $this->service->query('Bonjour');
    }

    // ==================================================
    // testConnection() Method Tests
    // ==================================================

    /**
     * Test successful connection test.
     *
     * Verifies that testConnection returns success when Ollama is reachable.
     */
    public function testConnectionSuccess(): void
    {
        // Queue a successful /api/tags response
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'models' => [
                    ['name' => 'llama3.2:latest', 'size' => 1234567890],
                    ['name' => 'mistral:latest', 'size' => 987654321]
                ]
            ]))
        );

        $result = $this->service->testConnection();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Connected to Ollama', $result['message']);
        $this->assertCount(2, $result['models']);
        $this->assertTrue($result['model_available']);
    }

    /**
     * Test connection test with model not available.
     *
     * Verifies that testConnection warns when configured model is not pulled.
     */
    public function testConnectionWithModelNotAvailable(): void
    {
        // Queue a response where configured model is not in the list
        $this->mockHandler->append(
            new Response(200, [], json_encode([
                'models' => [
                    ['name' => 'mistral:latest', 'size' => 987654321]
                ]
            ]))
        );

        $result = $this->service->testConnection();

        $this->assertTrue($result['success']);
        $this->assertFalse($result['model_available']);
        $this->assertStringContainsString('WARNING', $result['message']);
        $this->assertStringContainsString('ollama pull', $result['message']);
    }

    /**
     * Test connection test when Ollama is not running.
     *
     * Verifies that testConnection returns helpful error when service is down.
     */
    public function testConnectionWhenOllamaNotRunning(): void
    {
        // Queue a connection failure
        $this->mockHandler->append(
            new ConnectException('Connection refused', new Request('GET', 'api/tags'))
        );

        $result = $this->service->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Cannot connect to Ollama', $result['message']);
        $this->assertStringContainsString('Ollama is running', $result['message']);
        $this->assertEmpty($result['models']);
        $this->assertFalse($result['model_available']);
    }

    /**
     * Test connection test with invalid JSON response.
     *
     * Verifies that testConnection handles malformed responses.
     */
    public function testConnectionWithInvalidJson(): void
    {
        // Queue an invalid JSON response
        $this->mockHandler->append(
            new Response(200, [], 'not valid json')
        );

        $result = $this->service->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid JSON', $result['message']);
    }

    /**
     * Test connection test with server error.
     *
     * Verifies that testConnection handles 5xx errors.
     */
    public function testConnectionWithServerError(): void
    {
        // Queue a 500 server error
        $this->mockHandler->append(
            new ServerException(
                'Internal Server Error',
                new Request('GET', 'api/tags'),
                new Response(500, [], 'Internal Server Error')
            )
        );

        $result = $this->service->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('server error', $result['message']);
        $this->assertStringContainsString('500', $result['message']);
    }

    // ==================================================
    // Input Validation Tests
    // ==================================================

    /**
     * Test that query rejects empty message.
     */
    public function testQueryWithEmptyMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('User message cannot be empty');

        $this->service->query('');
    }

    /**
     * Test that query rejects whitespace-only message.
     */
    public function testQueryWithWhitespaceMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('User message cannot be empty');

        $this->service->query('   ');
    }

    /**
     * Test that setModel rejects empty model name.
     */
    public function testSetModelWithEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Model name cannot be empty');

        $this->service->setModel('');
    }

    // ==================================================
    // Format Response Tests
    // ==================================================

    /**
     * Test formatResponse with valid Ollama response.
     */
    public function testFormatResponseWithValidResponse(): void
    {
        $apiResponse = [
            'model' => 'llama3.2',
            'message' => [
                'role' => 'assistant',
                'content' => 'Test response content'
            ],
            'done' => true,
            'done_reason' => 'stop',
            'prompt_eval_count' => 100,
            'eval_count' => 50,
            'total_duration' => 1234567890
        ];

        $result = $this->service->formatResponse($apiResponse);

        $this->assertEquals('Test response content', $result['text']);
        $this->assertEquals(100, $result['usage']['input_tokens']);
        $this->assertEquals(50, $result['usage']['output_tokens']);
        $this->assertEquals(150, $result['usage']['total_tokens']);
        $this->assertEquals('llama3.2', $result['metadata']['model']);
        $this->assertEquals('stop', $result['metadata']['stop_reason']);
        $this->assertTrue($result['metadata']['done']);
    }

    /**
     * Test formatResponse with missing message object.
     */
    public function testFormatResponseWithMissingMessage(): void
    {
        $apiResponse = [
            'model' => 'llama3.2',
            'done' => true
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid API response: missing message object');

        $this->service->formatResponse($apiResponse);
    }

    // ==================================================
    // Extract Criteria Tests (Graceful Degradation)
    // ==================================================

    /**
     * Test extractCriteria handles connection errors gracefully.
     *
     * Verifies that criteria extraction returns empty array on error
     * instead of throwing exceptions (graceful degradation).
     */
    public function testExtractCriteriaGracefulDegradationOnConnectionError(): void
    {
        // Queue 3 connection failures
        $this->mockHandler->append(
            new ConnectException('Connection refused', new Request('POST', 'api/chat')),
            new ConnectException('Connection refused', new Request('POST', 'api/chat')),
            new ConnectException('Connection refused', new Request('POST', 'api/chat'))
        );

        $result = $this->service->extractCriteria('Montrez-moi les peintures de Monet');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test extractCriteria handles server errors gracefully.
     */
    public function testExtractCriteriaGracefulDegradationOnServerError(): void
    {
        // Queue a server error
        $this->mockHandler->append(
            new ServerException(
                'Internal Server Error',
                new Request('POST', 'api/chat'),
                new Response(500, [], 'Error')
            )
        );

        $result = $this->service->extractCriteria('Montrez-moi les peintures de Monet');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test extractCriteria with empty message throws exception.
     */
    public function testExtractCriteriaWithEmptyMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('User message cannot be empty');

        $this->service->extractCriteria('');
    }
}
