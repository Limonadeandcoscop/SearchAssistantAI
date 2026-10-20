<?php
declare(strict_types=1);

namespace SearchAssistantAI\Settings;

use Omeka\Settings\Settings;

/**
 * Typed wrapper around the ~40 `search_assistant_*` settings the module reads from
 * Omeka's global settings store.
 *
 * Exists for two reasons:
 *
 * 1. **Centralised defaults.** Previously, defaults were repeated at each
 *    `$settings->get('search_assistant_X', 'default')` call site and occasionally
 *    disagreed (the classic being `search_assistant_embeddings_url` = localhost in
 *    one place and the Docker service name in another). Now there is one
 *    source of truth per key.
 *
 * 2. **Typed accessors.** `Settings::get()` returns mixed. Callers either
 *    cast on every read or ignore the type system. Accessors here apply
 *    the canonical cast once so downstream code works with `int`,
 *    `string`, `bool`, `float`, or `int[]` as appropriate.
 *
 * The raw `Omeka\Settings\Settings` is still available via `raw()` for
 * keys that don't yet have a typed accessor — migration is incremental.
 */
final class SearchAssistantSettings
{
    // ─── Connection mode (direct infra vs hosted gateway) ───────────

    public const KEY_CONNECTION_MODE = 'search_assistant_connection_mode';
    public const KEY_GATEWAY_URL = 'search_assistant_gateway_url';
    public const KEY_GATEWAY_TOKEN = 'search_assistant_gateway_token';
    public const KEY_GATEWAY_MODEL = 'search_assistant_gateway_model';
    public const KEY_GATEWAY_QUERY_ENABLED = 'search_assistant_gateway_query_enabled';

    // ─── Vector store ────────────────────────────────────────────────

    public const KEY_BACKEND = 'search_assistant_vector_store_backend';
    public const KEY_QDRANT_URL = 'search_assistant_qdrant_url';
    public const KEY_QDRANT_COLLECTION = 'search_assistant_qdrant_collection';
    public const KEY_QDRANT_API_KEY = 'search_assistant_qdrant_api_key';
    public const KEY_ES_URL = 'search_assistant_es_url';
    public const KEY_ES_INDEX = 'search_assistant_es_index';
    public const KEY_ES_API_KEY = 'search_assistant_es_api_key';
    public const KEY_ES_VERIFY_SSL = 'search_assistant_es_verify_ssl';
    public const KEY_COLLECTION_VERIFIED = 'search_assistant_collection_verified';

    // ─── Embeddings ──────────────────────────────────────────────────

    public const KEY_EMBEDDINGS_URL = 'search_assistant_embeddings_url';
    public const KEY_EMBEDDING_DIMENSION = 'search_assistant_embedding_dimension';
    public const KEY_EMBEDDINGS_API_FORMAT = 'search_assistant_embeddings_api_format';
    public const KEY_EMBEDDINGS_MODEL = 'search_assistant_embeddings_model';

    // ─── LLM ─────────────────────────────────────────────────────────

    public const KEY_LLM_PROVIDER = 'search_assistant_llm_provider';
    public const KEY_CLAUDE_API_KEY = 'search_assistant_claude_api_key';
    public const KEY_CLAUDE_MODEL = 'search_assistant_claude_model';
    public const KEY_CLAUDE_MAX_TOKENS = 'search_assistant_claude_max_tokens';
    public const KEY_CLAUDE_TEMPERATURE = 'search_assistant_claude_temperature';
    public const KEY_OLLAMA_URL = 'search_assistant_ollama_url';
    public const KEY_OLLAMA_MODEL = 'search_assistant_ollama_model';
    public const KEY_LLM_API_FORMAT = 'search_assistant_llm_api_format';
    public const KEY_MAX_CONTEXT_TOKENS = 'search_assistant_max_context_tokens';
    public const KEY_MAX_PAYLOAD_CHARS = 'search_assistant_max_payload_chars';
    public const KEY_MAX_PROPERTIES = 'search_assistant_max_properties';

    // ─── Prompts (admin-editable overrides of on-disk defaults) ──────

