# LocAlmanac Architecture (V1)

## Goals
LocAlmanac is a civic information platform that ingests public sources (news, agendas, minutes, etc.), normalizes them into structured entities, and answers questions with citations.

V1 prioritizes:
- reliable ingestion + provenance
- entity resolution (aliases)
- “AI outputs are claims” (not facts)
- search as a projection (not the database)

## Non-Goals (V1)
- complex UI/UX polish
- multi-city federation (we support `city_id` everywhere, but ship with 1 city)
- a full knowledge graph engine
- perfect entity resolution (we start deterministic; improve iteratively)

---

## Core Principles
1. **Database is source of truth.** Search is derived.
2. **Provenance is mandatory.** No orphaned content.
3. **AI writes claims, never facts directly.**
4. **Models are dumb.** Orchestration lives in Services/Actions.
5. **City scoping is explicit.** Every query is city-aware.

---

## Data Model Overview

### City / Tenancy
- `cities`
- `city_id` is required on city-scoped records.
- Taxonomies may be global later, but V1 assumes city-scoped unless explicitly global.

### Content & provenance
- `articles` contain metadata (title, summary, published_at, canonical_url, content_hash, status).
- `article_bodies` store raw + cleaned text (and optionally raw_html).
- `article_sources` store source URLs + type and optionally organization attribution.

### Entities
- `organizations`, `people`, `locations`
- `entity_aliases` provides alternative names for resolution (polymorphic by entity_type/entity_id).

### Roles (time-scoped truth)
- `role_types`, `roles`
- roles are time-bounded (start_date/end_date) and optionally “active”.
- constraints are enforced at DB level when possible (Postgres partial unique indexes), otherwise in application logic + tests.

### Taxonomy
- `issue_areas` supports hierarchy via `parent_id`.
- `tags` supports loose thematic grouping.

### Claims (AI / extraction outputs)
- `claims` are structured assertions with provenance:
  - subject_type/subject_id
  - predicate
  - object_type/object_id OR value_json
  - confidence, method, model, prompt_version
  - source_article_id (required for AI extraction claims)
  - approval fields (approved_at / rejected_at)

Claims are the bridge between unstructured content and structured entities.

---

## Application Layers

### 1) Ingestion (Scrape → Store)
- Inputs: scraper config
- Outputs: Article + ArticleBody + ArticleSource (+ ScraperRun record)
- Responsibilities:
  - fetching (rss/html/pdf/api)
  - parsing
  - dedupe (canonical URL + content hash + source UID)
  - writing normalized content to DB

**Rule:** Ingestion may create entities only if explicitly configured (e.g., known organization attribution), otherwise entity creation happens during curation/resolution.

### 2) Extraction (Text → Claims)
- Inputs: ArticleBody.cleaned_text
- Outputs: Claims only (no facts)
- Responsibilities:
  - identify mentions (people/orgs) and topic hints (issue areas)
  - record provenance: method/model/prompt_version/confidence
  - never “auto-create” entities without a human/curation path

V1 may start with heuristics; AI is introduced behind this interface.

### 3) Resolution (Mentions → Entities)
- Inputs: a mention string + city_id + context
- Outputs: a resolved entity OR a ranked candidate list
- Strategy:
  - exact match on slug/name
  - alias match (entity_aliases)
  - optional fuzzy match later (Postgres `pg_trgm`)
- Resolution should be deterministic whenever possible.

### 4) Query + Answering
- `ContextBuilder`:
  - resolves city
  - resolves entities
  - gathers relevant articles + sources + roles + approved claims
- `AnswerSynthesizer`:
  - produces answer text
  - returns citations (URLs + titles)
  - never invents sources; citations must map to stored `article_sources`

**Rule:** The LLM receives a bounded context bundle and returns a structured answer + citations.

---

## Search
We use Laravel Scout with a dedicated search engine (Meilisearch preferred).
- Indexed: articles (and later orgs/people)
- Search is used for discovery and retrieval ranking.
- The DB remains the authority for joins, roles, and facts.

**Rule:** never rely on the search index for truth; only for finding candidate records.

---

## Code Organization (Laravel)
Recommended namespaces:
- `App\Domain\*` (optional: domain models/value objects)
- `App\Services\Ingestion\*`
- `App\Services\Extraction\*`
- `App\Services\Resolution\*`
- `App\Services\Query\*`
- `App\Services\LLM\*`
- `App\Jobs\*` (thin: call services)
- `App\Console\Commands\*` (thin: call services)

**Rule:** Controllers/Livewire/Commands should orchestrate, not implement business logic.

---

## Testing Strategy (V1)
- Unit tests:
  - dedupe logic
  - parsers (with fixtures)
  - resolver behavior (aliases)
- Integration tests:
  - ingestion end-to-end with mocked HTTP
  - extraction writing claims
  - context builder returns correct citations

**Rule:** Every pipeline stage should be testable without network calls.

---

## Security & Safety
- Never store secrets in repo.
- Scraped content is untrusted: sanitize before display.
- Rate-limit public endpoints.
- Log IDs, not raw content.

---

## Operational Notes
- Postgres is the primary DB (RDS in production).
- Background work uses queues for scrapers/extraction.
- All ingestion and extraction operations create audit records (scraper_runs, claims provenance).

---

## “Stop the Line” Rules
If any of these happen, treat as a bug:
- an Article exists without a Source
- an AI/extraction writes directly to facts tables instead of Claims
- city scoping is missing from a query touching city-scoped tables
- citations returned that do not map to stored source URLs
