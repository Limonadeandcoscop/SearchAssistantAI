# Changelog

All notable changes to the Search Assistant AI module are documented in
this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
aims to adhere to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed
- Before the module was configured, every request (public pages
  included) tried to reach Qdrant three times and logged a warning. The
  collection check now waits for the first configuration and retries at
  most every five minutes after a failure.
- A reindex now creates the configured collection when it is missing,
  instead of failing on every resource.
- Changing the Qdrant URL, collection, embedding model or dimension now
  re-checks the collection and asks for a full reindex.
- Reindex page: the preview failed, so a reindex could not be launched;
  the collection name shown was wrong; untranslated labels.
- A cancelled reindex job now ends as "stopped" instead of "completed".
- The Ollama / OpenAI-compatible provider now applies the record limits
  (characters per field, properties per record, context budget): a long
  full text no longer makes the LLM call time out.
- Fresh install: verbose logging and the widget were shown as enabled in
  the forms while they were off; the collection field showed a name
  other than the default.
- The widget greeting and the chat API messages follow the site
  language (English msgids with French translations); an empty welcome
  message gives the translated default.

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
