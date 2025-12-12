# LocAlmanac V1 Plan

## Milestone 0 — Project baseline
- [ ] Set DB to PostgreSQL (local + env templates)
- [ ] Add Pint + Pest (or PHPUnit) + basic CI-friendly scripts
- [ ] Create `docs/` folder and add `ARCHITECTURE.md`

## Milestone 1 — Database schema (core)
### Cities & tenancy
- [ ] Create `cities` table + City model
- [ ] Add `city_id` to all city-scoped tables from the start

### Entities
- [ ] Create `organizations`, `people`, `locations`
- [ ] Create `entity_aliases` (polymorphic: entity_type/entity_id) + indexes

### Content & provenance
- [ ] Create `articles` + `article_bodies`
- [ ] Create `article_sources`
- [ ] Add dedupe fields: `canonical_url`, `content_hash`

### Roles & taxonomy
- [ ] Create `role_types` + `roles` (time-scoped)
- [ ] Create `issue_areas` (hierarchy) + `tags`

### Claims (AI outputs as claims)
- [ ] Create `claims` table (subject/predicate/object/value_json + provenance)
- [ ] Add enums/constants for the first 3 claim types:
  - [ ] article_mentions_person
  - [ ] article_mentions_org
  - [ ] article_issue_area

## Milestone 2 — Ingestion pipeline (no AI yet)
- [ ] Create `scrapers` + `scraper_runs`
- [ ] Implement `Ingestion\ScrapeRunner`
- [ ] Implement Fetchers:
  - [ ] RssFetcher
  - [ ] HtmlFetcher
- [ ] Implement `Ingestion\Deduplicator` (URL + hash strategy)
- [ ] Implement `Ingestion\ArticleWriter` (Article + Body + Source)
- [ ] Add a demo scraper config and seed it
- [ ] Add a command: `php artisan scrape:run {scraper}`

## Milestone 3 — Extraction v1 (claims only)
- [ ] Implement `Extraction\Extractor` (start heuristic; AI later)
- [ ] Implement `Extraction\ClaimWriter`
- [ ] Add command: `php artisan extract:article {id}`
- [ ] Ensure extraction never writes “facts” directly (claims only)

## Milestone 4 — Resolution v1 (aliases-first)
- [ ] Implement `Resolution\EntityResolver`:
  - [ ] exact match on slug/name
  - [ ] alias match
  - [ ] optional fuzzy match (pg_trgm later)
- [ ] Add admin-only artisan tools:
  - [ ] `alias:add`
  - [ ] `alias:list`

## Milestone 5 — Search v1 (Scout + Meilisearch)
- [ ] Install Scout + Meilisearch driver
- [ ] Index Articles (title, summary, cleaned_text)
- [ ] Add filters: city_id, published_at
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

## Milestone 7 — Admin UI (only after pipeline works)
- [ ] Scraper management UI
- [ ] Claim review UI (approve/reject)
- [ ] Alias management UI

## Definition of Done (v1)
- [ ] One city seeded
- [ ] 2–3 scrapers reliably ingesting
- [ ] Search returns sane results
- [ ] Ask endpoint answers with citations
- [ ] Claims exist + can be reviewed
