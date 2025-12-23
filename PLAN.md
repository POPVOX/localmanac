# LocAlmanac V1 Plan

## Milestone 0 — Project baseline
- [x] Set DB to PostgreSQL (local + env templates)
- [x] Add Pint + Pest (or PHPUnit) + basic CI-friendly scripts
- [x] Create `docs/` folder and add `ARCHITECTURE.md`

## Milestone 1 — Database schema (core)
### Cities & tenancy
- [x] Create `cities` table + City model
- [x] Add `city_id` to all city-scoped tables from the start

### Entities
- [x] Create `organizations`, `people`, `locations`
- [x] Create `entity_aliases` (polymorphic: entity_type/entity_id) + indexes

### Content & provenance
- [x] Create `articles` + `article_bodies`
- [x] Create `article_sources`
- [ ] Add explicit dedupe columns (future optimization; currently handled in code)

### Roles & taxonomy
- [x] Create `role_types` + `roles` (time-scoped)
- [x] Create `issue_areas` (hierarchy) + `tags`

### Claims (AI outputs as claims — NOT YET IMPLEMENTED)
- [ ] Create `claims` table (subject/predicate/object/value_json + provenance)
- [ ] Add enums/constants for the first 3 claim types:
  - [ ] article_mentions_person
  - [ ] article_mentions_org
  - [ ] article_issue_area

## Milestone 2 — Ingestion pipeline (no AI yet)
- [x] Create `scrapers` + `scraper_runs`
- [x] Implement `Ingestion\ScrapeRunner`
- [x] Implement Fetchers:
  - [x] RssFetcher
  - [x] DocumentersFetcher (profile-based, Google Docs full-text)
  - [x] HtmlFetcher (generic, selector-based)
- [x] ScrapeRunner routes html fetchers by config.profile
- [x] Implement `Ingestion\Deduplicator` (URL + hash strategy)
- [x] Implement `Ingestion\ArticleWriter` (Article + Body + Source)
- [x] Add a demo scraper config and seed it
- [x] Add a command: `php artisan scrape:run {scraper}`

## Milestone 3 — Extraction v1 (text + OCR, claims later)
- [x] Implement PDF text extraction + OCR fallback
- [x] Persist extracted full text to ArticleBody.cleaned_text
- [ ] Implement `Extraction\Extractor` (start heuristic; AI later)
- [ ] Implement `Extraction\ClaimWriter`
- [ ] Add command: `php artisan extract:article {id}`
- [ ] Ensure extraction never writes “facts” directly (claims only)

## Milestone 3.5 — Analysis layer (summaries, tagging, civic relevance scoring)
- [ ] Create `article_analyses` table (dimension scores + final score + provenance + status)
- [ ] Implement Phase 1 heuristic scoring (fast, deterministic)
  - [ ] reading level / jargon density
  - [ ] timeliness (future dates, deadlines)
  - [ ] agency signals (calls to action, comment periods, meetings)
  - [ ] source type classification (gov/news/nonprofit/etc.)
- [ ] Implement Phase 2 LLM scoring for high-value content (store model + prompt_version + confidence)
- [ ] Compute weighted `civic_relevance_score` using the framework dimensions
- [ ] Persist extracted opportunities (dates/locations/URLs) for UI + chatbot
- [ ] Add minimal feedback capture (helpful/not helpful) for later calibration

## Milestone 4 — Resolution v1 (aliases-first)
- [ ] Implement `Resolution\EntityResolver`:
  - [ ] exact match on slug/name
  - [ ] alias match
  - [ ] optional fuzzy match (pg_trgm later)
- [ ] Add admin-only artisan tools:
  - [ ] `alias:add`
  - [ ] `alias:list`

## Milestone 5 — Search v1 (Scout + Meilisearch)
- [x] Install Scout + Meilisearch driver
- [x] Index Articles (title, summary, cleaned_text)
- [x] City-scoped search enforced
- [ ] Tune Meilisearch filters/sorting (city_id, published_at)
- [ ] Add a simple search endpoint: `/search?q=...`

## Milestone 6 — Q&A v1 (context builder + citations)
- [ ] Implement `Query\ContextBuilder`:
  - [ ] resolve city
  - [ ] resolve entities
  - [ ] fetch relevant articles + sources + approved claims
- [ ] Implement `LLM\AnswerSynthesizer` that returns:
  - [ ] answer text
  - [ ] list of citations (source_url + title)
- [ ] Add `/ask` endpoint (JSON) first; UI later

## Milestone 7 — Admin UI (IN PROGRESS)
- [x] Scraper management UI
- [ ] Claim review UI (approve/reject)
- [ ] Alias management UI

## Definition of Done (v1)
- [x] One city seeded
- [x] 2–3 scrapers reliably ingesting
- [x] Search returns sane results
- [ ] Ask endpoint answers with citations
- [ ] Claims exist + can be reviewed