    public const KEY_PROMPT_INSTRUCTIONS = 'search_assistant_prompt_instructions';
    public const KEY_PROMPT_CRITERIA_EXTRACTION = 'search_assistant_prompt_criteria_extraction';
    public const KEY_PROMPT_RESPONSE_GENERATION = 'search_assistant_prompt_response_generation';
    /** One-shot flag: true once prompts have been seeded from the example files. */
    public const KEY_PROMPTS_SEEDED = 'search_assistant_prompts_seeded';

    // ─── Widget / UI ─────────────────────────────────────────────────

    public const KEY_WIDGET_ENABLED = 'search_assistant_widget_enabled';
    public const KEY_WIDGET_AUTHENTICATED_ONLY = 'search_assistant_widget_authenticated_only';
    public const KEY_PAGE_ENABLED = 'search_assistant_page_enabled';
    public const KEY_WIDGET_POSITION = 'search_assistant_widget_position';
    /** Where the floating widget appears: 'homepage' (default) or 'all' site pages. */
    public const KEY_WIDGET_DISPLAY = 'search_assistant_widget_display';
    public const KEY_PRIMARY_COLOR = 'search_assistant_primary_color';
    public const KEY_WELCOME_MESSAGE = 'search_assistant_welcome_message';

    // ─── Advanced ────────────────────────────────────────────────────

    public const KEY_SEARCH_LIMIT = 'search_assistant_search_limit';
    public const KEY_VERBOSE_LOGGING = 'search_assistant_verbose_logging';
    public const KEY_AUTO_PROCESS_QUEUE = 'search_assistant_auto_process_queue';
    public const KEY_SYNC_MODE = 'search_assistant_sync_mode';

    // ─── Rate limiting ───────────────────────────────────────────────

    public const KEY_RATE_LIMIT_MAX_REQUESTS = 'search_assistant_rate_limit_max_requests';
    public const KEY_RATE_LIMIT_WINDOW = 'search_assistant_rate_limit_window_seconds';
    public const KEY_API_RATE_LIMIT_MAX_TOKENS = 'search_assistant_api_rate_limit_max_tokens';
    public const KEY_API_RATE_LIMIT_WINDOW = 'search_assistant_api_rate_limit_window_seconds';
    public const KEY_RATE_LIMIT_STORAGE_DIR = 'search_assistant_rate_limit_storage_dir';
    public const KEY_API_RATE_LIMIT_STORAGE_DIR = 'search_assistant_api_rate_limit_storage_dir';

    // ─── Indexing scope ──────────────────────────────────────────────

    public const KEY_INDEXED_PROPERTIES = 'search_assistant_indexed_properties';
    public const KEY_INDEXED_COLLECTIONS = 'search_assistant_indexed_collections';

    // ─── GDPR / session ──────────────────────────────────────────────

    public const KEY_SESSION_SALT = 'search_assistant_session_salt';

    // ─── Timestamps (written by jobs) ────────────────────────────────

    public const KEY_LAST_INDEXATION = 'search_assistant_last_indexation';
    public const KEY_LAST_SYNC = 'search_assistant_last_sync';

    // ─── Defaults ────────────────────────────────────────────────────

