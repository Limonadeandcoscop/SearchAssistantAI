<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Search;

/**
 * Interface for centralized query processing orchestration.
 *
 * Coordinates a two-stage Claude AI pipeline with semantic vector search
 * to process user questions through criteria extraction, semantic search,
 * and contextual response generation with source citations.
 */
interface QueryProcessorInterface
{
    /**
     * Process user query through complete AI-powered search pipeline.
     *
     * Orchestrates full workflow: input validation → criteria extraction (Claude) →
     * semantic vector search → response generation (Claude) → formatting.
     * Handles edge cases including no results and error fallbacks.
     *
     * @param string $userQuery User's natural language question (non-empty, max 1000 chars)
     * @param array<mixed> $context Optional conversation context with keys: history (array of role/content pairs), cache (boolean)
     * @param string $siteSlug Optional site slug for locale prefix injection in response URLs (e.g., 'fr', 'co')
     * @return array<string, mixed> Formatted response with keys: status (success/error), answer (string), sources (array), suggestions (array)
     * @throws \InvalidArgumentException If query is empty or exceeds length limit
     * @throws \RuntimeException If critical pipeline stage fails (Claude API unavailable, vector-store connection timeout)
     */
    public function processQuery(string $userQuery, array $context = [], string $siteSlug = ''): array;

    /**
     * Streamed variant of processQuery: same pipeline and return shape,
     * but emits progress events through $emit while the answer is being
     * generated. Event shapes (consumed by ChatController's SSE writer):
     *   ['event' => 'sources', 'sources' => [...]]  after search
     *   ['event' => 'delta', 'text' => '...']       per answer fragment
     *
     * @param array<mixed> $context Conversation context (role/content pairs)
     * @param callable $emit fn(array $event): void
     * @return array<string, mixed> Same shape as processQuery()
     */
    public function processQueryStream(string $userQuery, array $context, string $siteSlug, callable $emit): array;

    /**
     * Extract structured search criteria from natural language query.
     *
     * Uses Claude AI with YAML-defined prompt template to analyze user question
     * and extract structured parameters for database filtering (keywords, author,
     * date ranges, content type). Falls back to keyword-only search on extraction failure.
     *
     * @param string $userQuery User's natural language question in French or any language
     * @return array<string, mixed> Extracted criteria with keys: keywords (array), author (string), dateRange (array with start/end), type (string)
     * @throws \RuntimeException If Claude API fails and fallback extraction also fails
     */
    public function extractSearchCriteria(string $userQuery): array;

    /**
     * Generate natural language response with source citations from search results.
     *
     * Uses Claude AI with response generation prompt template to synthesize
     * search results into coherent French answer with inline citations [1], [2].
     * Formats sources as numbered references for traceability.
     *
     * @param string $userQuery Original user question for context-aware response
     * @param list<array<string, mixed>> $searchResults Array of search results with id, score, and metadata payload from the vector store
     * @return string Natural language response in French with inline source citations [1], [2], etc.
     * @throws \RuntimeException If Claude API fails or response generation produces invalid output
     */
    public function generateResponse(string $userQuery, array $searchResults): string;
}
