<?php
declare(strict_types=1);

namespace SearchAssistantAI\Service\Llm;

/**
 * Contract every LLM provider implements (self-hosted Ollama /
 * OpenAI-compatible, Claude, hosted gateway).
 *
 * Providers are listed in the `search_assistant.llm_providers` config registry and
 * resolved by LlmServiceFactory from the `search_assistant_llm_provider` setting.
 */
interface LlmInterface
{
    /**
     * Execute a conversational query to Claude API with context.
     *
     * @param string $userMessage User's message in French or any language
     * @param list<array<string, mixed>> $searchResults Optional search results to inject into system prompt for context-aware responses
     * @param array<string, mixed> $context Optional conversation context with keys: history (array of role/content pairs)
     * @return string Claude's response text in French
     */
    public function query(string $userMessage, array $searchResults = [], array $context = []): string;

    /**
     * Execute a conversational query with incremental (streamed) delivery.
     *
     * Behaves like query() but invokes $onDelta with each text fragment as
     * the provider produces it, then returns the full concatenated response.
     *
     * @param string $userMessage User's message in French or any language
     * @param list<array<string, mixed>> $searchResults Optional search results to inject into system prompt for context-aware responses
     * @param array<string, mixed> $context Optional conversation context with keys: history (array of role/content pairs)
     * @param callable|null $onDelta Called as fn(string $textChunk): void for each streamed fragment
     * @return string Full response text (concatenation of all fragments)
     */
    public function queryStream(string $userMessage, array $searchResults = [], array $context = [], ?callable $onDelta = null): string;

    /**
     * Extract structured criteria from user query for search filtering.
     *
     * Analyzes user message to identify search parameters, filters,
     * and structured data requirements.
     *
     * @param string $userMessage User's natural language query
     * @return array<string, mixed> Extracted criteria with keys for search parameters
     */
    public function extractCriteria(string $userMessage): array;

    /**
     * Format raw API response into structured output.
     *
     * Transforms Claude API JSON response into application-specific
     * format with metadata extraction.
     *
     * @param array<string, mixed> $apiResponse Raw response from Claude API
     * @return array<string, mixed> Formatted response with text, token usage, and metadata
     */
    public function formatResponse(array $apiResponse): array;

    /**
     * Change the active Claude model for subsequent queries.
     *
     * @param string $modelId Model identifier (e.g., claude-sonnet-4-5-20250929, claude-opus-4-5-20251101, claude-3-5-haiku-20241022)
     * @return void
     */
    public function setModel(string $modelId): void;
}
