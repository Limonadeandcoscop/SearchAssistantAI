<?php
declare(strict_types=1);
namespace SearchAssistantAI\Controller\Site;

use SearchAssistantAI\Event\ChatEvents;
use SearchAssistantAI\Permissions\ChatAccessPolicyInterface;
use SearchAssistantAI\Service\Search\QueryProcessorInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Http\Response as HttpResponse;
use Laminas\InputFilter\InputFilter;
use Laminas\InputFilter\Input;
use Laminas\Validator;

/**
 * Site controller for Search Assistant AI REST API
 *
 * Handles the /search-assistant/api/query endpoint for processing natural language queries
 * through semantic search pipeline with Qdrant vector database integration.
 *
 * Features:
 * - Natural language query processing in French
 * - Semantic search using embeddings service
 * - Input validation and sanitization
 * - Rate limiting (10 requests/minute per IP)
 * - Structured JSON responses with metadata
 * - Chat events (ChatEvents) for listeners such as conversation storage
 */
class ChatController extends AbstractActionController
{
    /**
     * @var QueryProcessorInterface
     */
    protected $queryProcessorService;

    /**
     * @var \SearchAssistantAI\Service\RateLimit\RateLimitService
     */
    protected $rateLimitService;

    /**
     * @var \Laminas\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * @var ChatAccessPolicyInterface|null Who may call the chat API; null allows everyone
     */
    protected $accessPolicy;

    /**
     * Constructor with dependency injection
     *
     * @param QueryProcessorInterface $queryProcessorService Service for processing queries
     * @param \SearchAssistantAI\Service\RateLimit\RateLimitService $rateLimitService Service for rate limiting
     * @param \Laminas\Log\LoggerInterface $logger Logger instance for tracking requests
     * @param \Omeka\Settings\Settings $settings Settings service for configuration
     * @param ChatAccessPolicyInterface|null $accessPolicy Who may call the chat API (null allows everyone)
     */
    public function __construct(
        QueryProcessorInterface $queryProcessorService,
        $rateLimitService,
        $logger,
        $settings,
        ?ChatAccessPolicyInterface $accessPolicy = null
    ) {
        $this->queryProcessorService = $queryProcessorService;
        $this->rateLimitService = $rateLimitService;
        $this->logger = $logger;
        $this->settings = $settings;
        $this->accessPolicy = $accessPolicy;
    }