    public const DEFAULT_CONNECTION_MODE = 'direct';
    public const DEFAULT_BACKEND = 'qdrant';
    public const DEFAULT_QDRANT_URL = 'http://localhost:6333';
    public const DEFAULT_COLLECTION = 'omeka_resources';
    public const DEFAULT_ES_URL = 'http://elasticsearch:9200';
    public const DEFAULT_EMBEDDINGS_URL = 'http://localhost:8000';
    public const DEFAULT_EMBEDDING_DIMENSION = 1024;
    public const DEFAULT_EMBEDDINGS_API_FORMAT = 'native';
    public const DEFAULT_LLM_PROVIDER = 'ollama';
    public const DEFAULT_CLAUDE_MODEL = 'claude-sonnet-4-5';
    public const DEFAULT_CLAUDE_MAX_TOKENS = 1024;
    public const DEFAULT_CLAUDE_TEMPERATURE = 0.7;
    public const DEFAULT_OLLAMA_URL = 'http://localhost:11434';
    public const DEFAULT_OLLAMA_MODEL = 'mistral';
    public const DEFAULT_LLM_API_FORMAT = 'ollama';
    public const DEFAULT_MAX_CONTEXT_TOKENS = 150000;
    public const DEFAULT_MAX_PAYLOAD_CHARS = 5000;
    public const DEFAULT_MAX_PROPERTIES = 20;
    public const DEFAULT_SEARCH_LIMIT = 10;
    public const DEFAULT_RATE_LIMIT_MAX_REQUESTS = 10;
    public const DEFAULT_RATE_LIMIT_WINDOW = 60;
    public const DEFAULT_API_RATE_LIMIT_MAX_TOKENS = 30000;
    public const DEFAULT_API_RATE_LIMIT_WINDOW = 60;
    public const DEFAULT_RATE_LIMIT_STORAGE_DIR = '/tmp/search_assistant_rate_limits';
    public const DEFAULT_API_RATE_LIMIT_STORAGE_DIR = '/tmp/search_assistant_api_rate_limits';
    public const DEFAULT_WIDGET_POSITION = 'bottom-right';
    public const DEFAULT_PRIMARY_COLOR = '#007bff';

    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /** Expose the underlying Omeka settings for keys without a typed accessor yet. */
    public function raw(): Settings
    {
        return $this->settings;
    }

    // ─── Connection mode ─────────────────────────────────────────────

    /**
     * 'direct' — the module talks to its own vector store / embeddings /
     * LLM (the historical behaviour). 'hosted' — everything goes through
     * the hosted gateway API with a single client token.
     */
    public function getConnectionMode(): string
    {
        $mode = (string) $this->settings->get(self::KEY_CONNECTION_MODE, self::DEFAULT_CONNECTION_MODE);
        return $mode === 'hosted' ? 'hosted' : self::DEFAULT_CONNECTION_MODE;
    }

    public function isHostedMode(): bool
    {
        return $this->getConnectionMode() === 'hosted';
    }

    public function getGatewayUrl(): string
    {
        return rtrim((string) $this->settings->get(self::KEY_GATEWAY_URL, ''), '/');
    }

    public function getGatewayToken(): string
    {
        return (string) $this->settings->get(self::KEY_GATEWAY_TOKEN, '');
    }

    /**
     * Model chosen from the gateway's per-client allowlist. Empty string
     * means "use the client's default model as configured on the gateway".
     */
    public function getGatewayModel(): string
    {
        return (string) $this->settings->get(self::KEY_GATEWAY_MODEL, '');
    }

    /**
     * Hosted server-side RAG: route /search-assistant/api/query through the
     * gateway's POST /v1/query instead of the local pipeline. Off by
     * default — switching it off is the rollback path to the legacy
     * hosted behaviour, no deploy needed.
     */
    public function isGatewayQueryEnabled(): bool
    {
        return (bool) $this->settings->get(self::KEY_GATEWAY_QUERY_ENABLED, false);
    }

    // ─── Vector store ────────────────────────────────────────────────

    /**
     * Backend key as stored (default qdrant). Which backends exist, and the
     * collection each one uses, is answered by VectorStoreRegistry.
     */
    public function getVectorStoreBackendSetting(): string
    {
        $b = trim((string) $this->settings->get(self::KEY_BACKEND, self::DEFAULT_BACKEND));
        return $b !== '' ? $b : self::DEFAULT_BACKEND;
    }

    public function getQdrantUrl(): string
    {
        return (string) $this->settings->get(self::KEY_QDRANT_URL, self::DEFAULT_QDRANT_URL);
    }

    public function getQdrantCollection(): string
    {
        return (string) $this->settings->get(self::KEY_QDRANT_COLLECTION, self::DEFAULT_COLLECTION);
    }

