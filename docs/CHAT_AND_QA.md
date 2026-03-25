# Chat and Q&A (Current)

Updated: March 2026

## Surfaces

Implemented chat/Q&A surfaces:

- API endpoint: `POST /ask`
- Dashboard Livewire chat (authenticated users)
- Demo questions page (non-streaming ask flow)

## `/ask` Endpoint Contract

Route:

- `POST /ask` with `throttle:ask`

Request validation (`AskRequest`):

- `question` required string, max 800 chars
- `city_id` optional existing city ID
- `city_slug` optional existing city slug

Response shape (`AskService::answer`):

- `answer` string
- `citations` array of `{title, source_url, type}`
- `city` object `{id, name, slug}`
- `meta` object `{sources_used, pages_fetched, cache_hits}`

## Source Selection and Retrieval

`ChatSourceSelector` behavior:

1. Try Scout search over `ChatSource` by question relevance.
2. If results are insufficient or Scout fails, merge priority-ordered DB fallback sources.
3. Deduplicate and enforce `chat.max_sources` limit.

All questions flow through a single retrieval-and-synthesis path via `AnswerSynthesizer`. There is no separate routing for "updates" or "reference" question types.

## Retrieval Pipeline

`ChatSourceRetriever` performs multi-source retrieval using the Laravel AI SDK:

### Vector Search (SDK)

- Chat source chunks: `ChatSourceChunk::query()->whereVectorSimilarTo('embedding', $question)`
- Article chunks: `ArticleChunk::query()->whereVectorSimilarTo('embedding', $question)` (filtered to published articles in the same city)
- Controlled by `chat.vector_enabled` and `chat.article_chunks_enabled` config flags
- The SDK handles embedding generation internally — no manual `EmbeddingClient` calls in the retriever

### Full-Text Search

- PostgreSQL `websearch_to_tsquery` on chat source chunks with relaxed OR fallback
- Article FTS on title, summary, and body text
- LIKE-based fallback for non-PostgreSQL drivers

### Reranking (SDK)

- `Reranking::of($snippets)->rerank($question)` replaces manual scoring heuristics
- Provider chain: Cohere (primary), Jina (fallback), configured via `chat.reranking_provider_chain`
- On provider failure, falls back to ordering by original retrieval scores
- Controlled by `chat.reranking_enabled` config flag

### Query Expansion

- `QueryExpander` agent generates 2–3 specific sub-queries for broad questions
- Broad = fewer than 4 content words or matching aggregation/recency patterns
- Each sub-query runs through the full retrieval pipeline; results are merged and deduplicated
- On LLM failure, retrieval proceeds with the original question only
- Controlled by `chat.query_expansion_enabled` config flag

### Evidence Merging

- Results from all sources (chat chunks, article chunks, article FTS) are merged
- Deduplicated by source URL and content hash (SHA-1 of normalized snippet)
- Combined result set respects `chat.retrieval_max_evidence` limit

## Article Chunk Embeddings

Articles are now searchable via vector similarity in addition to full-text search:

- `article_chunks` table stores chunked article body text with pgvector embeddings (1536 dimensions, text-embedding-3-small)
- `ArticleChunkEmbedder` service chunks article bodies using the existing `Chunker` and generates embeddings via `EmbeddingClient`
- Chunks are generated automatically during article enrichment (`EnrichArticle` job)
- Backfill command: `php artisan chat:backfill-article-chunks` with `--force` and `--batch-size` options

## Navigation Page Filtering

Navigation and hub pages are filtered from the chunk index:

- `NavigationPageClassifier` detects pages with high URL density (>15%) or zero prose sentences
- `ChatSourceCrawler` rejects navigation pages during crawl before storing
- Purge command: `php artisan chat:purge-navigation-pages` with `--dry-run` flag

## Answer Synthesis Behavior

### Primary Path

- Structured agent response with tools (SimilaritySearch, EventSearchTool)
- Normalized citations from structured payload, tool metadata, or tool result context
- LLM is instructed to format street addresses as clickable Google Maps links

### Fallback Behavior

- If model answer fails grounding (hallucinated URLs, phone numbers, etc.) and seed evidence exists, a deterministic seed-evidence answer fallback is attempted
- If citations are missing but answer exists, fallback citations are derived from seed evidence
- If answer or citations remain unusable, AskService returns deterministic fallback response

### Answer Quality Judge

- `AnswerQualityJudge` agent classifies answers as `useful`, `no_answer`, `refusal`, or `vague`
- Runs after answer generation, non-blocking — failures are logged and never block the response
- Classification logged to `chat.answer_quality` for monitoring

### Grounding Check

- Validates that URLs, phone numbers, currency values, and addresses in the answer appear in seed evidence
- Google Maps URLs (generated from address linking instructions) are exempt from grounding validation
- Entity grounding is enforced for questions that reference specific organizations or departments

### Citation Limits

- Citation list is normalized and deduplicated
- `chat.link_limit` is enforced

## Event-Aware Q&A

Implemented event support in `AnswerSynthesizer` uses:

- `EventIntentDetector`
- `EventWindowResolver`
- `EventSearchService`
- `EventSearchTool`

Behavior summary:

1. Detect event intent from question language.
2. Resolve temporal window from relative or explicit date phrasing.
3. Query local event data for the city.
4. If model output is insufficient and local events exist, return deterministic event summary fallback.
5. If no local events exist, return explicit no-events guidance.

The event pipeline (EventIntentDetector, EventSearchService, EventWindowResolver, EventSearchTool) is unchanged by the SDK-native retrieval pipeline work.

## Dashboard Streaming and Memory

Dashboard (`App\Livewire\Dashboard`) behavior:

- Authenticated users use streaming synthesis via `answerStreamingForUser`
- Guest interactions use non-streaming `answer`
- Conversation memory is enabled only when `chat.memory_enabled` and user is authenticated
- Conversation ID persists in session key `chat.memory_session_key` (default `chat.conversation_id`)
- `startNewConversation()` clears session conversation ID and local message history

### Sample Query Pills

Dashboard displays four sample query pills:

- "What's new this week?" — summarize recent local updates
- "Upcoming meetings" — city council, board, and public meetings
- "How do I...?" — building permit application process
- "Service alerts" — active service disruptions

## Time Context Handling

Prompt construction includes explicit city-local temporal context:

- City timezone
- Current local datetime
- Current local date and weekday
- Instruction to resolve relative date phrases using city-local time

## Configuration

Key chat config entries in `config/chat.php`:

- `vector_enabled` — enable/disable vector search
- `fts_enabled` — enable/disable full-text search
- `article_chunks_enabled` — enable/disable article chunk vector search
- `reranking_enabled` — enable/disable SDK reranking
- `reranking_provider` — primary reranking provider (default: cohere)
- `reranking_provider_chain` — fallback chain (default: cohere, jina)
- `query_expansion_enabled` — enable/disable LLM query expansion
- `query_expansion_provider` — provider for query expansion
- `retrieval_max_evidence` — max evidence items across all sources
- `retrieval_chunk_limit` — max chunks per retrieval pass

## Operational Commands

- `php artisan chat:ingest-sources` — crawl and embed chat source pages
- `php artisan chat:backfill-article-chunks` — backfill article chunk embeddings (`--force`, `--batch-size`)
- `php artisan chat:purge-navigation-pages` — remove navigation pages from chunk index (`--dry-run`)
