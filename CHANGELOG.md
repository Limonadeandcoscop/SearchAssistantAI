# Changelog

All notable changes to the Search Assistant AI module are documented in
this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
aims to adhere to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-10-21

First public release.

### Added
- Semantic search over Omeka S Items, Media and Item Sets, stored in
  Qdrant.
- Embeddings through a native `/embed` endpoint or an OpenAI-compatible
  `/v1/embeddings` endpoint.
- Answers with citations from a self-hosted LLM speaking the native
  Ollama API or the OpenAI-compatible API, blocking or streamed
  (Server-Sent Events).
- Floating chat widget on the homepage or on every page, with position,
  colour and welcome message set on the Interface page.
- Live indexing through a sync queue processed by Omeka's job queue; full
  reindex from the Reindex page, the dashboard or the `vector:reindex`
  command.
- Admin dashboard: service health, index statistics, sync queue status
  and actions (reindex, sync, clear the vector store, clear the
  embeddings cache).
- Per-IP rate limiting of the chat API.
- Admin pages restricted to global administrators.
- French and English translations.
- Extension points for other modules: config registries
  (`llm_providers`, `vector_store_backends`, `config_form`,
  `dashboard.panels`, `listeners`), service aliases
  (`CriteriaExtractorInterface`, `ResultScorerInterface`,
  `PromptProviderInterface`, `ChatAccessPolicyInterface`), chat events
  (`ChatEvents::QUERY_RECEIVED`, `QUERY_ANSWERED`, `WIDGET_RENDER`), keyed
  admin navigation and `SearchAssistantAcl::addResources()`.
