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

### Claims (AI outputs as claims)
- [x] Create `claims` table (subject/predicate/object/value_json + provenance)
- [x] Add enums/constants for the first 3 claim types:
  - [x] article_mentions_person
  - [x] article_mentions_org
  - [x] article_issue_area
- [x] Add indexes/constraints for claims:
  - [x] index on (article_id, claim_type)
  - [x] index on (city_id, claim_type)
  - [x] optional: unique guard for (article_id, claim_type, subject_type, subject_id, value_hash)

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

## Milestone 3 — Extraction v1 (text + OCR + enrichment)
- [x] Implement PDF text extraction + OCR fallback 
- [x] Persist extracted full text to ArticleBody.cleaned_text
- [x] Implement `Extraction\Extractor` (start heuristic; AI later)
- [x] Implement `Extraction\ClaimWriter`
- [x] Add command: `php artisan extract:article {id}`
- [x] Ensure extraction never writes “facts” directly (claims only)

### Structured enrichment (entities + keywords + issue areas)
- [x] Implement `Extraction\Enricher` (LLM; structured JSON output)
  - [x] People extraction (name + role/title if present + evidence spans)
  - [x] Organization extraction (name + type guess + evidence spans)
  - [x] Location extraction (name/address if present + evidence spans)
  - [x] Keyword/topic extraction (normalized keywords + evidence spans)
  - [x] Issue area suggestions (map to `issue_areas` slugs + evidence)
- [x] Persist enrichment outputs as Claims (never directly on Articles)
  - [x] Use `claims` as the source of truth (with evidence spans + confidence + provenance)
  - [x] Add `Extraction\ClaimWriter` and write claims for people/orgs/locations/keywords/issue areas
- [x] Add projection tables (derived from Claims; optional but useful for UI/search)
  - [x] `article_entities` (article_id, entity_type, entity_id, confidence, source)
  - [x] `article_issue_areas` (article_id, issue_area_id, confidence, source)
  - [x] `keywords` (city_id, name, slug) + unique (city_id, slug)
  - [x] `article_keywords` (article_id, keyword_id, confidence, source)
- [x] Implement `Extraction\ProjectionWriter` to upsert projection tables from approved/high-confidence claims
- [x] Dispatch enrichment automatically after `ArticleBody.cleaned_text` is written (post-extraction), with dedicated queue isolation (e.g. `enrichment`)

## Milestone 3.5 — Analysis layer (summaries, tagging, civic relevance scoring)
- [x] Create `article_analyses` table (dimension scores + final score + provenance + status)
- [x] Implement Phase 1 heuristic scoring (fast, deterministic)
  - [x] reading level / jargon density
  - [x] timeliness (future dates, deadlines)
  - [x] agency signals (calls to action, comment periods, meetings)
  - [x] source type classification (gov/news/nonprofit/etc.)
- [x] Implement Phase 2 LLM scoring for high-value content (store model + prompt_version + confidence)
- [x] Compute weighted `civic_relevance_score` using the framework dimensions
- [x] Persist extracted opportunities (dates/locations/URLs) for UI + chatbot
- [x] Add minimal feedback capture (helpful/not helpful) for later calibration
- [x] Analysis + enrichment executed via Prism-powered multi-pass LLM calls (analysis, entities, explainer, projections)

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
- [ ] Incorporate civic_relevance_score into search reranking

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
- [ ] Claim review UI (pending)
- [ ] Article enrichment UI (pending)
- [ ] Alias management UI (pending)

## Definition of Done (v1)
- [x] One city seeded
- [x] 2–3 scrapers reliably ingesting
- [x] Search returns sane results
- [ ] Ask endpoint answers with citations
- [x] Claims exist (persisted + projected)
- [ ] Claims can be reviewed in UI
