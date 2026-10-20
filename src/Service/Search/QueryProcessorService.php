<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Search;

use SearchAssistantAI\Service\Llm\LlmInterface;
use SearchAssistantAI\Settings\SearchAssistantSettings;
use InvalidArgumentException;
use Laminas\Log\LoggerInterface;
use RuntimeException;

/**
 * Orchestrator for the full question-answering pipeline:
 *
 *   validate → extract criteria → semantic search → generate response → format
 *
 * Collaborators own their slice and are injected so they can be
 * swapped/mocked without poking this service's internals:
 *
 *   - CriteriaExtractor: natural-language question → structured criteria
 *   - SemanticSearchInterface: criteria → ranked vector-store results
 *   - LlmInterface + response_generation template: results → LLM answer
 *   - ResponseFormatter: answer + results → chat-API JSON shape
 *
 * Extracted from the original 843-line QueryProcessorService during the
 * v1.1 refactor — everything related to criteria parsing lives in
 * CriteriaExtractor, everything related to shaping the response
 * lives in ResponseFormatter. This class keeps the pipeline logic
 * and the generateResponse Claude call.
 */
class QueryProcessorService implements QueryProcessorInterface
{
    /** Maximum accepted query length (chars). Anything longer is rejected. */
    private const MAX_QUERY_LENGTH = 1000;

    /** Max number of search results included in the Claude context payload. */
    private const MAX_CONTEXT_RESULTS = 10;

    /** Token-count threshold that triggers individual-result-size logging. */
    private const LARGE_PAYLOAD_TOKEN_THRESHOLD = 30000;

    /** Token-count threshold that flips the diagnostic log tag. */
    private const HIGH_TOKEN_LOG_THRESHOLD = 50000;

    private LlmInterface $claudeService;
    private SemanticSearchInterface $searchService;
    private LoggerInterface $logger;

    /** @var array<string, array<string, mixed>> YAML prompt templates by id. */
    private array $promptTemplates;

    private CriteriaExtractorInterface $extractor;
    private ResponseFormatter $formatter;

    /** Number of results requested from the vector search (search_assistant_search_limit). */
    private int $searchLimit;

    /**
     * @param array<string, array<string, mixed>> $promptTemplates
     * @param mixed $settings Unused — kept for backwards compatibility with older call sites.
     */
    public function __construct(
        LlmInterface $claudeService,
        SemanticSearchInterface $searchService,
        LoggerInterface $logger,
        array $promptTemplates = [],
        $settings = null,
        ?CriteriaExtractorInterface $extractor = null,
        ?ResponseFormatter $formatter = null,
        int $searchLimit = SearchAssistantSettings::DEFAULT_SEARCH_LIMIT
    ) {
        $this->claudeService = $claudeService;
        $this->searchService = $searchService;
        $this->logger = $logger;
        $this->promptTemplates = $promptTemplates;
        unset($settings); // deprecated argument, intentionally ignored
        $this->searchLimit = max(1, $searchLimit);

        // Default collaborators keep direct instantiation (tests, ad-hoc
        // scripts) working without the full factory wiring.
        $this->extractor = $extractor ?? new PassThroughCriteriaExtractor();
        $this->formatter = $formatter ?? new ResponseFormatter($logger);

        $this->logger->info('QueryProcessorService initialized', [
            'max_query_length' => self::MAX_QUERY_LENGTH,
            'max_context_results' => self::MAX_CONTEXT_RESULTS,
            'search_limit' => $this->searchLimit,
            'templates_loaded' => array_keys($promptTemplates),
        ]);
    }

    /**
     * Run the full pipeline for a single user question. Returns the
     * chat-API response shape: `{status, answer, sources, suggestions}`.
     *
     * @param array<mixed> $context Optional conversation context.
     * @return array{status: string, answer: string, sources: list<array<string, mixed>>, suggestions: list<string>}
     */
    public function processQuery(string $userQuery, array $context = [], string $siteSlug = ''): array
    {
        return $this->runPipeline($userQuery, $context, $siteSlug, null);
    }

    /**
     * Same pipeline as processQuery(), but streams progress through $emit.
     *
     * $emit receives associative arrays with an `event` key:
     *   - {event: 'sources', sources: [...]}  — right after vector search,
     *     before the (slow) LLM generation starts
     *   - {event: 'delta', text: '...'}       — each generated text fragment
     *
     * The full formatted response (identical to processQuery()'s return) is
     * returned once generation completes, so the caller can persist it and
     * send a final `done` frame.
     *
     * @param array<mixed> $context Optional conversation context.
     * @param callable $emit fn(array $event): void
     * @return array{status: string, answer: string, sources: list<array<string, mixed>>, suggestions: list<string>}
     */
    public function processQueryStream(string $userQuery, array $context, string $siteSlug, callable $emit): array
    {
        return $this->runPipeline($userQuery, $context, $siteSlug, $emit);
    }

