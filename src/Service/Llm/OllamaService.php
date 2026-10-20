<?php
declare(strict_types=1);
namespace SearchAssistantAI\Service\Llm;

use SearchAssistantAI\Service\Prompt\FilePromptProvider;
use SearchAssistantAI\Service\FileLogger;

use RuntimeException;
use InvalidArgumentException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

/**
 * Service implementation for interacting with a self-hosted LLM.
 *
 * Provides methods for conversational AI queries with context management,
 * response formatting, and dynamic model selection for French language search_assistant.
 * This is a cost-effective alternative to Claude API using local LLM execution.
 *
 * Speaks either the native Ollama API (/api/chat, /api/tags) or the
 * OpenAI-compatible API (/v1/chat/completions, /v1/models) exposed by vLLM,
 * TGI, LiteLLM and Ollama itself.
 */
class OllamaService implements LlmInterface
{
    public const API_FORMAT_OLLAMA = 'ollama';
    public const API_FORMAT_OPENAI = 'openai';

    /**
     * @var Client Guzzle HTTP client for API requests
     */
    private $client;

    /**
     * @var string Ollama server URL
     */
    private $ollamaUrl;

    /**
     * @var string Current active Ollama model name
     */
    private $currentModel;

    /**
     * @var string One of the API_FORMAT_* constants
     */
    private $apiFormat;

    /**
     * @var int Maximum number of retry attempts for network failures
     */
    private $maxRetries = 3;

    /**
     * @var list<int> Retry delays in seconds for exponential backoff (1s, 2s, 4s)
     */
    private $retryDelays = [1, 2, 4];

    /**
     * @var string|null Admin-provided system prompt; null → fall back to on-disk file.
     */
    private $systemInstructionsOverride = null;

