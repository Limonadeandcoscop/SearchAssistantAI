# Search Assistant AI module for Omeka S

Natural-language search and conversational answers for Omeka S digital
collections. The module indexes Items, Media and Item Sets into a Qdrant
vector store, retrieves records by semantic similarity, and has a
self-hosted LLM (Ollama or any OpenAI-compatible server) write an answer
that cites them.

**Author:** Limonade & Co · **License:** GPL-3.0-or-later

The **Search Assistant AI Pro** add-on module adds Claude, Elasticsearch, a hosted
gateway, smarter search, editable prompts, stored conversations and
analytics; see [Search Assistant AI Pro](#search-assistant-ai-pro) below.

## Features

- **Semantic search.** Every resource is embedded (one vector per
  resource) and stored in Qdrant. A question such as "documents sur la
  marine corse du XIXᵉ siècle" finds relevant records even when none of
  those words appear in them.
- **Answers with citations.** The top records are sent to the LLM, which
  answers from them only and cites them. When nothing matches, the answer
  says so instead of drawing on general knowledge.
- **Streaming.** Answers appear as they are generated (Server-Sent
  Events), with a fallback for older browsers.
- **Floating chat widget** on the homepage or on every page, with its
  position, colour and welcome message set on the Interface page.
- **Live indexing.** Creating, editing or deleting a resource updates the
  vector store through a sync queue processed by Omeka's job queue. A full
  reindex is available from the admin (Reindex page, dashboard) and from
  the command line.
- **Dashboard** with service health (vector store, embeddings, LLM),
  index statistics, sync queue status and actions (reindex, sync, clear
  the vector store, clear the embeddings cache).
- **Rate limiting** per IP address (10 questions per minute).
- **French and English** interface.

## Requirements

- **Omeka S** 3.2 or later (tested on 4.1)
- **PHP** 7.4 or later
- **MySQL** 5.7.9+ or MariaDB 10.2.6+
- **Qdrant** 1.x
- An **embeddings service** returning vectors of the configured
  dimension, through either:
  - a native `POST /embed` endpoint taking `{"text": ["..."]}` and
    returning `{"embeddings": [[...], ...]}` (for example a small service
    running `BAAI/bge-m3`, 1024 dimensions), or
  - the OpenAI-compatible `POST /v1/embeddings` endpoint (vLLM, TEI,
    Ollama...).
- A **self-hosted LLM** speaking either the native Ollama API
  (`/api/chat`) or the OpenAI-compatible API (`/v1/chat/completions`:
  vLLM, TGI, LiteLLM...).

The dashboard works without an LLM; answers need one.

## Installation

1. Copy the module into `modules/SearchAssistantAI` of your Omeka S install.
2. If the release has no `vendor/` directory, install the PHP
   dependencies: `cd modules/SearchAssistantAI && composer install --no-dev`.
3. In the Omeka S admin, open **Modules** and install **Search Assistant AI**. The
   installer creates one table, `search_assistant_sync_queue`.

### First-time setup

1. **Module configuration** (`/admin/module/configure?id=SearchAssistantAI`): enter
   the Qdrant URL and collection, the embeddings service URL, format and
   vector dimension (1024 for `bge-m3`), then the LLM API format, URL
   (`http://ollama:11434` for an Ollama container on the same Docker
   network) and model name. Save; the dashboard creates the collection.
2. **Interface** (`/admin/search-assistant/config`): turn the widget on, choose
   where it shows, its position, colour and welcome message.
3. **Reindex** (`/admin/search-assistant/reindex`, or the dashboard button): fill
   the vector store. The job runs in the background; progress shows on
   the page. From the shell: the `vector:reindex` laminas-cli command.
4. Open a public page and ask a question about your content.

## Admin pages

| Page | Content |
|---|---|
| `/admin/module/configure?id=SearchAssistantAI` | Vector store, embeddings, LLM, verbose logging. |
| `/admin/search-assistant/dashboard` | Health checks, index statistics, sync queue, actions. |
| `/admin/search-assistant/config` (Interface) | Widget on/off, where it shows, position, colour, welcome message. |
| `/admin/search-assistant/reindex` | Preview, launch, follow and cancel a full reindex. |

All Search Assistant AI admin pages are restricted to global administrators.

## How a question is answered

`POST /search-assistant/api/query`:

```
ChatController
 └─ QueryProcessorService
     ├─ 1. search query      = the question as typed
     ├─ 2. SemanticSearchService: embeddings + Qdrant search (10 results)
     └─ 3. LLM answer
          ├─ system ← config/prompts/search_assistant_instructions_fr.example.txt
          └─ user   ← response_generation system_prompt + question + records
```

The prompts are the files shipped under `config/prompts/`.

## Search Assistant AI Pro

Search Assistant AI Pro is a separate module installed next to this one (it requires
Search Assistant AI 1.0.0 or later). It adds:

- Claude (Anthropic) as LLM, Elasticsearch as vector store, and a hosted
  gateway connection that needs no local vector store, embeddings
  service or LLM;
- criteria extraction and contextual re-ranking for better search;
- editable prompts, stored conversations with a browser, analytics and
  usage tiles on the dashboard;
- an Indexing page (which properties and item sets are indexed),
  editable search, rate-limit and token settings, a choice of sync mode,
  a logged-in-only widget option;
- a dedicated `/search-assistant` page and a search results page.

## Extending

Other modules plug in without changing this one, through:

- config registries under `$config['search_assistant']`: `llm_providers`,
  `vector_store_backends`, `config_form` (sections, layout, assets,
  reindex watch-list), `dashboard.panels`, `listeners`;
- service aliases: `CriteriaExtractorInterface`, `ResultScorerInterface`,
  `PromptProviderInterface`, `ChatAccessPolicyInterface`;
- events: `ChatEvents::QUERY_RECEIVED`, `QUERY_ANSWERED`, `WIDGET_RENDER`;
- the admin navigation (string keys with an `order`).

## Testing

```
composer install
composer test     # PHPUnit
composer stan     # PHPStan level 6
composer ci       # both
```

The suite needs no database but loads the Omeka autoloader, so run it
from a checkout inside an Omeka S install. Scripts under `test/scripts/`
are manual smoke tests, not part of `composer test`.

## Privacy

- Questions are sent to the LLM you configure. With a self-hosted LLM
  they stay on your infrastructure.
- Rate limiting keeps, per IP address, the times of the last requests in
  a file under `/tmp/search_assistant_rate_limits` (named after the IP); entries
  older than the window are dropped.
- The free module does not store conversations.

## Uninstall

**Modules → Search Assistant AI → Deactivate → Uninstall** drops `search_assistant_sync_queue`.
It leaves the vector store collection alone (use **Clear vector store** on
the dashboard first if you want it emptied) and does not touch
Search Assistant AI Pro's tables.

Release history: `CHANGELOG.md`.