    /**
     * @param array<string, mixed> $context
     * @return array{status: string, answer: string, sources: list<array<string, mixed>>, suggestions: list<string>}
     */
    private function runPipeline(string $userQuery, array $context, string $siteSlug, ?callable $emit): array
    {
        $this->validateQuery($userQuery);

        $this->logger->info('QueryProcessorService: Starting query processing pipeline', [
            'query' => $userQuery,
            'query_length' => strlen($userQuery),
            'has_context' => !empty($context),
            'site_slug' => $siteSlug,
        ]);

        try {
            // 1. Criteria extraction (LLM with keyword-fallback).
            $this->logger->debug('QueryProcessorService: Step 1 - Extracting search criteria');
            $criteria = $this->extractor->extract($userQuery);
            $this->logger->info('QueryProcessorService: Search criteria extracted successfully', [
                'criteria' => $criteria,
                'keyword_count' => count($criteria['keywords']),
            ]);

            // 2. Semantic search.
            $this->logger->debug('QueryProcessorService: Step 2 - Performing semantic search', [
                'criteria' => $criteria,
            ]);
            $searchResults = $this->searchService->search(
                $this->extractor->buildSearchQuery($criteria),
                $this->extractor->buildSearchFilters($criteria),
                $this->searchLimit
            );

            $metrics = $this->formatter->payloadMetrics($searchResults);
            $this->logger->info('QueryProcessorService: Semantic search completed', [
                'result_count' => count($searchResults),
                'has_results' => !empty($searchResults),
                'payload_size_bytes' => $metrics['total_size_bytes'],
                'payload_size_kb' => $metrics['total_size_kb'],
                'estimated_tokens' => $metrics['estimated_tokens'],
                'avg_result_size_bytes' => $metrics['avg_result_size_bytes'],
            ]);

            if (empty($searchResults)) {
                $this->logger->warn('QueryProcessorService: No search results found', [
                    'query' => $userQuery,
                    'criteria' => $criteria,
                ]);
                return $this->formatter->formatNoResults($userQuery, $criteria);
            }

            $this->logContextPayload($searchResults);

            // Streaming callers get the sources as soon as search completes,
            // so the widget can show citations while the answer generates.
            if ($emit !== null) {
                $preformatted = $this->formatter->formatSuccess('', $searchResults, $siteSlug);
                $emit(['event' => 'sources', 'sources' => $preformatted['sources']]);
            }

            // 3. Response generation (LLM).
            $answer = $this->generateResponse(
                $userQuery,
                $searchResults,
                $emit === null ? null : function (string $fragment) use ($emit): void {
                    $emit(['event' => 'delta', 'text' => $fragment]);
                }
            );
            $this->logger->info('QueryProcessorService: Response generated successfully', [
                'answer_length' => strlen($answer),
            ]);

            // 4. Format the public response.
            $response = $this->formatter->formatSuccess($answer, $searchResults, $siteSlug);
            $this->logger->info('QueryProcessorService: Query processing pipeline completed successfully', [
                'status' => $response['status'],
                'source_count' => count($response['sources']),
                'has_suggestions' => !empty($response['suggestions']),
            ]);

            return $response;
        } catch (InvalidArgumentException $e) {
            $this->logger->err('QueryProcessorService: Input validation failed', [
                'error' => $e->getMessage(),
                'query' => $userQuery,
            ]);
            throw $e;
        } catch (RuntimeException $e) {
            if ($e->getCode() === 429) {
                $this->logger->warn('QueryProcessorService: Claude API rate limit exceeded', [
                    'error' => $e->getMessage(),
                    'query' => $userQuery,
                ]);
                throw new RuntimeException(
                    'Service temporairement surchargé. Veuillez réessayer dans quelques instants.',
                    429,
                    $e
                );
            }
            $this->logger->err('QueryProcessorService: Pipeline execution failed', [
                'error' => $e->getMessage(),
                'query' => $userQuery,
                'trace' => $e->getTraceAsString(),
            ]);
            throw new RuntimeException('Query processing pipeline failed: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            $this->logger->err('QueryProcessorService: Unexpected error in pipeline', [
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'query' => $userQuery,
                'trace' => $e->getTraceAsString(),
            ]);
            throw new RuntimeException('Unexpected error during query processing: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Public delegator — preserved for back-compat with external callers
     * that need just the criteria-extraction slice.
     *
     * @return array{keywords: list<string>, author?: string, dateRange?: array{start:string, end:string}, type?: string}
     */
    public function extractSearchCriteria(string $userQuery): array
    {
        return $this->extractor->extract($userQuery);
    }

    /**
     * Ask Claude for a natural-language answer given the user's question
     * and the search-result context. Returns the raw LLM text; the
     * caller is responsible for wrapping it into the final response
     * structure (see ResponseFormatter).
     *
     * @param list<array<string, mixed>> $searchResults
     * @param callable|null $onDelta When provided, the LLM call streams and
     *        $onDelta receives each text fragment as it is generated.
     */
    public function generateResponse(string $userQuery, array $searchResults, ?callable $onDelta = null): string
    {
        if (trim($userQuery) === '') {
            throw new InvalidArgumentException('User query cannot be empty');
        }

        $this->logger->info('Generating response from search results', [
            'query' => $userQuery,
            'result_count' => count($searchResults),
            'query_length' => strlen($userQuery),
        ]);

        try {
            $limitedResults = array_slice($searchResults, 0, self::MAX_CONTEXT_RESULTS);

            $contextMetrics = $this->formatter->payloadMetrics($limitedResults);
            $this->logger->info('QueryProcessorService: Preparing Claude context payload', [
                'original_count' => count($searchResults),
                'limited_count' => count($limitedResults),
                'context_payload_size_bytes' => $contextMetrics['total_size_bytes'],
                'context_payload_size_kb' => $contextMetrics['total_size_kb'],
                'context_estimated_tokens' => $contextMetrics['estimated_tokens'],
                'max_single_result_bytes' => $contextMetrics['max_result_size_bytes'],
            ]);

            $responsePrompt = $this->buildResponsePrompt($userQuery);
            $this->logger->debug('Calling Claude API for response generation', [
                'streaming' => $onDelta !== null,
            ]);
            $response = $onDelta !== null
                ? $this->claudeService->queryStream($responsePrompt, $limitedResults, [], $onDelta)
                : $this->claudeService->query($responsePrompt, $limitedResults);

            $this->logger->info('Successfully generated response', [
                'response_length' => strlen($response),
                'sources_used' => count($limitedResults),
            ]);

            return $response;
        } catch (\Exception $e) {
            $this->logger->err('Response generation failed', [
                'error' => $e->getMessage(),
                'query' => $userQuery,
                'result_count' => count($searchResults),
            ]);
            throw new RuntimeException('Failed to generate response: ' . $e->getMessage(), 0, $e);
        }
    }

    // ────────────────────────────────────────────────────────────────
    // internals
    // ────────────────────────────────────────────────────────────────

    private function validateQuery(string $userQuery): void
    {
        if (trim($userQuery) === '') {
            throw new InvalidArgumentException('User query cannot be empty');
        }
        if (strlen($userQuery) > self::MAX_QUERY_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('User query exceeds maximum length of %d characters', self::MAX_QUERY_LENGTH)
            );
        }
    }

    /**
     * Diagnostic log for the context payload about to go to Claude.
     * Tags large payloads separately so the grep can find them fast.
     *
     * @param array<int, array<string, mixed>> $searchResults
     */
    private function logContextPayload(array $searchResults): void
    {
        $contextResults = array_slice($searchResults, 0, self::MAX_CONTEXT_RESULTS);
        $metrics = $this->formatter->payloadMetrics($contextResults);

        $this->logger->info('QueryProcessorService: Preparing context for Claude response generation', [
            'result_count' => count($searchResults),
            'context_result_count' => count($contextResults),
            'context_payload_size_bytes' => $metrics['total_size_bytes'],
            'context_payload_size_kb' => $metrics['total_size_kb'],
            'context_estimated_tokens' => $metrics['estimated_tokens'],
            'token_warning' => $metrics['estimated_tokens'] > self::HIGH_TOKEN_LOG_THRESHOLD ? 'HIGH_TOKEN_COUNT' : 'NORMAL',
        ]);

        if ($metrics['estimated_tokens'] > self::LARGE_PAYLOAD_TOKEN_THRESHOLD) {
            $this->logger->warn('QueryProcessorService: Large payload detected before Claude call', [
                'result_sizes' => $metrics['individual_sizes'],
                'total_estimated_tokens' => $metrics['estimated_tokens'],
                'max_result_size_bytes' => $metrics['max_result_size_bytes'],
            ]);
        }
    }

    /**
     * Build the full prompt sent to Claude for answer generation.
     *
     * Pulls the system prompt from the YAML template (admin-editable at
     * /admin/search-assistant/prompts) and appends the user question. Any further
     * instruction the admin wants to inject belongs inside the YAML's
     * `system_prompt` field itself.
     */
    private function buildResponsePrompt(string $userQuery): string
    {
        if (!isset($this->promptTemplates['response_generation'])) {
            throw new RuntimeException('Response generation template not loaded');
        }

        $template = $this->promptTemplates['response_generation'];
        if (!isset($template['system_prompt'])) {
            throw new RuntimeException('Response generation template missing system_prompt field');
        }

        $label = isset($template['question_label']) && is_string($template['question_label'])
            ? $template['question_label']
            : "Question de l'utilisateur :";
        return $template['system_prompt'] . "\n\n" . $label . ' ' . $userQuery;
    }
}