    /**
     * Constructor.
     *
     * @param string $ollamaUrl Ollama server URL (default: http://ollama:11434)
     * @param string $currentModel Initial model name (default: mistral)
     * @param float $timeout Request timeout in seconds (default: 30.0)
     * @param string|null $systemInstructions Pre-resolved system prompt; if null, reads the default file.
     * @param string $apiFormat API_FORMAT_OLLAMA (native) or API_FORMAT_OPENAI (/v1/*)
     * @throws InvalidArgumentException If URL is empty
     */
    public function __construct(string $ollamaUrl = 'http://ollama:11434', string $currentModel = 'mistral', float $timeout = 30.0, ?string $systemInstructions = null, string $apiFormat = self::API_FORMAT_OLLAMA)
    {
        $this->systemInstructionsOverride = $systemInstructions;
        // Validate URL
        if (empty(trim($ollamaUrl))) {
            throw new InvalidArgumentException('Ollama URL cannot be empty');
        }

        // Validate model name
        if (empty(trim($currentModel))) {
            throw new InvalidArgumentException('Model name cannot be empty');
        }

        $this->apiFormat = $apiFormat === self::API_FORMAT_OPENAI ? self::API_FORMAT_OPENAI : self::API_FORMAT_OLLAMA;
        $this->ollamaUrl = rtrim($ollamaUrl, '/');
        if ($this->apiFormat === self::API_FORMAT_OPENAI) {
            // Accept both "http://host:port" and "http://host:port/v1"; routes add the /v1 prefix.
            $this->ollamaUrl = (string) preg_replace('#/v1$#', '', $this->ollamaUrl);
        }
        $this->currentModel = $currentModel;

        // Log configuration for debugging
        FileLogger::getInstance()->info("OllamaService: Initializing with URL: {$this->ollamaUrl} (API format: {$this->apiFormat})");
        FileLogger::getInstance()->info("OllamaService: Using model: {$this->currentModel}");

        // Initialize Guzzle HTTP client
        $this->client = new Client([
            'base_uri' => $this->ollamaUrl . '/',
            'timeout' => $timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]
        ]);
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
                $currentAttempt = $attempt + 1;
                FileLogger::getInstance()->debug("OllamaService::executeWithRetry() - Attempt {$currentAttempt}/{$this->maxRetries}");
                return $operation();
            } catch (ConnectException $e) {
                $lastException = $e;
                $attempt++;

                FileLogger::getInstance()->error("OllamaService::executeWithRetry() - Connection failed on attempt {$attempt}: " . $e->getMessage());

                if ($attempt < $this->maxRetries) {
                    $delay = $this->retryDelays[$attempt - 1];
                    FileLogger::getInstance()->debug("OllamaService::executeWithRetry() - Waiting {$delay}s before retry attempt " . ($attempt + 1));
                    sleep($delay);
                }
            }
        }

        FileLogger::getInstance()->error("OllamaService::executeWithRetry() - All {$this->maxRetries} retry attempts exhausted. Final error: " . $lastException->getMessage());

        throw new RuntimeException(
            "Failed to connect to Ollama API at '{$this->ollamaUrl}' after {$this->maxRetries} attempts. " .
            "Please verify: (1) Ollama is running, (2) The URL is correct in admin settings " .
            "(use 'http://localhost:11434' for local Ollama or 'http://ollama:11434' for Docker). " .
            "Error: " . $lastException->getMessage(),
            0,
            $lastException
        );
    }

    private function isOpenAi(): bool
    {
        return $this->apiFormat === self::API_FORMAT_OPENAI;
    }

    private function chatPath(): string
    {
        return $this->isOpenAi() ? 'v1/chat/completions' : 'api/chat';
    }

    /**
     * Build the chat request body for the configured API format.
     *
     * @param list<array{role: string, content: string}> $messages
     * @return array<string, mixed>
     */
    private function buildChatBody(array $messages, float $temperature): array
    {
        $body = [
            'model' => $this->currentModel,
            'messages' => $messages,
            'stream' => false, // CRITICAL: Disable streaming for synchronous response
        ];

        if ($this->isOpenAi()) {
            $body['temperature'] = $temperature;
        } else {
            $body['options'] = ['temperature' => $temperature];
        }

        return $body;
    }

    /**
     * Return the assistant message (role + content) from a chat response,
     * or null when the structure is not recognised.
     * Ollama: {message: {...}}; OpenAI: {choices: [{message: {...}}]}.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function extractChatMessage(array $data): ?array
    {
        $message = $this->isOpenAi()
            ? ($data['choices'][0]['message'] ?? null)
            : ($data['message'] ?? null);

        return is_array($message) ? $message : null;
    }

    /**
     * Execute a conversational query to Ollama API with context.
     *
     * @param string $userMessage User's message in French or any language
     * @param list<array<string, mixed>> $searchResults Optional search results to inject into system prompt for context-aware responses
     * @param array<string, mixed> $context Optional conversation context with keys: history (array of role/content pairs)
     * @return string Ollama's response text in French
     */
    public function query(string $userMessage, array $searchResults = [], array $context = []): string
    {
        // Validate input
        if (empty(trim($userMessage))) {
            throw new InvalidArgumentException('User message cannot be empty');
        }

        // Build messages array from context history and current user message
        $messages = $this->buildMessages($userMessage, $context, $searchResults);

        // Log API request details for debugging
        $apiEndpoint = $this->ollamaUrl . '/' . $this->chatPath();
        FileLogger::getInstance()->debug("OllamaService::query() - Calling API endpoint: {$apiEndpoint}");
        FileLogger::getInstance()->debug("OllamaService::query() - Using model: {$this->currentModel}");
        FileLogger::getInstance()->debug("OllamaService::query() - Message count: " . count($messages));

        // Make API call with retry logic
        try {
            $response = $this->executeWithRetry(function () use ($messages, $apiEndpoint) {
                try {
                    $requestBody = $this->buildChatBody($messages, 0.2);

                    $response = $this->client->post($this->chatPath(), [
                        'json' => $requestBody
                    ]);

                    $statusCode = $response->getStatusCode();
                    FileLogger::getInstance()->debug("OllamaService::query() - Response status: HTTP {$statusCode}");

                    $body = $response->getBody()->getContents();
                    $data = json_decode($body, true);

                    if ($data === null) {
                        FileLogger::getInstance()->error("OllamaService::query() - ERROR: Invalid JSON response. Raw body length: " . strlen($body));
                        throw new RuntimeException('Invalid JSON response from Ollama API');
                    }

                    // Extract text from response (message.content, or choices[0].message.content for OpenAI)
                    $message = $this->extractChatMessage($data);
                    if ($message === null) {
                        FileLogger::getInstance()->error("OllamaService::query() - ERROR: Missing message object in response");
                        throw new RuntimeException('Invalid response structure: missing message object');
                    }

                    if (!isset($message['content'])) {
                        FileLogger::getInstance()->error("OllamaService::query() - ERROR: Missing content in message object");
                        throw new RuntimeException('Invalid response structure: missing content in message');
                    }

                    FileLogger::getInstance()->info("OllamaService::query() - SUCCESS: Response received, content length: " . strlen($message['content']));
                    return $message['content'];
                } catch (ClientException $e) {
                    $statusCode = $e->getResponse()->getStatusCode();
                    FileLogger::getInstance()->error("OllamaService::query() - ClientException at {$apiEndpoint}: HTTP {$statusCode}");
                    $message = $this->extractClientErrorMessage($e);
                    FileLogger::getInstance()->error("OllamaService::query() - Client error details: {$message}");
                    throw new RuntimeException($message, 0, $e);
                } catch (ServerException $e) {
                    $statusCode = $e->getResponse()->getStatusCode();
                    FileLogger::getInstance()->error("OllamaService::query() - ServerException at {$apiEndpoint}: HTTP {$statusCode}");
                    $this->logServerError($e, 'query');
                    throw new RuntimeException(
                        'Ollama API server error (HTTP ' . $e->getResponse()->getStatusCode() . ')',
                        0,
                        $e
                    );
                }
            });
        } catch (RuntimeException $e) {
            // Log connection failure with full URL details for debugging
            FileLogger::getInstance()->error("OllamaService::query() - CONNECTION FAILED to {$apiEndpoint}");
            FileLogger::getInstance()->error("OllamaService::query() - Error: " . $e->getMessage());
            throw $e;
        }

        return $response;
    }

    /**
     * Execute a conversational query with incremental (streamed) delivery.
     *
     * Ollama streams natively as NDJSON: one JSON object per line, each
     * carrying a `message.content` fragment, with `done: true` on the last.
     * OpenAI-compatible servers stream SSE chat.completions chunks ending
     * with `data: [DONE]`. $onDelta is invoked for each fragment; the full
     * text is returned.
     *
     * @param string $userMessage User's message in French or any language
     * @param list<array<string, mixed>> $searchResults Optional search results to inject into system prompt
     * @param array<string, mixed> $context Optional conversation context with keys: history
     * @param callable|null $onDelta Called as fn(string $textChunk): void per fragment
     * @return string Full response text
     */
    public function queryStream(string $userMessage, array $searchResults = [], array $context = [], ?callable $onDelta = null): string
    {
        // Validate input
        if (empty(trim($userMessage))) {
            throw new InvalidArgumentException('User message cannot be empty');
        }

        $messages = $this->buildMessages($userMessage, $context, $searchResults);

        $apiEndpoint = $this->ollamaUrl . '/' . $this->chatPath();
        FileLogger::getInstance()->debug("OllamaService::queryStream() - Calling API endpoint: {$apiEndpoint} (streaming)");

        if ($this->isOpenAi()) {
            return $this->queryStreamOpenAi($messages, $apiEndpoint, $onDelta);
        }

        return $this->executeWithRetry(function () use ($messages, $apiEndpoint, $onDelta) {
            try {
                $response = $this->client->post('api/chat', [
                    'json' => [
                        'model' => $this->currentModel,
                        'messages' => $messages,
                        'stream' => true,
                        'options' => [
                            'temperature' => 0.2
                        ]
                    ],
                    'stream' => true,
                ]);

                $body = $response->getBody();
                $buffer = '';
                $fullText = '';

                while (!$body->eof()) {
                    $chunk = $body->read(8192);
                    if ($chunk === '') {
                        continue;
                    }
                    $buffer .= $chunk;

                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = trim(substr($buffer, 0, $pos));
                        $buffer = substr($buffer, $pos + 1);
                        if ($line === '') {
                            continue;
                        }

                        $data = json_decode($line, true);
                        if (!is_array($data)) {
                            continue;
                        }
                        if (isset($data['error'])) {
                            throw new RuntimeException('Ollama stream error: ' . $data['error']);
                        }

                        $fragment = $data['message']['content'] ?? '';
                        if ($fragment !== '') {
                            $fullText .= $fragment;
                            if ($onDelta !== null) {
                                $onDelta($fragment);
                            }
                        }

                        if (!empty($data['done'])) {
                            if ($fullText === '') {
                                throw new RuntimeException('Empty response from Ollama stream');
                            }
                            FileLogger::getInstance()->info("OllamaService::queryStream() - SUCCESS: Response received, content length: " . strlen($fullText));
                            return $fullText;
                        }
                    }
                }

                if ($fullText === '') {
                    throw new RuntimeException('Empty response from Ollama stream');
                }
                return $fullText;
            } catch (ClientException $e) {
                $statusCode = $e->getResponse()->getStatusCode();
                FileLogger::getInstance()->error("OllamaService::queryStream() - ClientException at {$apiEndpoint}: HTTP {$statusCode}");
                $message = $this->extractClientErrorMessage($e);
                throw new RuntimeException($message, 0, $e);
            } catch (ServerException $e) {
                $this->logServerError($e, 'queryStream');
                throw new RuntimeException(
                    'Ollama API server error (HTTP ' . $e->getResponse()->getStatusCode() . ')',
                    0,
                    $e
                );
            }
        });
    }

    /**
     * OpenAI-compatible streaming: POST /v1/chat/completions with
     * `stream: true`, read as SSE chunks.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param callable|null $onDelta Called as fn(string $textChunk): void per fragment
     * @return string Full response text
     */
    private function queryStreamOpenAi(array $messages, string $apiEndpoint, ?callable $onDelta): string
    {
        return $this->executeWithRetry(function () use ($messages, $apiEndpoint, $onDelta) {
            try {
                $body = $this->buildChatBody($messages, 0.2);
                $body['stream'] = true;

                $response = $this->client->post($this->chatPath(), [
                    'json' => $body,
                    'stream' => true,
                ]);

                $fullText = SseStreamReader::read($response->getBody(), [SseStreamReader::class, 'openAiDelta'], $onDelta);
                FileLogger::getInstance()->info("OllamaService::queryStream() - SUCCESS: Response received, content length: " . strlen($fullText));
                return $fullText;
            } catch (ClientException $e) {
                $statusCode = $e->getResponse()->getStatusCode();
                FileLogger::getInstance()->error("OllamaService::queryStream() - ClientException at {$apiEndpoint}: HTTP {$statusCode}");
                $message = $this->extractClientErrorMessage($e);
                throw new RuntimeException($message, 0, $e);
            } catch (ServerException $e) {
                $this->logServerError($e, 'queryStream');
                throw new RuntimeException(
                    'Ollama API server error (HTTP ' . $e->getResponse()->getStatusCode() . ')',
                    0,
                    $e
                );
            }
        });
    }

    /**
     * Build messages array from conversation history, system prompt, and current user message.
     *
     * Constructs the messages array required by Ollama API by combining
     * system prompt, conversation history, and the current user message.
     *
     * @param string $userMessage Current user message to append
     * @param array<string, mixed> $context Conversation context with optional 'history' key
     * @param list<array<string, mixed>> $searchResults Search results to inject into system prompt
     * @return list<array{role: string, content: string}> Messages array formatted for Ollama API
     */
    private function buildMessages(string $userMessage, array $context, array $searchResults): array
    {
        $messages = [];

        // Build and add system prompt
        $systemPrompt = $this->buildSystemPrompt($searchResults);
        if (!empty($systemPrompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt
            ];
        }

        // Add conversation history if provided
        if (isset($context['history']) && is_array($context['history'])) {
            foreach ($context['history'] as $message) {
                if (isset($message['role']) && isset($message['content'])) {
                    $messages[] = [
                        'role' => $message['role'],
                        'content' => $message['content']
                    ];
                }
            }
        }

        // Add current user message
        $messages[] = [
            'role' => 'user',
            'content' => $userMessage
        ];

        return $messages;
    }

    /**
     * Build system prompt with search results context.
     *
     * Loads the base search assistant instruction template and appends formatted
     * search results if provided. The system prompt guides Ollama's behavior
     * and enforces French language responses.
     *
     * @param list<array<string, mixed>> $searchResults Search results to inject into prompt
     * @return string Formatted system prompt
     */
    private function buildSystemPrompt(array $searchResults): string
    {
        // Prefer the admin-provided override when present; otherwise read the
        // default shipped with the module.
        if ($this->systemInstructionsOverride !== null) {
            $prompt = $this->systemInstructionsOverride;
        } else {
            $templatePath = FilePromptProvider::shippedDir() . '/' . FilePromptProvider::FILE_INSTRUCTIONS;
            if (!file_exists($templatePath)) {
                throw new RuntimeException("System prompt template not found at: {$templatePath}");
            }
            $prompt = file_get_contents($templatePath);
            if ($prompt === false) {
                throw new RuntimeException("Failed to load system prompt template from: {$templatePath}");
            }
        }

        // Append search results context if provided
        if (!empty($searchResults)) {
            $prompt .= "\n\n**Données de contexte:**\n";
            foreach ($searchResults as $index => $result) {
                $resultNum = $index + 1;
                $prompt .= "\nRésultat {$resultNum}:\n";

                // Extract payload from Qdrant result structure
                $payload = $result['payload'] ?? $result;

                if (isset($payload['title'])) {
                    $prompt .= "Titre: {$payload['title']}\n";
                }
                if (isset($payload['creator'])) {
                    $prompt .= "Créateur: {$payload['creator']}\n";
                }
                if (isset($payload['date'])) {
                    $prompt .= "Date: {$payload['date']}\n";
                }
                if (isset($payload['type'])) {
                    $prompt .= "Type: {$payload['type']}\n";
                }
                if (isset($payload['description'])) {
                    $prompt .= "Description: {$payload['description']}\n";
                }
                if (isset($payload['subject'])) {
                    $prompt .= "Sujet: {$payload['subject']}\n";
                }
                if (isset($payload['url']) && !empty($payload['url'])) {
                    $prompt .= "URL: {$payload['url']}\n";
                }

                // Dynamically include all vocabulary properties
                if (!empty($payload['properties'])) {
                    $skip = ['dcterms:creator', 'dcterms:date', 'dcterms:subject'];
                    foreach ($payload['properties'] as $term => $value) {
                        if (in_array($term, $skip, true) || empty($value)) {
                            continue;
                        }
                        $label = ucfirst(preg_replace('/.*:/', '', $term));
                        $prompt .= "{$label}: {$value}\n";
                    }
                }
            }

            $prompt .= "\n**IMPORTANT : Vous devez UNIQUEMENT utiliser les données ci-dessus pour répondre à la question. Ne vous appuyez pas sur vos connaissances générales pour des faits spécifiques sur les œuvres ou artistes. Si les données fournies ne permettent pas de répondre à la question, indiquez que vous n'avez pas trouvé d'information pertinente dans les collections.**";
        }

        return $prompt;
    }

    /**
     * Extract meaningful error message from ClientException (4xx errors).
     *
     * Parses the response body to get Ollama API's error message, or provides
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

            // Ollama returns {"error": "message"}; OpenAI-compatible servers return
            // {"error": {"message": "..."}} or {"message": "..."} (vLLM).
            $errorMessage = $data['error']['message'] ?? $data['error'] ?? $data['message'] ?? null;
            if (is_string($errorMessage)) {

                // Provide helpful message for model not found error
                if (strpos($errorMessage, 'not found') !== false || strpos($errorMessage, 'does not exist') !== false) {
                    return $this->isOpenAi()
                        ? "Model '{$this->currentModel}' not found on the server: {$errorMessage}"
                        : "Model not found - run: docker exec ollama ollama pull {$this->currentModel}";
                }

                return "Client error (HTTP {$statusCode}): {$errorMessage}";
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
     * Logs server errors (5xx) without exposing internal Ollama API error details.
     *
     * @param ServerException $exception The server exception to log
     * @param string $operation The operation that failed (e.g., 'query')
     * @return void
     */
    private function logServerError(ServerException $exception, string $operation): void
    {
        $statusCode = $exception->getResponse()->getStatusCode();
        FileLogger::getInstance()->error("Ollama API {$operation} failed: Ollama API server error (HTTP {$statusCode})");
    }

    /**
     * Extract structured criteria from user query for search filtering.
     *
     * Uses Ollama API to analyze natural language query and extract search criteria
     * such as categories, date ranges, keywords, and other relevant filters.
     *
     * @param string $userMessage User's natural language query
     * @return array<string, mixed> Extracted criteria with keys for search parameters
     * @throws InvalidArgumentException If user message is empty
     */
    public function extractCriteria(string $userMessage): array
    {
        // Validate input
        if (empty(trim($userMessage))) {
            throw new InvalidArgumentException('User message cannot be empty');
        }

        // Build prompt for criteria extraction
        $extractionPrompt = "Analysez cette question et extrayez les critères de recherche structurés. "
            . "Retournez un objet JSON avec les clés suivantes si applicables: "
            . "categories (array), keywords (array), dateRange (object avec from/to), location (string), "
            . "priceRange (object avec min/max). Question: {$userMessage}";

        // Make API call with retry logic
        try {
            $response = $this->executeWithRetry(function () use ($extractionPrompt) {
                $response = $this->client->post($this->chatPath(), [
                    // Lower temperature for more deterministic JSON output
                    'json' => $this->buildChatBody([['role' => 'user', 'content' => $extractionPrompt]], 0.3)
                ]);

                $body = $response->getBody()->getContents();
                return json_decode($body, true);
            });

            $message = is_array($response) ? $this->extractChatMessage($response) : null;
            if (!isset($message['content'])) {
                return [];
            }

            $criteriaText = $message['content'];

            // Try to extract JSON from the response (model might include explanation text)
            if (preg_match('/\{[^{}]*\}/s', $criteriaText, $matches)) {
                $criteriaText = $matches[0];
            }

            // Parse JSON response
            $criteria = json_decode($criteriaText, true);

            // Return empty array if parsing fails or response is not an array
            return is_array($criteria) ? $criteria : [];
        } catch (\Exception $e) {
            // Return empty criteria on error (graceful degradation)
            FileLogger::getInstance()->error("Failed to extract criteria: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Format raw API response into structured output.
     *
     * Extracts text content, token usage, and metadata from Ollama API response
     * and structures it for consistent handling in the application layer.
     *
     * @param array<string, mixed> $apiResponse Raw response from Ollama API
     * @return array<string, mixed> Formatted response with text, token usage, and metadata
     * @throws InvalidArgumentException If response structure is invalid
     */
    public function formatResponse(array $apiResponse): array
    {
        // Validate response structure (Ollama uses 'message' not 'content' array)
        $message = $this->extractChatMessage($apiResponse);
        if ($message === null) {
            throw new InvalidArgumentException('Invalid API response: missing message object');
        }

        $text = (string) ($message['content'] ?? '');

        if ($this->isOpenAi()) {
            $inputTokens = (int) ($apiResponse['usage']['prompt_tokens'] ?? 0);
            $outputTokens = (int) ($apiResponse['usage']['completion_tokens'] ?? 0);

            return [
                'text' => $text,
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'total_tokens' => $inputTokens + $outputTokens,
                ],
                'metadata' => [
                    'model' => $apiResponse['model'] ?? $this->currentModel,
                    'stop_reason' => $apiResponse['choices'][0]['finish_reason'] ?? null,
                    'id' => $apiResponse['id'] ?? null,
                    'role' => $message['role'] ?? 'assistant',
                    'total_duration' => null,
                    'done' => true,
                ],
            ];
        }

        // Extract token usage (Ollama uses different field names)
        $usage = [
            'input_tokens' => $apiResponse['prompt_eval_count'] ?? 0,
            'output_tokens' => $apiResponse['eval_count'] ?? 0,
            'total_tokens' => ($apiResponse['prompt_eval_count'] ?? 0) + ($apiResponse['eval_count'] ?? 0)
        ];

        // Extract metadata
        $metadata = [
            'model' => $apiResponse['model'] ?? $this->currentModel,
            'stop_reason' => $apiResponse['done_reason'] ?? null,
            'id' => null, // Ollama doesn't provide response IDs
            'role' => $message['role'] ?? 'assistant',
            'total_duration' => $apiResponse['total_duration'] ?? null,
            'done' => $apiResponse['done'] ?? true
        ];

        return [
            'text' => $text,
            'usage' => $usage,
            'metadata' => $metadata
        ];
    }

    /**
     * Change the active Ollama model for subsequent queries.
     *
     * Note: Unlike Claude, Ollama doesn't validate model names upfront.
     * Invalid models will fail at query time with a "model not found" error.
     *
     * @param string $modelId Model name (e.g., mistral, llama3, mixtral)
     * @return void
     * @throws InvalidArgumentException If model name is empty
     */
    public function setModel(string $modelId): void
    {
        if (empty(trim($modelId))) {
            throw new InvalidArgumentException('Model name cannot be empty');
        }

        $this->currentModel = $modelId;
    }

    /**
     * Test connectivity to the Ollama API.
     *
     * Performs a health check by calling the /api/tags endpoint to verify
     * that the Ollama service is running and reachable. Also checks if the
     * configured model is available.
     *
     * @return array<string, mixed> Diagnostic result with keys:
     *               - success (bool): Whether connection was successful
     *               - message (string): Human-readable status message
     *               - models (array): List of available models (on success)
     *               - model_available (bool): Whether configured model is available
     */
    public function testConnection(): array
    {
        $modelsPath = $this->isOpenAi() ? 'v1/models' : 'api/tags';
        $apiEndpoint = $this->ollamaUrl . '/' . $modelsPath;
        FileLogger::getInstance()->debug("OllamaService::testConnection() - Testing connectivity to: {$apiEndpoint}");

        try {
            $response = $this->client->get($modelsPath, [
                'timeout' => 5.0 // Short timeout for health check
            ]);

            $statusCode = $response->getStatusCode();
            FileLogger::getInstance()->debug("OllamaService::testConnection() - Response status: HTTP {$statusCode}");

            if ($statusCode !== 200) {
                return [
                    'success' => false,
                    'message' => "Unexpected response from Ollama API (HTTP {$statusCode})",
                    'models' => [],
                    'model_available' => false
                ];
            }

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if ($data === null) {
                return [
                    'success' => false,
                    'message' => 'Invalid JSON response from Ollama API',
                    'models' => [],
                    'model_available' => false
                ];
            }

            // Extract list of available models (Ollama: models[].name, OpenAI: data[].id)
            $models = [];
            $modelAvailable = false;
            $modelList = $this->isOpenAi() ? ($data['data'] ?? null) : ($data['models'] ?? null);
            $nameKey = $this->isOpenAi() ? 'id' : 'name';
            if (is_array($modelList)) {
                foreach ($modelList as $model) {
                    if (isset($model[$nameKey])) {
                        $modelName = $model[$nameKey];
                        $models[] = $modelName;
                        // Check if configured model matches (with or without :latest tag)
                        if ($modelName === $this->currentModel ||
                            $modelName === $this->currentModel . ':latest' ||
                            strpos($modelName, $this->currentModel . ':') === 0) {
                            $modelAvailable = true;
                        }
                    }
                }
            }

            $message = "Connected to " . ($this->isOpenAi() ? 'OpenAI-compatible LLM' : 'Ollama') . " at {$this->ollamaUrl}";
            if (!$modelAvailable && !empty($this->currentModel)) {
                $message .= $this->isOpenAi()
                    ? ". WARNING: Model '{$this->currentModel}' not served. Available: " . implode(', ', $models)
                    : ". WARNING: Model '{$this->currentModel}' not found. Run: ollama pull {$this->currentModel}";
            } elseif ($modelAvailable) {
                $message .= ". Model '{$this->currentModel}' is available.";
            }

            FileLogger::getInstance()->info("OllamaService::testConnection() - SUCCESS: " . count($models) . " models available");

            return [
                'success' => true,
                'message' => $message,
                'models' => $models,
                'model_available' => $modelAvailable
            ];
        } catch (ConnectException $e) {
            FileLogger::getInstance()->error("OllamaService::testConnection() - Connection failed: " . $e->getMessage());

            $errorMessage = $this->isOpenAi()
                ? "Cannot connect to the LLM server at {$this->ollamaUrl}: " . $e->getMessage()
                : "Cannot connect to Ollama at {$this->ollamaUrl}. " .
                    "Please verify: (1) Ollama is running, (2) The URL is correct " .
                    "(use 'http://localhost:11434' for local Ollama or 'http://ollama:11434' for Docker).";

            return [
                'success' => false,
                'message' => $errorMessage,
                'models' => [],
                'model_available' => false
            ];
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            FileLogger::getInstance()->error("OllamaService::testConnection() - Client error: HTTP {$statusCode}");

            return [
                'success' => false,
                'message' => "Ollama API returned error (HTTP {$statusCode})",
                'models' => [],
                'model_available' => false
            ];
        } catch (ServerException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            FileLogger::getInstance()->error("OllamaService::testConnection() - Server error: HTTP {$statusCode}");

            return [
                'success' => false,
                'message' => "Ollama API server error (HTTP {$statusCode})",
                'models' => [],
                'model_available' => false
            ];
        } catch (\Exception $e) {
            FileLogger::getInstance()->error("OllamaService::testConnection() - Unexpected error: " . $e->getMessage());

            return [
                'success' => false,
                'message' => "Connection test failed: " . $e->getMessage(),
                'models' => [],
                'model_available' => false
            ];
        }
    }
}
