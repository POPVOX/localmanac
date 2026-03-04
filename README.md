# Localmanac

Localmanac is a city-scoped civic information platform. It ingests local articles and calendar events, enriches article content with structured analysis, and exposes evidence-backed answers through dashboard chat and public/demo surfaces.

## Current System (March 2026)

- Article ingestion for `rss` and `html` scrapers, including profile-based HTML fetchers (`generic_listing`, `wichitadocumenters`, `wichita_archive_pdf_list`)
- Calendar ingestion for `ics`, `rss`, `json/json_api`, and `html` event sources
- Queue-driven enrichment pipeline with civic analysis, entity extraction, process timeline projection, and explainer projection
- City-scoped article search on dashboard with Scout-backed retrieval and SQL fallback
- Ask API (`POST /ask`) with citations, plus dashboard chat with streaming and conversation memory for authenticated users
- Super-admin-only admin console for cities, organizations, scrapers, events, event sources, chat sources, and feedback review
- Site feedback capture widget for authenticated users (`site_feedback`)

## Quick Start

1. Install dependencies.

```bash
composer install
npm install
```

2. Configure environment.

```bash
cp .env.example .env
php artisan key:generate
```

3. Run database migrations.

```bash
php artisan migrate
```

4. Start the app.

```bash
composer run dev
```

## Useful Commands

- `php artisan scrape:run {scraper}`
- `php artisan scrape:schedule`
- `php artisan calendar:run`
- `php artisan calendar:schedule`
- `php artisan enrich:article {id}`
- `php artisan enrich:backfill`
- `php artisan chat:ingest-sources`
- `php artisan articles:prune-low-quality --help`
- `php artisan users:super-admin {email}`

## Documentation

See the docs index for current technical documentation:

- [Documentation Index](docs/README.md)

## City Scope

All core records are city-scoped. Wichita is the default configured city in current usage, but city logic is not hard-coded to Wichita.