    /**
     * Handle POST /search-assistant/api/query requests
     *
     * Processes natural language queries through semantic search pipeline.
     * Returns structured JSON response with search results, natural language
     * response, suggestions, and metadata.
     *
     * Expected POST body:
     * {
     *   "query": "Trouve-moi des œuvres de Monet",
     *   "sessionId": "abc123...",                          // optional, generated if not provided
     *   "context": [{"role": "user", "content": "..."}],  // optional
     *   "filters": {"type": "item", "dateRange": {...}}   // optional
     * }
     *
     * Response format:
     * {
     *   "success": true,
     *   "response": "Voici les œuvres de Monet que j'ai trouvées...",
     *   "results": [
     *     {
     *       "id": "123",
     *       "type": "item",
     *       "title": "Nymphéas",
     *       "url": "/items/123",
     *       "thumbnail": "/files/thumb.jpg",
     *       "excerpt": "...",
     *       "score": 0.95
     *     }
     *   ],
     *   "suggestions": ["Montre-moi des peintures impressionnistes", ...],
     *   "metadata": {
     *     "queryTime": 1.23,
     *     "totalResults": 15
     *   }
     * }
     *
     * @return HttpResponse JSON response with results or error
     */
    public function queryAction()
    {
        $startTime = microtime(true);

        // 1. Validate request method - only POST allowed
        if (!$this->getRequest()->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $this->translate('Method not allowed. Use POST.')
            ], 405);
        }

        // 1b. Access policy (e.g. signed-in users only); the widget asks
        // the same policy before showing itself.
        if ($this->accessPolicy !== null && !$this->accessPolicy->canChat()) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $this->translate('Authentication required.'),
            ], 403);
        }

        // 2. Verify Content-Type is application/json
        $contentType = $this->getRequest()->getHeader('Content-Type');
        if (!$contentType || strpos($contentType->getFieldValue(), 'application/json') === false) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $this->translate('Content-Type must be application/json')
            ], 400);
        }

        // 3. Check rate limit before processing expensive operations.
        // The full IP stays in-memory (and is briefly persisted to /tmp by the
        // rate-limit store, which self-expires). Everything we log/derive uses
        // a masked or HMAC'd form for GDPR data minimization.
        /** @var \Laminas\Http\PhpEnvironment\Request $request */
        $request = $this->getRequest();
        $clientIp = (string) $request->getServer('REMOTE_ADDR', 'unknown');
        $clientIpMasked = $this->maskIpForLog($clientIp);
        if (!$this->rateLimitService->isAllowed($clientIp)) {
            // Rate limit exceeded
            $resetTime = $this->rateLimitService->getResetTime($clientIp);
            $rateLimitConfig = $this->rateLimitService->getConfiguration();

            // Log rate limit violation
            $this->logger->warn('Search Assistant AI API rate limit exceeded', [
                'client_ip' => $clientIpMasked,
                'reset_in_seconds' => $resetTime,
                'max_requests' => $rateLimitConfig['maxRequests'],
                'window_seconds' => $rateLimitConfig['windowSeconds']
            ]);

            return $this->jsonResponse([
                'success' => false,
                'error' => sprintf(
                    $this->translate('Too many requests. Please try again in %d seconds.'),
                    $resetTime
                ),
                'retryAfter' => $resetTime
            ], 429, ['Retry-After' => (string) $resetTime]);
        }

        // 4. Parse JSON body
        $rawBody = $this->getRequest()->getContent();
        $data = json_decode($rawBody, true);

        // Check for JSON parse errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->jsonResponse([
                'success' => false,
                'error' => sprintf($this->translate('Invalid JSON: %s'), json_last_error_msg())
            ], 400);
        }

        // 5. Validate input using InputFilter
        $inputFilter = $this->createInputFilter();
        $inputFilter->setData($data);

        if (!$inputFilter->isValid()) {
            $errors = $inputFilter->getMessages();
            return $this->jsonResponse([
                'success' => false,
                'error' => $this->translate('Validation failed'),
                'details' => $errors
            ], 400);
        }

        // 6. Get validated data
        $validatedData = $inputFilter->getValues();
        $query = $validatedData['query'];
        $sessionId = $validatedData['sessionId'] ?? null;
        $context = $validatedData['context'] ?? [];
        $filters = $validatedData['filters'] ?? [];

        // 6b. Extract site slug from route for locale-aware URL generation
        $siteSlug = $this->params()->fromRoute('site-slug', '');

        // 7. Generate session ID if not provided. HMAC-SHA256 with a
        // server-side salt + daily rotation — unlike a plain md5(IP+date), an
        // attacker cannot recover the IP without the salt.
        if (empty($sessionId)) {
            $sessionId = $this->deriveAnonymousSessionId($clientIp);
        }

        // 8. Log the incoming request
        $this->logger->info('Search Assistant AI API query received', [
            'query' => $query,
            'session_id' => $sessionId,
            'client_ip' => $clientIpMasked,
            'has_context' => !empty($context),
            'has_filters' => !empty($filters)
        ]);

        // 9. Let listeners record the question (e.g. conversation storage).
        $this->triggerChatEvent(ChatEvents::QUERY_RECEIVED, [
            'query' => $query,
            'sessionId' => $sessionId,
            'context' => $context,
            'filters' => $filters,
            'clientIpMasked' => $clientIpMasked,
            'userAgent' => (string) $request->getServer('HTTP_USER_AGENT', 'unknown'),
        ]);

        // 9b. Streaming mode (opt-in via `"stream": true` in the body):
        // deliver the answer as Server-Sent Events so the widget renders
        // text as the LLM produces it instead of waiting for the full
        // pipeline. All validation/rate-limit failures above still return
        // plain JSON with the appropriate status code.
        if (!empty($data['stream'])) {
            return $this->streamQueryResponse($query, $context, $siteSlug, $sessionId, $startTime);
        }

        try {
            // 10. Process query through QueryProcessorService
            // Note: This will be implemented when QueryProcessorService is created
            $result = $this->queryProcessorService->processQuery(
                $query,
                $context,
                $siteSlug
            );

            // 11. Calculate query time
            $queryTime = round(microtime(true) - $startTime, 3);

            // 12. Let listeners record the answer.
            $this->triggerChatEvent(ChatEvents::QUERY_ANSWERED, [
                'query' => $query,
                'sessionId' => $sessionId,
                'result' => $result,
                'responseTimeMs' => (int) ($queryTime * 1000),
                'streamed' => false,
            ]);

            // 13. Log successful response
            $this->logger->info('Search Assistant AI API query completed', [
                'query' => $query,
                'session_id' => $sessionId,
                'result_count' => count($result['sources']),
                'query_time' => $queryTime
            ]);

            // 14. Return successful response
            return $this->jsonResponse([
                'success' => true,
                'response' => $result['answer'],
                'results' => $result['sources'],
                'suggestions' => $result['suggestions'],
                'metadata' => [
                    'queryTime' => $queryTime,
                    'totalResults' => count($result['sources']),
                    'sessionId' => $sessionId
                ]
            ]);

        } catch (\RuntimeException $e) {
            // Check if this is a rate limit error from Claude API (code 429)
            if ($e->getCode() === 429) {
                // Handle Claude API rate limit errors
                $this->logger->warn('Search Assistant AI: LLM rate limit exceeded', [
                    'query' => $query,
                    'session_id' => $sessionId,
                    'error' => $e->getMessage()
                ]);

                return $this->jsonResponse([
                    'success' => false,
                    'error' => $this->translate('The service is temporarily overloaded. Please try again in a few moments.'),
                    'retryAfter' => 30
                ], 429, ['Retry-After' => '30']);
            }

            // Handle other service unavailable errors (embeddings service, Qdrant, etc.)
            $this->logger->err('Search Assistant AI API service error', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            return $this->jsonResponse([
                'success' => false,
                'error' => $this->translate('The service is temporarily unavailable. Please try again later.')
            ], 503);

        } catch (\Exception $e) {
            // Handle unexpected errors
            $this->logger->err('Search Assistant AI API unexpected error', [
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->jsonResponse([
                'success' => false,
                'error' => 'Une erreur interne s\'est produite'
            ], 500);
        }
    }

    /**
     * Process the query and stream the answer as Server-Sent Events.
     *
     * Emitted events:
     *   - sources: {sources: [...]} — as soon as vector search completes
     *   - delta:   {text: "..."}    — each generated text fragment
     *   - done:    full payload, same shape as the non-streaming JSON response
     *   - error:   {error: "...", retryAfter?: int}
     *
     * Output is written directly (echo + flush), bypassing the Laminas
     * response object which buffers content until dispatch ends. Errors
     * after this point are delivered as `error` events on a 200 response —
     * the HTTP status is already on the wire.
     *
     * @param list<array<string, mixed>> $context
     */
    private function streamQueryResponse(
        string $query,
        array $context,
        string $siteSlug,
        string $sessionId,
        float $startTime
    ): HttpResponse {
        // Generation can exceed max_execution_time; the LLM client enforces
        // its own timeout.
        @set_time_limit(0);
        // Release the session lock so the user's other requests (page loads,
        // parallel widgets) aren't blocked while we stream.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @ini_set('zlib.output_compression', '0');

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no'); // nginx: disable proxy buffering for this response
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        // Anti-buffering padding: Apache/FPM/reverse proxies hold small
        // writes until a few KB accumulate. An SSE comment (ignored by
        // clients) pushes the first real events through immediately.
        echo ':' . str_repeat(' ', 4096) . "\n\n";
        flush();

        $emit = function (string $event, array $payload): void {
            echo "event: {$event}\n";
            echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            flush();
        };

        try {
            $result = $this->queryProcessorService->processQueryStream(
                $query,
                $context,
                $siteSlug,
                function (array $event) use ($emit): void {
                    $type = $event['event'] ?? 'delta';
                    unset($event['event']);
                    $emit($type, $event);
                }
            );

            $queryTime = round(microtime(true) - $startTime, 3);

            // Let listeners record the answer, as in the JSON path.
            $this->triggerChatEvent(ChatEvents::QUERY_ANSWERED, [
                'query' => $query,
                'sessionId' => $sessionId,
                'result' => $result,
                'responseTimeMs' => (int) ($queryTime * 1000),
                'streamed' => true,
            ]);

            $this->logger->info('Search Assistant AI API query completed (streamed)', [
                'query' => $query,
                'session_id' => $sessionId,
                'result_count' => count($result['sources']),
                'query_time' => $queryTime,
            ]);

            $emit('done', [
                'success' => true,
                'response' => $result['answer'],
                'results' => $result['sources'],
                'suggestions' => $result['suggestions'],
                'metadata' => [
                    'queryTime' => $queryTime,
                    'totalResults' => count($result['sources']),
                    'sessionId' => $sessionId,
                ],
            ]);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 429) {
                $this->logger->warn('Search Assistant AI: LLM rate limit exceeded', [
                    'query' => $query,
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
                $emit('error', [
                    'error' => $this->translate('The service is temporarily overloaded. Please try again in a few moments.'),
                    'retryAfter' => 30,
                ]);
            } else {
                $this->logger->err('Search Assistant AI API service error', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
                $emit('error', [
                    'error' => $this->translate('The service is temporarily unavailable. Please try again later.'),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->err('Search Assistant AI API unexpected error', [
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $emit('error', [
                'error' => 'Une erreur interne s\'est produite',
            ]);
        }

        // Everything is already on the wire. Return the (header-less, empty)
        // response so the MVC lifecycle completes without re-sending anything:
        // Laminas' sendHeaders() is a no-op once headers are sent.
        $response = $this->getResponse();
        $response->setContent('');
        return $response;
    }

    /**
     * Return a direct JSON response, bypassing Laminas view layer.
     *
     * This prevents Laminas' ExceptionStrategy from merging error content
     * into the JSON response when post-dispatch exceptions occur
     * (e.g., closed Doctrine EntityManager from conversation save failures).
     *
     * @param array $data Response data to encode as JSON
     * @param int $statusCode HTTP status code
     * @param array $headers Additional headers to set
     * @return HttpResponse
     */
    /**
     * Reduce a raw IP to a form safe to keep in logs under GDPR.
     *
     * IPv4: zero the last octet (192.0.2.37 → 192.0.2.0) — roughly /24, widely
     * accepted by EU DPAs as sufficient data minimization.
     * IPv6: truncate to the /48 prefix. Non-IP / unknown inputs pass through.
     */
    private function maskIpForLog(string $ip): string
    {
        if ($ip === '' || $ip === 'unknown') {
            return 'unknown';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = @inet_pton($ip);
            if ($bin !== false) {
                $masked = substr($bin, 0, 6) . str_repeat("\0", 10);
                $out = @inet_ntop($masked);
                if ($out !== false) {
                    return $out;
                }
            }
        }
        return 'unknown';
    }

    /**
     * Trigger a chat event (see ChatEvents). A failing listener is logged
     * and never breaks the answer.
     *
     * @param array<string, mixed> $params
     */
    private function triggerChatEvent(string $name, array $params): void
    {
        try {
            $this->getEventManager()->trigger($name, $this, $params);
        } catch (\Throwable $e) {
            $this->logger->err('[SearchAssistantAI] Chat event listener failed', [
                'event' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build a daily-rotated anonymous session ID from the client IP.
     *
     * Uses HMAC-SHA256 with a server-side salt (auto-generated and persisted
     * on first use via Omeka settings). Same IP on the same UTC day maps to
     * the same sessionId (so messages group into a conversation), but the
     * mapping is non-reversible without the salt — meeting GDPR pseudonymity
     * rather than the original md5(IP+date) which was trivially rainbow-table-able.
     */
    private function deriveAnonymousSessionId(string $clientIp): string
    {
        $salt = $this->settings->get('search_assistant_session_salt', '');
        if (!is_string($salt) || strlen($salt) < 32) {
            $salt = bin2hex(random_bytes(32));
            $this->settings->set('search_assistant_session_salt', $salt);
        }
        return hash_hmac('sha256', $clientIp . '_' . date('Ymd'), $salt);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function jsonResponse(array $data, int $statusCode = 200, array $headers = []): HttpResponse
    {
        $response = $this->getResponse();
        $response->setStatusCode($statusCode);
        $response->getHeaders()
            ->addHeaderLine('Content-Type', 'application/json; charset=utf-8');

        foreach ($headers as $name => $value) {
            $response->getHeaders()->addHeaderLine($name, $value);
        }

        $response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response;
    }

    /**
     * Create and configure input filter for query validation
     *
     * Validates:
     * - query: required string, 1-1000 characters
     * - sessionId: optional string, 1-255 characters (generated if not provided)
     * - context: optional array, max 10 items
     * - filters: optional array with type and dateRange validation
     *
     * @return InputFilter Configured input filter
     */
    protected function createInputFilter()
    {
        $inputFilter = new InputFilter();

        // Validate 'query' field
        $query = new Input('query');
        $query->setRequired(true);
        $query->getValidatorChain()
            ->attach(new Validator\NotEmpty([
                'messages' => [
                    Validator\NotEmpty::IS_EMPTY => $this->translate('The "query" field is required')
                ]
            ]))
            ->attach(new Validator\StringLength([
                'min' => 1,
                'max' => 1000,
                'messages' => [
                    Validator\StringLength::TOO_SHORT => $this->translate('The query must contain at least 1 character'),
                    Validator\StringLength::TOO_LONG => $this->translate('The query cannot exceed 1000 characters')
                ]
            ]));
        $inputFilter->add($query);

        // Validate 'sessionId' field (optional string for conversation tracking)
        $sessionId = new Input('sessionId');
        $sessionId->setRequired(false);
        $sessionId->setAllowEmpty(true);
        $sessionId->getValidatorChain()
            ->attach(new Validator\StringLength([
                'min' => 1,
                'max' => 255,
                'messages' => [
                    Validator\StringLength::TOO_SHORT => $this->translate('The sessionId must contain at least 1 character'),
                    Validator\StringLength::TOO_LONG => $this->translate('The sessionId cannot exceed 255 characters')
                ]
            ]));
        $inputFilter->add($sessionId);

        // Validate 'context' field (optional array)
        $context = new Input('context');
        $context->setRequired(false);
        $context->setAllowEmpty(true);
        $inputFilter->add($context);

        // Validate 'filters' field (optional object/array)
        $filters = new Input('filters');
        $filters->setRequired(false);
        $filters->setAllowEmpty(true);
        $inputFilter->add($filters);

        return $inputFilter;
    }
}
