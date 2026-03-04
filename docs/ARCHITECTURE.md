# Localmanac Architecture (Current)

Updated: March 2026

## System Goal

Localmanac provides city-scoped civic awareness by combining:

- structured ingestion from local sources
- enrichment and projection of article meaning
- evidence-backed retrieval and answer synthesis
- operational controls in super-admin interfaces

## High-Level Runtime Flow

1. Ingestion writes canonical content records.
2. Enrichment jobs analyze article text and write derived outputs.
3. Projection layers materialize enrichment outputs for UI and retrieval.
4. Search and chat retrieve city-scoped evidence.
5. Livewire UIs render dashboards, demos, and admin workflows.

## Primary Data Domains

- `cities`
- `organizations`, `people`, `locations`, `entity_aliases`
- `scrapers`, `scraper_runs`, `articles`, `article_bodies`, `article_sources`
- `event_sources`, `event_ingestion_runs`, `events`, `event_source_items`
- `claims`, `article_entities`, `article_issue_areas`, `keywords`, `article_keywords`
- `article_analyses`, `civic_actions`, `process_timeline_items`, `article_explainers`
- `chat_sources`, `chat_source_pages`, `chat_source_chunks`
- `site_feedback`

## Authorization Model

Authorization gates are explicit and super-admin based:

- `access-admin`
- `manage-raw-scraper-config`

Both gates resolve through `User::isSuperAdmin()`.

## Application Surfaces

- Public/demo routes: home, article explainer, demo calendar, questions
- Authenticated user surface: dashboard with article search + chat
- Admin surface: cities, organizations, scrapers, event sources, events, chat sources, feedback
- API endpoint: `POST /ask` (throttled via `ask` rate limiter)

## Search and Retrieval

- Scout is used for article search and chat-source retrieval.
- Dashboard article search uses Scout candidate retrieval and deterministic ordering, with SQL fallback when Scout fails.
- Chat source selection uses Scout first, then priority fallback from DB.

## Dashboard Article Discovery

Implemented dashboard article behavior includes:

- full-text search over article-indexed content
- city-scoped result filtering
- issue-area filtering
- paginated feed/results
- SQL-like fallback path when Scout retrieval throws

## Queue and Job Topology

- `analysis` queue: enrichment and scraper-run jobs
- `ingestion` queue: chat source crawl jobs (configurable)
- `embedding` queue: embedding jobs (configurable)

Recurring schedule commands:

- `scrape:schedule`
- `calendar:schedule`

## Reliability Safeguards

- Sequence drift auto-recovery for selected Postgres PK sequences
- Anti-bot detection with Playwright fallback paths in fetchers
- Deterministic fallback answers and citations in chat when model output is insufficient
- Configurable ingestion quality guard for low-quality article suppression

## Out of Scope in This Doc

- Per-source selector tuning details
- Prompt-level enrichment schema details
- Product roadmap decisions

See dedicated docs for those topics.