    public function getQdrantApiKey(): ?string
    {
        $v = $this->settings->get(self::KEY_QDRANT_API_KEY);
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function getEsUrl(): string
    {
        return (string) $this->settings->get(self::KEY_ES_URL, self::DEFAULT_ES_URL);
    }

    public function getEsIndex(): string
    {
        return (string) $this->settings->get(self::KEY_ES_INDEX, self::DEFAULT_COLLECTION);
    }

    public function getEsApiKey(): ?string
    {
        $v = $this->settings->get(self::KEY_ES_API_KEY);
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function getEsVerifySsl(): bool
    {
        return (bool) $this->settings->get(self::KEY_ES_VERIFY_SSL, false);
    }

    /**
     * Active collection/index name, chosen according to the configured backend.
     */
    // ─── Embeddings ──────────────────────────────────────────────────

    public function getEmbeddingsUrl(): string
    {
        return (string) $this->settings->get(self::KEY_EMBEDDINGS_URL, self::DEFAULT_EMBEDDINGS_URL);
    }

    public function getEmbeddingDimension(): int
    {
        return (int) $this->settings->get(self::KEY_EMBEDDING_DIMENSION, self::DEFAULT_EMBEDDING_DIMENSION);
    }

    /** 'native' (POST /embed) or 'openai' (POST /v1/embeddings). */
    public function getEmbeddingsApiFormat(): string
    {
        $f = (string) $this->settings->get(self::KEY_EMBEDDINGS_API_FORMAT, self::DEFAULT_EMBEDDINGS_API_FORMAT);
        return $f === 'openai' ? 'openai' : self::DEFAULT_EMBEDDINGS_API_FORMAT;
    }

    /** Model name sent to OpenAI-compatible embeddings servers; ignored by the native API. */
    public function getEmbeddingsModel(): string
    {
        return trim((string) $this->settings->get(self::KEY_EMBEDDINGS_MODEL, ''));
    }

    // ─── LLM ─────────────────────────────────────────────────────────

    public function getLlmProvider(): string
    {
        return (string) $this->settings->get(self::KEY_LLM_PROVIDER, self::DEFAULT_LLM_PROVIDER);
    }

    public function getClaudeApiKey(): string
    {
        return (string) $this->settings->get(self::KEY_CLAUDE_API_KEY, '');
    }

    public function getClaudeModel(): string
    {
        return (string) $this->settings->get(self::KEY_CLAUDE_MODEL, self::DEFAULT_CLAUDE_MODEL);
    }

    public function getClaudeMaxTokens(): int
    {
        return (int) $this->settings->get(self::KEY_CLAUDE_MAX_TOKENS, self::DEFAULT_CLAUDE_MAX_TOKENS);
    }

    public function getClaudeTemperature(): float
    {
        return (float) $this->settings->get(self::KEY_CLAUDE_TEMPERATURE, self::DEFAULT_CLAUDE_TEMPERATURE);
    }

    public function getOllamaUrl(): string
    {
        return (string) $this->settings->get(self::KEY_OLLAMA_URL, self::DEFAULT_OLLAMA_URL);
    }

    public function getOllamaModel(): string
    {
        return (string) $this->settings->get(self::KEY_OLLAMA_MODEL, self::DEFAULT_OLLAMA_MODEL);
    }

    /** API spoken by the self-hosted LLM: 'ollama' (native) or 'openai' (/v1/*). */
    public function getLlmApiFormat(): string
    {
        $f = (string) $this->settings->get(self::KEY_LLM_API_FORMAT, self::DEFAULT_LLM_API_FORMAT);
        return $f === 'openai' ? 'openai' : self::DEFAULT_LLM_API_FORMAT;
    }

    public function getMaxContextTokens(): int
    {
        return (int) $this->settings->get(self::KEY_MAX_CONTEXT_TOKENS, self::DEFAULT_MAX_CONTEXT_TOKENS);
    }

    public function getMaxPayloadChars(): int
    {
        return (int) $this->settings->get(self::KEY_MAX_PAYLOAD_CHARS, self::DEFAULT_MAX_PAYLOAD_CHARS);
    }

    public function getMaxProperties(): int
    {
        return (int) $this->settings->get(self::KEY_MAX_PROPERTIES, self::DEFAULT_MAX_PROPERTIES);
    }

    // ─── Prompts ─────────────────────────────────────────────────────

    /**
     * Admin-edited override for the search assistant system instructions. Empty
     * string means "use the on-disk default"; resolution is the
     * PromptService's job.
     */
    public function getPromptInstructions(): string
    {
        return (string) $this->settings->get(self::KEY_PROMPT_INSTRUCTIONS, '');
    }

    public function getPromptCriteriaExtraction(): string
    {
        return (string) $this->settings->get(self::KEY_PROMPT_CRITERIA_EXTRACTION, '');
    }

    public function getPromptResponseGeneration(): string
    {
        return (string) $this->settings->get(self::KEY_PROMPT_RESPONSE_GENERATION, '');
    }

    // ─── Advanced ────────────────────────────────────────────────────

    public function getSearchLimit(): int
    {
        return (int) $this->settings->get(self::KEY_SEARCH_LIMIT, self::DEFAULT_SEARCH_LIMIT);
    }

    public function isVerboseLogging(): bool
    {
        return (bool) $this->settings->get(self::KEY_VERBOSE_LOGGING, false);
    }

    public function isAutoProcessQueue(): bool
    {
        return (bool) $this->settings->get(self::KEY_AUTO_PROCESS_QUEUE, true);
    }

    /**
     * True when the admin has restricted the search assistant widget + API to
     * authenticated Omeka users. Lets administrators test the module
     * without exposing it to unauthenticated site visitors.
     */
    public function isWidgetAuthenticatedOnly(): bool
    {
        return (bool) $this->settings->get(self::KEY_WIDGET_AUTHENTICATED_ONLY, false);
    }

    // ─── Rate limiting ───────────────────────────────────────────────

    public function getRateLimitMaxRequests(): int
    {
        return (int) $this->settings->get(self::KEY_RATE_LIMIT_MAX_REQUESTS, self::DEFAULT_RATE_LIMIT_MAX_REQUESTS);
    }

    public function getRateLimitWindowSeconds(): int
    {
        return (int) $this->settings->get(self::KEY_RATE_LIMIT_WINDOW, self::DEFAULT_RATE_LIMIT_WINDOW);
    }

    public function getApiRateLimitMaxTokens(): int
    {
        return (int) $this->settings->get(self::KEY_API_RATE_LIMIT_MAX_TOKENS, self::DEFAULT_API_RATE_LIMIT_MAX_TOKENS);
    }

    public function getApiRateLimitWindowSeconds(): int
    {
        return (int) $this->settings->get(self::KEY_API_RATE_LIMIT_WINDOW, self::DEFAULT_API_RATE_LIMIT_WINDOW);
    }

    public function getRateLimitStorageDir(): string
    {
        return (string) $this->settings->get(self::KEY_RATE_LIMIT_STORAGE_DIR, self::DEFAULT_RATE_LIMIT_STORAGE_DIR);
    }

    public function getApiRateLimitStorageDir(): string
    {
        return (string) $this->settings->get(self::KEY_API_RATE_LIMIT_STORAGE_DIR, self::DEFAULT_API_RATE_LIMIT_STORAGE_DIR);
    }

    // ─── Indexing scope ──────────────────────────────────────────────

    /**
     * Decode `search_assistant_indexed_properties` (stored as JSON string) into
     * a plain array of property IDs. Empty array means "all properties".
     *
     * @return array<int, int|string>
     */
    public function getIndexedPropertyIds(): array
    {
        return $this->decodeIdList(self::KEY_INDEXED_PROPERTIES);
    }

    /**
     * Decode `search_assistant_indexed_collections` (stored as JSON string) into
     * a plain array of item-set IDs. Empty array means "all collections".
     *
     * @return array<int, int|string>
     */
    public function getIndexedCollectionIds(): array
    {
        return $this->decodeIdList(self::KEY_INDEXED_COLLECTIONS);
    }

    /**
     * @return array<int, int|string>
     */
    private function decodeIdList(string $key): array
    {
        $json = (string) $this->settings->get($key, '[]');
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    // ─── Session salt ────────────────────────────────────────────────

    /**
     * Server-side salt for HMACing session IDs so they aren't reversible to IPs.
     * Generated lazily on first use.
     */
    public function getOrCreateSessionSalt(): string
    {
        $salt = $this->settings->get(self::KEY_SESSION_SALT, '');
        if (!is_string($salt) || strlen($salt) < 32) {
            $salt = bin2hex(random_bytes(32));
            $this->settings->set(self::KEY_SESSION_SALT, $salt);
        }
        return $salt;
    }
}
