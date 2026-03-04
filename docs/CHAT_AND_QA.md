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

Retrieval and synthesis then execute in `AnswerSynthesizer`.

## Answer Synthesis Behavior

### Primary Path

- Structured agent response with tools
- Normalized citations from structured payload, tool metadata, or tool result context

### Fallback Behavior

- If model answer is empty or no-answer and seed evidence exists, deterministic seed-evidence answer fallback is attempted.
- If citations are missing but answer exists, fallback citations are derived from selected sources.
- If answer or citations remain unusable, AskService returns deterministic fallback response.

### Citation Limits

- Citation list is normalized and deduplicated.
- `chat.link_limit` is enforced.

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

### Web Fallback Policy

Web search for events is controlled by config and defaults to local-first behavior:

- web fallback can be enabled for events
- default behavior is to use it only when local event results are empty
- allowed domains policy can merge city event-source domains with global allowlist

## Dashboard Streaming and Memory

Dashboard (`App\Livewire\Dashboard`) behavior:

- Authenticated users use streaming synthesis via `answerStreamingForUser`
- Guest interactions use non-streaming `answer`
- conversation memory is enabled only when `chat.memory_enabled` and user is authenticated
- conversation ID persists in session key `chat.memory_session_key` (default `chat.conversation_id`)
- `startNewConversation()` clears session conversation ID and local message history

## Time Context Handling

Prompt construction includes explicit city-local temporal context:

- city timezone
- current local datetime
- current local date and weekday
- instruction to resolve relative date phrases using city-local time

## Operational Command

- `php artisan chat:ingest-sources`

This command crawls chat sources and queues ingestion/embedding workflows with city/source filters and sync/force controls.
