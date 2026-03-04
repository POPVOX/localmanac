# Ingestion Architecture (Current)

Updated: March 2026

## Scope

This document describes implemented ingestion behavior for:

- article ingestion (`scrapers`)
- event ingestion (`event_sources`)
- ingestion-quality controls
- anti-bot/browser fallback behavior

## Article Ingestion

### Scraper Types

Implemented scraper types in `ScrapeRunner`:

- `rss`
- `html`

### HTML Profiles

Implemented HTML profile routing:

- `wichitadocumenters`
- `generic_listing`
- `wichita_archive_pdf_list`

### Core Article Ingestion Path

1. Scheduler or manual command creates `scraper_runs` record.
2. Fetcher produces normalized items.
3. Required fields are validated (`city_id`, `title`, `source.source_url`).
4. Quality guard may reject items before write.
5. Deduplicator resolves create/update target.
6. ArticleWriter persists article/body/source records.
7. Document-like content may dispatch extraction jobs.

## Event Ingestion

Implemented source types include:

- `ics`
- `rss`
- `json` / `json_api` (with profile registry)
- `html` (with profile registry)

Event ingestion writes:

- `event_ingestion_runs`
- `events`
- `event_source_items`

## Quality Guard and Pruning

`ArticleQualityGuard` supports three implemented rejection reasons:

- `blocked_url_path`
- `min_content`
- `profile_title`

Guard behavior is configured in `config/ingestion.php` and includes:

- blocked URL path segment matching
- min word/character floor for non-document items
- profile-title heuristic for short role-card content

Operational command:

- `php artisan articles:prune-low-quality`

Command supports:

- city and scraper targeting
- reason filtering
- limit
- dry-run default
- deletion with `--force`

## Anti-Bot and Playwright Fallback

### Shared Page Fetcher

`PageFetcher` supports `auto`, `http`, and `playwright` renderer modes.

In `auto` mode:

1. HTTP fetch runs first.
2. Extracted text and HTML shell signals are evaluated.
3. Playwright fetch is attempted when content looks JS-shell or insufficient.

### Fetcher-Level Fallbacks

`DocumentersFetcher` and `GenericListingFetcher` both:

- detect challenge pages (anti-bot markers)
- attempt Playwright fallback when needed
- raise explicit anti-bot errors when both paths remain blocked

### Playwright Runtime Controls

`PlaywrightPageFetcher` supports:

- timeout, wait selector, user agent
- storage state path for persistent sessions
- proxy server/user/password/bypass
- refresh-on-blocked and refresh attempt limits
- optional auto-scroll controls for lazy-loaded pages

## Scraper Assistant Integration

Scraper form uses assistant services for draft generation and preview:

- `ScraperAssistantSourceFetcher`
- `ScraperConfigDrafter`
- `ScraperConfigAiRefiner`
- `ScraperConfigPreviewer`

Implemented constraints:

- non-super-admin users cannot persist arbitrary raw config JSON
- non-super-admin users must pass preview validation before save
- super-admin users can edit raw JSON directly

## Scheduling and Commands

Article commands:

- `scrape:run {scraper}`
- `scrape:schedule`

Event commands:

- `calendar:run`
- `calendar:schedule`

Support commands:

- `events:dedupe`
- `db:sync-sequences`

## Known Operational Realities

- Some sources require browser rendering due to anti-bot or JS rendering.
- Some ingestion paths are intentionally best-effort to avoid full-run failure.
- Sequence drift mitigation is built in for specific write-heavy ingestion paths.
